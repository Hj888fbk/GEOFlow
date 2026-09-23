<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ObsidianKnowledgeSyncPreviewService
{
    private const MAX_FILE_BYTES = 2_097_152;

    private const MAX_TOTAL_BYTES = 20_971_520;

    /**
     * @param  array{id?: int, content_hash?: string, name?: string}|null  $currentKnowledgeBase
     * @return array<string, mixed>
     */
    public function preview(string $vaultPath, string $ledgerPath, ?array $currentKnowledgeBase = null): array
    {
        $vaultPath = realpath($vaultPath) ?: '';
        $ledgerPath = realpath($ledgerPath) ?: '';
        if ($vaultPath === '' || ! is_dir($vaultPath)) {
            throw new RuntimeException('obsidian_vault_not_found');
        }
        if ($ledgerPath === '' || ! is_file($ledgerPath)) {
            throw new RuntimeException('fact_ledger_not_found');
        }

        $included = [];
        $excluded = [];
        $sourceOfTruthFiles = [];
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vaultPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || mb_strtolower($file->getExtension()) !== 'md' || str_starts_with($file->getFilename(), '~$')) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($vaultPath) + 1));
            $pathReason = $this->excludedPathReason($relativePath);
            if ($pathReason !== null) {
                $excluded[] = ['path' => $relativePath, 'reasons' => [$pathReason]];

                continue;
            }
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                $excluded[] = ['path' => $relativePath, 'reasons' => ['file_too_large']];

                continue;
            }

            $totalBytes += $file->getSize();
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('obsidian_vault_preview_too_large');
            }

            $content = file_get_contents($file->getPathname());
            if (! is_string($content)) {
                $excluded[] = ['path' => $relativePath, 'reasons' => ['file_unreadable']];

                continue;
            }
            $content = $this->toUtf8($content);
            [$frontmatter, $body] = $this->parseFrontmatter($content);
            $reasons = $this->exclusionReasons($frontmatter, $body);
            if ($this->isTruthy($frontmatter['source_of_truth'] ?? null)) {
                $sourceOfTruthFiles[] = $relativePath;
            }
            if ($reasons !== []) {
                $excluded[] = ['path' => $relativePath, 'reasons' => $reasons];

                continue;
            }

            $warnings = [];
            if (! $this->isTruthy($frontmatter['publishable'] ?? null)) {
                $warnings[] = 'publishable_marker_missing';
            }
            if ($this->isTruthy($frontmatter['source_of_truth'] ?? null)) {
                $warnings[] = 'source_of_truth_marker_must_be_removed';
            }

            preg_match_all('/\bHJ-[A-Z]{1,8}-\d{2,5}\b/u', $body, $claimMatches);
            $claimIds = array_values(array_unique($claimMatches[0] ?? []));
            $normalizedBody = $this->normalizeBody($body);
            $included[] = [
                'path' => $relativePath,
                'sha256' => hash('sha256', $normalizedBody),
                'bytes' => strlen($normalizedBody),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
                'claim_ids' => $claimIds,
                'warnings' => $warnings,
                'package_content' => $normalizedBody,
            ];
        }

        usort($included, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        usort($excluded, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $packageSections = array_map(
            static fn (array $file): string => "<!-- source: {$file['path']} -->\n\n# {$file['path']}\n\n{$file['package_content']}",
            $included,
        );
        $packageContent = implode("\n\n---\n\n", $packageSections);
        foreach ($included as &$includedFile) {
            unset($includedFile['package_content']);
        }
        unset($includedFile);
        $packageHash = hash('sha256', $packageContent);
        $warnings = [];
        if ($sourceOfTruthFiles !== []) {
            $warnings[] = count($sourceOfTruthFiles) > 1
                ? 'multiple_obsidian_source_of_truth_markers'
                : 'obsidian_source_of_truth_marker_conflicts_with_ledger_authority';
        }
        if (array_filter($included, static fn (array $file): bool => in_array('publishable_marker_missing', $file['warnings'], true)) !== []) {
            $warnings[] = 'manual_review_required_for_unmarked_files';
        }

        return [
            'schema_version' => 'geoflow.obsidian-sync-preview/v1',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'preview_only',
            'authority' => [
                'type' => 'fact_ledger_csv',
                'path' => $ledgerPath,
                'sha256' => hash_file('sha256', $ledgerPath),
                'modified_at' => date(DATE_ATOM, filemtime($ledgerPath) ?: 0),
            ],
            'vault' => [
                'path' => $vaultPath,
                'source_of_truth_markers' => $sourceOfTruthFiles,
            ],
            'summary' => [
                'included_count' => count($included),
                'excluded_count' => count($excluded),
                'claim_id_count' => count(array_unique(array_merge(...array_map(
                    static fn (array $file): array => $file['claim_ids'],
                    $included === [] ? [['claim_ids' => []]] : $included,
                )))),
                'package_bytes' => strlen($packageContent),
            ],
            'package' => [
                'sha256' => $packageHash,
                'write_to_geoflow_allowed' => false,
                'requires_manual_confirmation' => true,
            ],
            'current_knowledge_base' => $currentKnowledgeBase === null ? null : [
                ...$currentKnowledgeBase,
                'preview_differs' => ! hash_equals((string) ($currentKnowledgeBase['content_hash'] ?? ''), $packageHash),
            ],
            'included_files' => $included,
            'excluded_files' => $excluded,
            'warnings' => $warnings,
            'package_content' => $packageContent,
        ];
    }

    private function excludedPathReason(string $relativePath): ?string
    {
        $segments = array_map('mb_strtolower', explode('/', $relativePath));
        foreach ($segments as $segment) {
            if (in_array($segment, ['.obsidian', '.git', '.trash', '99-历史归档', 'archive', 'archives', '_archive'], true)) {
                return 'historical_or_system_path';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $frontmatter @return list<string> */
    private function exclusionReasons(array $frontmatter, string $body): array
    {
        $reasons = [];
        if (array_key_exists('publishable', $frontmatter) && ! $this->isTruthy($frontmatter['publishable'])) {
            $reasons[] = 'publishable_false';
        }
        $status = mb_strtolower(trim((string) ($frontmatter['status'] ?? '')));
        if (in_array($status, ['hold', 'superseded', 'archived', 'paused', 'draft', '暂缓发布', '已归档', '已废弃'], true)) {
            $reasons[] = 'status_not_publishable';
        }
        $visibility = mb_strtolower(trim((string) ($frontmatter['visibility'] ?? $frontmatter['access'] ?? '')));
        if (in_array($visibility, ['internal', 'private', 'restricted', '内部', '私有', '受限'], true)) {
            $reasons[] = 'restricted_visibility';
        }

        $opening = mb_substr($body, 0, 1_500);
        if (preg_match('/(?:暂缓发布|禁止发布|仅供内部|不得对外)/u', $opening) === 1) {
            $reasons[] = 'body_marks_non_publishable';
        }
        if (preg_match('/(?:十大厂家|TOP\s*1|恒佳居首|全国第一|行业第一|唯一厂家)/iu', $body) === 1) {
            $reasons[] = 'high_risk_ranking_claim';
        }
        if (preg_match('/(?:已通过|拥有|获得|具备).{0,20}(?:ISO\s*\d+|CE\s*认证)/iu', $body) === 1) {
            $reasons[] = 'unverified_or_expired_certification_claim';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function parseFrontmatter(string $content): array
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) !== 1) {
            return [[], $content];
        }

        $frontmatter = [];
        foreach (preg_split('/\R/u', (string) $matches[1]) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*?)\s*$/u', $line, $parts) !== 1) {
                continue;
            }
            $frontmatter[mb_strtolower($parts[1])] = trim($parts[2], " \t\n\r\0\x0B\"'");
        }

        return [$frontmatter, substr($content, strlen($matches[0]))];
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', '是'], true);
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body) ?? $body;
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $body) ?? $body;
        $body = preg_replace('/\n{3,}/u', "\n\n", $body) ?? $body;

        return Str::of($body)->trim()->toString();
    }

    private function toUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if ($encoding === false || mb_strtoupper($encoding) === 'UTF-8') {
            return $content;
        }

        $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);

        return $converted === false ? $content : $converted;
    }
}
