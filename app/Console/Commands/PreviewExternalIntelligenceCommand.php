<?php

namespace App\Console\Commands;

use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\ExternalIntelligence\ExternalIntelligenceSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PreviewExternalIntelligenceCommand extends Command
{
    protected $signature = 'geoflow:external-intelligence:preview
        {--input= : UTF-8 text, Markdown, or JSON source file}
        {--format=auto : auto, json, markdown, or text}
        {--knowledge-base= : Optional knowledge base ID for reviewed-fact comparison}
        {--source-name= : Competitor or source label}
        {--source-url= : Public source URL; query and fragment are removed}
        {--out= : Output JSON path; defaults to private storage}
        {--force : Replace an existing output file}';

    protected $description = 'Create a research-only sanitized competitor intelligence preview without importing external claims';

    public function handle(ExternalIntelligenceSanitizer $sanitizer): int
    {
        $inputPath = $this->absoluteInputPath((string) $this->option('input'));
        if ($inputPath === null || ! File::isFile($inputPath)) {
            $this->components->error('A readable --input file is required.');

            return self::FAILURE;
        }

        $payload = File::get($inputPath);
        try {
            $preview = $sanitizer->preview(
                $payload,
                (string) $this->option('format'),
                $this->verifiedFacts(),
                [
                    'name' => (string) $this->option('source-name'),
                    'url' => (string) $this->option('source-url'),
                ],
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $outputPath = $this->outputPath((string) $this->option('out'), (string) $preview['preview_hash']);
        if (File::exists($outputPath) && ! $this->option('force')) {
            $this->components->error('Output already exists; use --force to replace it.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        $summary = (array) $preview['summary'];
        $this->components->info('External intelligence preview created. No GEOFlow data was changed.');
        $this->line('Output: '.$outputPath);
        $this->line(sprintf(
            'Fragments: %d | Claims: %d | Risky: %d | Verified fact matches: %d',
            (int) ($summary['fragment_count'] ?? 0),
            (int) ($summary['claim_count'] ?? 0),
            (int) ($summary['risky_claim_count'] ?? 0),
            (int) ($summary['verified_fact_match_count'] ?? 0),
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function verifiedFacts(): array
    {
        $knowledgeBaseId = (int) $this->option('knowledge-base');
        if ($knowledgeBaseId <= 0) {
            return [];
        }

        $library = KnowledgeFactLibrary::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->with(['facts' => fn ($query) => $query
                ->enabled()
                ->reviewed()
                ->with(['values' => fn ($values) => $values
                    ->where('review_status', 'reviewed')
                    ->where('conflict_status', 'clear')])])
            ->first();
        if (! $library) {
            throw new RuntimeException('knowledge_fact_library_not_found');
        }

        return $library->facts
            ->flatMap(static fn ($fact) => $fact->values->map(static fn ($value): array => [
                'stable_key' => (string) $fact->stable_key,
                'label' => (string) $fact->label,
                'subject' => (string) $fact->subject,
                'predicate' => (string) $fact->predicate,
                'aliases' => (array) $fact->aliases_json,
                'value_type' => (string) $fact->value_type,
                'canonical_value' => (array) $value->canonical_value_json,
                'canonical_answer' => (string) $value->canonical_answer,
                'comparison_policy' => (array) $value->comparison_policy_json,
            ]))
            ->values()
            ->all();
    }

    private function absoluteInputPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1 ? $path : base_path($path);
    }

    private function outputPath(string $path, string $previewHash): string
    {
        $path = trim($path);
        if ($path === '') {
            return storage_path('app/private/external-intelligence-previews/'.now()->format('Ymd-His').'-'.substr($previewHash, 0, 12).'.json');
        }

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1 ? $path : base_path($path);
    }
}
