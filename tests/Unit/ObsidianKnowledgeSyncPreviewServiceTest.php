<?php

namespace Tests\Unit;

use App\Services\GeoFlow\KnowledgeGovernance\ObsidianKnowledgeSyncPreviewService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ObsidianKnowledgeSyncPreviewServiceTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'geoflow-obsidian-preview-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault'.DIRECTORY_SEPARATOR.'01-事实与品牌');
        File::ensureDirectoryExists($this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault'.DIRECTORY_SEPARATOR.'99-历史归档');
        File::ensureDirectoryExists($this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault'.DIRECTORY_SEPARATOR.'.obsidian');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    #[Test]
    public function it_builds_a_preview_and_excludes_historical_restricted_and_risky_pages(): void
    {
        $vault = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault';
        $ledger = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'事实台账.csv';
        File::put($ledger, "id,status\nHJ-C-001,verified\n");
        File::put($vault.DIRECTORY_SEPARATOR.'01-事实与品牌'.DIRECTORY_SEPARATOR.'公开事实.md', <<<'MD'
---
publishable: true
source_of_truth: true
status: reviewed
---
# 企业事实

HJ-C-001 是已经核验的公开事实。
MD);
        File::put($vault.DIRECTORY_SEPARATOR.'普通页面.md', "# 常见问题\n\n用于人工复核的产品说明。\n");
        File::put($vault.DIRECTORY_SEPARATOR.'内部资料.md', "---\nvisibility: internal\n---\n内部成本资料。\n");
        File::put($vault.DIRECTORY_SEPARATOR.'风险旧稿.md', "# 十大厂家\n\n恒佳居首。\n");
        File::put($vault.DIRECTORY_SEPARATOR.'99-历史归档'.DIRECTORY_SEPARATOR.'旧稿.md', "旧稿不得同步。\n");
        File::put($vault.DIRECTORY_SEPARATOR.'.obsidian'.DIRECTORY_SEPARATOR.'config.md', "系统配置。\n");

        $preview = (new ObsidianKnowledgeSyncPreviewService)->preview($vault, $ledger, [
            'id' => 2,
            'name' => '恒佳企业知识库',
            'content_hash' => str_repeat('0', 64),
        ]);

        $this->assertSame('preview_only', $preview['mode']);
        $this->assertSame(2, data_get($preview, 'summary.included_count'));
        $this->assertSame(4, data_get($preview, 'summary.excluded_count'));
        $this->assertSame(1, data_get($preview, 'summary.claim_id_count'));
        $this->assertTrue(data_get($preview, 'current_knowledge_base.preview_differs'));
        $this->assertContains('obsidian_source_of_truth_marker_conflicts_with_ledger_authority', $preview['warnings']);
        $this->assertContains('manual_review_required_for_unmarked_files', $preview['warnings']);
        $this->assertStringContainsString('HJ-C-001', $preview['package_content']);
        $this->assertStringNotContainsString('恒佳居首', $preview['package_content']);
        $this->assertStringNotContainsString('内部成本', $preview['package_content']);

        $secondPreview = (new ObsidianKnowledgeSyncPreviewService)->preview($vault, $ledger);
        $this->assertSame(data_get($preview, 'package.sha256'), data_get($secondPreview, 'package.sha256'));

        $excluded = collect($preview['excluded_files'])->keyBy('path');
        $this->assertContains('high_risk_ranking_claim', $excluded['风险旧稿.md']['reasons']);
        $this->assertContains('restricted_visibility', $excluded['内部资料.md']['reasons']);
    }

    #[Test]
    public function it_reports_multiple_obsidian_truth_markers_without_promoting_them(): void
    {
        $vault = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'vault';
        $ledger = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'事实台账.csv';
        File::put($ledger, "id,status\nHJ-C-001,verified\n");
        File::put($vault.DIRECTORY_SEPARATOR.'一.md', "---\nsource_of_truth: true\npublishable: true\n---\n事实一。\n");
        File::put($vault.DIRECTORY_SEPARATOR.'二.md', "---\nsource_of_truth: true\npublishable: true\n---\n事实二。\n");

        $preview = (new ObsidianKnowledgeSyncPreviewService)->preview($vault, $ledger);

        $this->assertContains('multiple_obsidian_source_of_truth_markers', $preview['warnings']);
        $this->assertCount(2, data_get($preview, 'vault.source_of_truth_markers'));
        $this->assertFalse(data_get($preview, 'package.write_to_geoflow_allowed'));
    }
}
