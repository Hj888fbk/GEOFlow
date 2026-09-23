<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class KnowledgeGovernancePreviewCommandsTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'geoflow-preview-commands-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_external_intelligence_command_writes_a_preview_to_an_absolute_windows_compatible_path(): void
    {
        $input = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'competitor.json';
        $output = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'preview.json';
        File::put($input, json_encode(['feature' => '工作压力 2.5 MPa，行业第一。'], JSON_UNESCAPED_UNICODE));

        $this->artisan('geoflow:external-intelligence:preview', [
            '--input' => $input,
            '--format' => 'json',
            '--source-url' => 'https://competitor.example/page?utm_source=test',
            '--out' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);
        $preview = json_decode((string) File::get($output), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('external_research_only', data_get($preview, 'isolation.classification'));
        $this->assertFalse(data_get($preview, 'isolation.auto_import_allowed'));
    }

    public function test_obsidian_command_writes_manifest_and_preview_without_touching_a_knowledge_base(): void
    {
        $vault = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault';
        $ledger = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'ledger.csv';
        $output = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'output';
        File::ensureDirectoryExists($vault);
        File::put($vault.DIRECTORY_SEPARATOR.'公开事实.md', "---\npublishable: true\n---\nHJ-C-001 已核验。\n");
        File::put($ledger, "id,status\nHJ-C-001,verified\n");

        $this->artisan('geoflow:knowledge:preview-obsidian-sync', [
            '--vault' => $vault,
            '--ledger' => $ledger,
            '--out' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output.DIRECTORY_SEPARATOR.'manifest.json');
        $this->assertFileExists($output.DIRECTORY_SEPARATOR.'knowledge-preview.md');
        $manifest = json_decode((string) File::get($output.DIRECTORY_SEPARATOR.'manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('preview_only', $manifest['mode']);
        $this->assertFalse(data_get($manifest, 'package.write_to_geoflow_allowed'));
    }
}
