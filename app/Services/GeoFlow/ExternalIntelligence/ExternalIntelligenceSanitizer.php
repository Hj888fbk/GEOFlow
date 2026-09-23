<?php

namespace App\Services\GeoFlow\ExternalIntelligence;

use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use App\Services\GeoFlow\KnowledgeFacts\AtomicFactComparator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class ExternalIntelligenceSanitizer
{
    private const MAX_INPUT_BYTES = 5_242_880;

    private const MAX_SCALARS = 5_000;

    private const MAX_FRAGMENT_CHARACTERS = 1_000;

    public function __construct(
        private readonly ArticleFactCandidateExtractor $claimExtractor,
        private readonly AtomicFactComparator $factComparator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $verifiedFacts
     * @param  array{name?: string, url?: string, observed_at?: string}  $source
     * @return array<string, mixed>
     */
    public function preview(string $payload, string $format, array $verifiedFacts = [], array $source = []): array
    {
        if (strlen($payload) > self::MAX_INPUT_BYTES) {
            throw new RuntimeException('external_intelligence_input_too_large');
        }

        $sourceHash = hash('sha256', $payload);
        $payload = $this->toUtf8($payload);
        $format = $this->resolveFormat($payload, $format);
        $warnings = [];
        $removed = [
            'secret_fields' => 0,
            'prompt_instructions' => 0,
            'contact_values' => 0,
            'tracking_parameters' => 0,
        ];

        $parsed = $format === 'json'
            ? $this->parseJson($payload, $warnings, $removed)
            : $this->parseText($payload, $format, $warnings, $removed);

        $normalizedText = implode("\n", array_values(array_unique(array_filter(
            array_map(static fn (array $fragment): string => (string) ($fragment['text'] ?? ''), $parsed['fragments']),
        ))));
        $claimCandidates = $this->claimExtractor->extract(['content' => $normalizedText], 200);
        $claims = [];
        $matchedFactKeys = [];

        foreach ($claimCandidates as $candidate) {
            $text = trim((string) ($candidate['quote'] ?? ''));
            if ($text === '') {
                continue;
            }

            $matches = $this->compareAgainstVerifiedFacts($text, $verifiedFacts);
            foreach ($matches as $match) {
                if (($match['stable_key'] ?? '') !== '') {
                    $matchedFactKeys[(string) $match['stable_key']] = true;
                }
            }

            $riskTags = $this->riskTags($text);
            $claims[] = [
                'id' => (string) ($candidate['id'] ?? 'F'.(count($claims) + 1)),
                'text' => $text,
                'type' => (string) ($candidate['type'] ?? 'statement'),
                'materiality' => (string) ($candidate['materiality'] ?? 'medium'),
                'risk_tags' => $riskTags,
                'reuse_policy' => $riskTags === [] ? 'research_only' : 'structure_only',
                'verified_fact_matches' => $matches,
            ];
        }

        $unmatchedVerifiedFacts = collect($verifiedFacts)
            ->filter(static fn (array $fact): bool => ! isset($matchedFactKeys[(string) ($fact['stable_key'] ?? '')]))
            ->take(30)
            ->map(static fn (array $fact): array => Arr::only($fact, ['stable_key', 'label', 'canonical_answer']))
            ->values()
            ->all();

        $result = [
            'schema_version' => 'geoflow.external-intelligence-preview/v1',
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'name' => trim((string) ($source['name'] ?? '')),
                'url' => $this->sanitizeSourceUrl((string) ($source['url'] ?? ''), $removed),
                'observed_at' => trim((string) ($source['observed_at'] ?? now()->toDateString())),
                'input_format' => $format,
                'source_hash' => $sourceHash,
            ],
            'isolation' => [
                'classification' => 'external_research_only',
                'auto_import_allowed' => false,
                'may_become_enterprise_fact' => false,
                'manual_evidence_review_required' => true,
                'copy_sentences_to_public_content' => false,
            ],
            'summary' => [
                'fragment_count' => count($parsed['fragments']),
                'claim_count' => count($claims),
                'risky_claim_count' => count(array_filter($claims, static fn (array $claim): bool => $claim['risk_tags'] !== [])),
                'verified_fact_match_count' => count($matchedFactKeys),
                'removed' => $removed,
            ],
            'structure' => [
                'headings' => array_slice(array_values(array_unique($parsed['headings'])), 0, 100),
                'json_paths' => array_slice(array_values(array_unique($parsed['json_paths'])), 0, 500),
            ],
            'claims' => $claims,
            'opportunities' => [
                'structure_patterns' => array_slice(array_values(array_unique(array_merge($parsed['headings'], $parsed['json_paths']))), 0, 100),
                'competitor_claims_not_covered_by_verified_facts' => array_values(array_map(
                    static fn (array $claim): array => Arr::only($claim, ['id', 'text', 'type', 'risk_tags']),
                    array_filter($claims, static fn (array $claim): bool => $claim['verified_fact_matches'] === []),
                )),
                'our_verified_facts_not_observed_in_source' => $unmatchedVerifiedFacts,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];

        $result['preview_hash'] = hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result;
    }

    private function resolveFormat(string $payload, string $format): string
    {
        $format = mb_strtolower(trim($format));
        if (in_array($format, ['json', 'markdown', 'text'], true)) {
            return $format;
        }
        if ($format !== '' && $format !== 'auto') {
            throw new RuntimeException('external_intelligence_format_invalid');
        }

        try {
            json_decode($payload, true, 64, JSON_THROW_ON_ERROR);

            return 'json';
        } catch (JsonException) {
            return preg_match('/^\s{0,3}#{1,6}\s+/m', $payload) === 1 ? 'markdown' : 'text';
        }
    }

    /**
     * @param  list<string>  $warnings
     * @param  array<string, int>  $removed
     * @return array{fragments: list<array{path: string, text: string}>, headings: list<string>, json_paths: list<string>}
     */
    private function parseJson(string $payload, array &$warnings, array &$removed): array
    {
        try {
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('external_intelligence_json_invalid');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('external_intelligence_json_root_invalid');
        }

        $fragments = [];
        $paths = [];
        $this->flattenJson($decoded, '$', $fragments, $paths, $warnings, $removed);

        return [
            'fragments' => $fragments,
            'headings' => [],
            'json_paths' => $paths,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array{path: string, text: string}>  $fragments
     * @param  list<string>  $paths
     * @param  list<string>  $warnings
     * @param  array<string, int>  $removed
     */
    private function flattenJson(array $node, string $path, array &$fragments, array &$paths, array &$warnings, array &$removed): void
    {
        foreach ($node as $key => $value) {
            if (count($paths) >= self::MAX_SCALARS) {
                throw new RuntimeException('external_intelligence_json_too_many_values');
            }

            $keyName = (string) $key;
            $childPath = is_int($key) ? $path.'['.$key.']' : $path.'.'.$keyName;
            if ($this->isSecretKey($keyName)) {
                $removed['secret_fields']++;
                $warnings[] = 'secret_like_fields_removed';

                continue;
            }
            if (is_array($value)) {
                $this->flattenJson($value, $childPath, $fragments, $paths, $warnings, $removed);

                continue;
            }
            if (! is_scalar($value) || is_bool($value)) {
                continue;
            }

            $paths[] = $childPath;
            $text = $this->sanitizeFragment((string) $value, $warnings, $removed);
            if ($text !== '') {
                $fragments[] = ['path' => $childPath, 'text' => $text];
            }
        }
    }

    /**
     * @param  list<string>  $warnings
     * @param  array<string, int>  $removed
     * @return array{fragments: list<array{path: string, text: string}>, headings: list<string>, json_paths: list<string>}
     */
    private function parseText(string $payload, string $format, array &$warnings, array &$removed): array
    {
        $payload = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $payload) ?? $payload;
        $payload = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $payload) ?? $payload;
        $payload = preg_replace('/\A---\s*\R.*?\R---\s*\R/s', '', $payload) ?? $payload;
        $headings = [];
        if ($format === 'markdown') {
            preg_match_all('/^\s{0,3}#{1,6}\s+(.+)$/mu', $payload, $matches);
            foreach ($matches[1] ?? [] as $heading) {
                $cleanedHeading = $this->sanitizeFragment((string) $heading, $warnings, $removed);
                if ($cleanedHeading !== '') {
                    $headings[] = $cleanedHeading;
                }
            }
        }

        $plain = html_entity_decode(strip_tags($payload), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $blocks = preg_split('/(?:\R\s*){2,}|(?<=[。！？!?；;])\s+/u', $plain) ?: [];
        $fragments = [];
        foreach ($blocks as $index => $block) {
            $text = $this->sanitizeFragment((string) $block, $warnings, $removed);
            if ($text !== '') {
                $fragments[] = ['path' => '$.text['.$index.']', 'text' => $text];
            }
        }

        return ['fragments' => $fragments, 'headings' => $headings, 'json_paths' => []];
    }

    /** @param list<string> $warnings @param array<string, int> $removed */
    private function sanitizeFragment(string $value, array &$warnings, array &$removed): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ($this->containsPromptInstruction($value)) {
            $removed['prompt_instructions']++;
            $warnings[] = 'embedded_prompt_instruction_removed';

            return '';
        }

        $contactCount = 0;
        $value = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', '[已移除邮箱]', $value, -1, $emailCount) ?? $value;
        $contactCount += $emailCount;
        $value = preg_replace('/(?<!\d)1[3-9]\d{9}(?!\d)/u', '[已移除手机号]', $value, -1, $phoneCount) ?? $value;
        $contactCount += $phoneCount;
        $value = preg_replace('/(?:微信|V信|VX|WeChat)\s*[:：]?\s*[A-Za-z][A-Za-z0-9_-]{5,19}/iu', '[已移除微信号]', $value, -1, $wechatCount) ?? $value;
        $contactCount += $wechatCount;
        if ($contactCount > 0) {
            $removed['contact_values'] += $contactCount;
            $warnings[] = 'contact_values_removed';
        }

        $value = preg_replace_callback('/https?:\/\/[^\s<>"\']+/iu', function (array $match) use (&$removed): string {
            $url = $match[0];
            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return '[外部链接]';
            }
            if (isset($parts['query']) || isset($parts['fragment'])) {
                $removed['tracking_parameters']++;
            }

            return mb_strtolower((string) $parts['scheme']).'://'.mb_strtolower((string) $parts['host']).($parts['path'] ?? '');
        }, $value) ?? $value;

        return Str::limit(Str::squish($value), self::MAX_FRAGMENT_CHARACTERS, '…');
    }

    /** @param list<array<string, mixed>> $verifiedFacts @return list<array<string, string>> */
    private function compareAgainstVerifiedFacts(string $claimText, array $verifiedFacts): array
    {
        $matches = [];
        $normalizedClaim = $this->normalizeForMatch($claimText);
        foreach ($verifiedFacts as $fact) {
            $terms = collect([
                $fact['label'] ?? null,
                $fact['subject'] ?? null,
                $fact['predicate'] ?? null,
            ])->merge((array) ($fact['aliases'] ?? []))
                ->filter(static fn (mixed $term): bool => is_string($term) && mb_strlen(trim($term)) >= 2)
                ->map(fn (string $term): string => $this->normalizeForMatch($term));
            if (! $terms->contains(static fn (string $term): bool => $term !== '' && str_contains($normalizedClaim, $term))) {
                continue;
            }

            $standard = [
                'type' => (string) ($fact['value_type'] ?? 'text'),
                'answer' => (string) ($fact['canonical_answer'] ?? ''),
                'value' => (string) data_get($fact, 'canonical_value.value', ''),
                'unit' => (string) data_get($fact, 'canonical_value.unit', ''),
                'tolerance' => (float) data_get($fact, 'comparison_policy.tolerance', 0),
            ];
            $numeric = $this->numericClaim($claimText);
            $comparison = $this->factComparator->compare(
                $numeric === null
                    ? ['type' => $standard['type'], 'text' => $claimText]
                    : ['type' => $standard['type'], ...$numeric, 'text' => $claimText],
                $standard,
            );
            $matches[] = [
                'stable_key' => (string) ($fact['stable_key'] ?? ''),
                'label' => (string) ($fact['label'] ?? ''),
                'result' => (string) $comparison['result'],
                'decision' => (string) $comparison['decision'],
            ];
        }

        return $matches;
    }

    /** @return array{value: string, unit: string}|null */
    private function numericClaim(string $text): ?array
    {
        if (preg_match('/(?<![A-Za-z0-9])(-?\d+(?:\.\d+)?)\s*(%|％|MPa|kPa|Pa|bar|℃|°C|mm|cm|m³\/h|m3\/h|L\/s|Hz|kW|rpm|万|亿|千)?/iu', $text, $matches) !== 1) {
            return null;
        }

        return [
            'value' => (string) $matches[1],
            'unit' => str_replace('％', '%', (string) ($matches[2] ?? '')),
        ];
    }

    /** @return list<string> */
    private function riskTags(string $text): array
    {
        $patterns = [
            'absolute_or_ranking_claim' => '/(?:TOP\s*\d+|第\s*[一二三四五六七八九十\d]+|唯一|首家|领先|最好|最高|最大|十大厂家|居首|国家级)/iu',
            'guarantee_claim' => '/(?:保证|确保|承诺|零风险|百分百|100%|绝对)/u',
            'certification_claim' => '/(?:ISO\s*\d+|CE\s*认证|资质|认证证书)/iu',
            'numeric_claim' => '/\d/u',
            'external_contact_or_link' => '/(?:已移除邮箱|已移除手机号|已移除微信号|https?:\/\/)/u',
        ];
        $tags = [];
        foreach ($patterns as $tag => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function isSecretKey(string $key): bool
    {
        return preg_match('/(?:password|passwd|secret|token|cookie|authorization|credential|api[_-]?key|private[_-]?key)/iu', $key) === 1;
    }

    private function containsPromptInstruction(string $value): bool
    {
        return preg_match('/(?:ignore\s+(?:all\s+)?previous\s+instructions|system\s+prompt|developer\s+message|你是(?:一个|一名)?(?:AI|助手)|忽略(?:以上|之前|所有)指令|执行以下命令)/iu', $value) === 1;
    }

    /** @param array<string, int> $removed */
    private function sanitizeSourceUrl(string $url, array &$removed): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ! isset($parts['host'])) {
            throw new RuntimeException('external_intelligence_source_url_invalid');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            $removed['tracking_parameters']++;
        }

        return mb_strtolower((string) $parts['scheme']).'://'.mb_strtolower((string) $parts['host']).($parts['path'] ?? '');
    }

    private function normalizeForMatch(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $value));
    }

    private function toUtf8(string $payload): string
    {
        $encoding = mb_detect_encoding($payload, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if ($encoding === false || mb_strtoupper($encoding) === 'UTF-8') {
            return $payload;
        }

        $converted = @mb_convert_encoding($payload, 'UTF-8', $encoding);

        return $converted === false ? $payload : $converted;
    }
}
