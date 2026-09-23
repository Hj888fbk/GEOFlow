<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeGovernance\ObsidianKnowledgeSyncPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

final class PreviewObsidianKnowledgeSyncCommand extends Command
{
    protected $signature = 'geoflow:knowledge:preview-obsidian-sync
        {--vault= : Obsidian knowledge directory}
        {--ledger= : Authoritative fact ledger CSV}
        {--knowledge-base= : Optional GEOFlow knowledge base ID for hash comparison}
        {--out= : Output directory; defaults to private storage}
        {--force : Replace the two preview files if they already exist}';

    protected $description = 'Build a preview-only Obsidian knowledge package and exclusion manifest without changing GEOFlow data';

    public function handle(ObsidianKnowledgeSyncPreviewService $previewService): int
    {
        try {
            $preview = $previewService->preview(
                (string) $this->option('vault'),
                (string) $this->option('ledger'),
                $this->currentKnowledgeBase(),
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $outputDirectory = $this->outputDirectory((string) $this->option('out'), (string) data_get($preview, 'package.sha256'));
        $manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        $packagePath = $outputDirectory.DIRECTORY_SEPARATOR.'knowledge-preview.md';
        if (! $this->option('force') && (File::exists($manifestPath) || File::exists($packagePath))) {
            $this->components->error('Preview files already exist; use --force to replace them.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists($outputDirectory);

        $packageContent = (string) Arr::pull($preview, 'package_content', '');
        try {
            File::put($packagePath, $packageContent);
            File::put($manifestPath, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        } catch (JsonException $exception) {
            $this->components->error('obsidian_preview_manifest_invalid');

            return self::FAILURE;
        }

        $this->components->info('Obsidian sync preview created. No GEOFlow data was changed.');
        $this->line('Manifest: '.$manifestPath);
        $this->line('Package: '.$packagePath);
        $this->line(sprintf(
            'Included: %d | Excluded: %d | Warnings: %d',
            (int) data_get($preview, 'summary.included_count', 0),
            (int) data_get($preview, 'summary.excluded_count', 0),
            count((array) ($preview['warnings'] ?? [])),
        ));

        return self::SUCCESS;
    }

    /** @return array{id: int, name: string, content_hash: string}|null */
    private function currentKnowledgeBase(): ?array
    {
        $knowledgeBaseId = (int) $this->option('knowledge-base');
        if ($knowledgeBaseId <= 0) {
            return null;
        }
        $knowledgeBase = KnowledgeBase::query()->find($knowledgeBaseId);
        if (! $knowledgeBase) {
            throw new RuntimeException('knowledge_base_not_found');
        }

        return [
            'id' => (int) $knowledgeBase->id,
            'name' => (string) $knowledgeBase->name,
            'content_hash' => hash('sha256', (string) $knowledgeBase->content),
        ];
    }

    private function outputDirectory(string $path, string $packageHash): string
    {
        $path = trim($path);
        if ($path === '') {
            return storage_path('app/private/knowledge-sync-previews/'.now()->format('Ymd-His').'-'.substr($packageHash, 0, 12));
        }

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1 ? rtrim($path, '\\/') : base_path(rtrim($path, '\\/'));
    }
}
