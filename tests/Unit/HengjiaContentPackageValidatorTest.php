<?php

namespace Tests\Unit;

use App\Models\ContentMaster;
use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\EvidenceClaim;
use App\Services\Content\HengjiaContentPackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HengjiaContentPackageValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoritative_package_passes_and_model_cannot_change_claim_value(): void
    {
        [$task, $claim] = $this->authoritativeProductEvidence();
        $package = $this->package($task, $claim);

        $validator = app(HengjiaContentPackageValidator::class);
        $valid = $validator->validate($package, $task);

        self::assertTrue($valid['valid'], json_encode($valid, JSON_UNESCAPED_UNICODE));

        $package['claims'][0]['value'] = 'PN2.5';
        $tampered = $validator->validate($package, $task);

        self::assertFalse($tampered['valid']);
        self::assertContains('claim_evidence_mismatch', array_column($tampered['blockers'], 'code'));
    }

    public function test_parameter_and_media_references_require_the_correct_authoritative_claim_types(): void
    {
        [$task, $claim] = $this->authoritativeProductEvidence();
        $package = $this->package($task, $claim);
        $package['media'][] = [
            'source_id' => $claim->source_id,
            'usage' => '产品示意图',
            'alt' => '橡胶软接头产品示意图',
            'rights_status' => '恒佳内部授权',
        ];

        $result = app(HengjiaContentPackageValidator::class)->validate($package, $task);

        self::assertFalse($result['valid']);
        self::assertContains('media_rights_source_missing', array_column($result['blockers'], 'code'));
        self::assertNotContains('parameter_source_type_mismatch', array_column($result['blockers'], 'code'));
    }

    public function test_unlisted_sources_and_prohibited_schema_claims_are_blocked(): void
    {
        [$task, $claim] = $this->authoritativeProductEvidence();
        $package = $this->package($task, $claim);
        $package['sources'] = [];
        $package['schema_nodes'][0]['payload_json'] = json_encode([
            '@type' => 'Product',
            'name' => '橡胶软接头',
            'offers' => ['price' => '99.00', 'priceCurrency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $result = app(HengjiaContentPackageValidator::class)->validate($package, $task);
        $codes = array_column($result['blockers'], 'code');

        self::assertFalse($result['valid']);
        self::assertContains('unlisted_source_reference', $codes);
        self::assertContains('prohibited_schema_claim', $codes);
    }

    public function test_claim_source_ids_must_be_present_in_the_package_source_list(): void
    {
        [$task, $claim] = $this->authoritativeProductEvidence();
        $package = $this->package($task, $claim);
        $package['claims'][0]['source_ids'] = ['HJ-UNLISTED-CLAIM-SOURCE'];

        $result = app(HengjiaContentPackageValidator::class)->validate($package);

        self::assertFalse($result['valid']);
        self::assertContains('unlisted_source_reference', array_column($result['blockers'], 'code'));
        self::assertContains(
            'claims.0.source_ids.0',
            array_column($result['blockers'], 'path'),
        );
    }

    public function test_expired_qualification_and_competitor_self_claim_cannot_be_publishable(): void
    {
        [$task, $claim] = $this->authoritativeProductEvidence();
        $package = $this->package($task, $claim);
        $package['claims'][0] = array_replace($package['claims'][0], [
            'claim_type' => EvidenceClaim::TYPE_QUALIFICATION,
            'evidence_status' => ContentSourceFile::STATUS_COMPETITOR_SELF_CLAIM,
            'qualification' => [
                'qualification_name' => '质量管理体系认证',
                'certificate_number' => 'TEST-001',
                'issuer' => '示例机构',
                'certification_scope' => '示例范围',
                'applicable_products' => '橡胶软接头',
                'issued_at' => now()->subYears(2)->toDateString(),
                'valid_until' => now()->subDay()->toDateString(),
                'official_lookup_url' => 'https://example.test/cert/TEST-001',
                'file_sha256' => str_repeat('a', 64),
                'public_permission' => ContentSourceFile::PERMISSION_PUBLISHABLE,
                'reviewer' => '审核员',
            ],
        ]);

        $result = app(HengjiaContentPackageValidator::class)->validate($package);
        $codes = array_column($result['blockers'], 'code');

        self::assertFalse($result['valid']);
        self::assertContains('unsupported_public_claim', $codes);
        self::assertContains('qualification_expired', $codes);
    }

    /** @return array{ContentTask,EvidenceClaim} */
    private function authoritativeProductEvidence(): array
    {
        $task = ContentTask::query()->create([
            'product_key' => 'rubber-joint',
            'title' => '橡胶软接头选型说明',
            'audience' => '工业采购人员',
            'intent' => '核对工况并询价',
            'page_role' => 'procurement_guide',
            'primary_keyword' => '橡胶软接头选型',
            'secondary_keywords' => ['KXT橡胶软接头'],
            'target_channels' => [['channel_key' => 'wordpress', 'account_id' => null]],
            'priority' => 1,
            'status' => ContentTask::STATUS_IN_REVIEW,
            'review_status' => ContentTask::REVIEW_PENDING,
            'blockers' => [],
            'input_hash' => str_repeat('1', 64),
        ]);
        $source = ContentSourceFile::query()->create([
            'source_root_key' => 'test',
            'relative_path' => 'approved/product-evidence.pdf',
            'path_hash' => hash('sha256', 'approved/product-evidence.pdf'),
            'sha256' => str_repeat('a', 64),
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'evidence_status' => ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            'public_permission' => ContentSourceFile::PERMISSION_PUBLISHABLE,
            'is_approved' => true,
            'last_synced_at' => now(),
        ]);
        $claim = EvidenceClaim::query()->create([
            'claim_id' => (string) Str::uuid(),
            'content_task_id' => $task->getKey(),
            'content_source_file_id' => $source->getKey(),
            'source_id' => 'HJ-PRODUCT-001',
            'claim_type' => EvidenceClaim::TYPE_PRODUCT,
            'subject' => '恒佳 KXT 橡胶软接头',
            'predicate' => '公称压力',
            'claim_value' => 'PN1.6',
            'unit' => 'MPa',
            'scope' => '仅适用于已核验的 KXT 示例型号，实际按图纸确认',
            'evidence_status' => ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            'public_permission' => ContentSourceFile::PERMISSION_PUBLISHABLE,
            'reviewed_at' => now(),
        ]);

        return [$task, $claim];
    }

    /** @return array<string,mixed> */
    private function package(ContentTask $task, EvidenceClaim $claim): array
    {
        return [
            'schema_version' => ContentMaster::SCHEMA_VERSION,
            'page' => [
                'role' => $task->page_role,
                'audience' => $task->audience,
                'intent' => $task->intent,
                'target_channels' => ['wordpress'],
            ],
            'seo' => [
                'title' => '橡胶软接头选型：先核对工况再询价',
                'h1' => '橡胶软接头选型与询价输入',
                'slug' => 'rubber-joint-selection',
                'primary_keyword' => $task->primary_keyword,
                'secondary_keywords' => ['KXT橡胶软接头'],
                'summary' => '按介质、温度、压力、口径、位移和约束条件准备询价资料。',
                'meta_description' => '橡胶软接头选型与询价资料说明，参数以对应型号和图纸核验为准。',
            ],
            'claims' => [[
                'claim_id' => $claim->claim_id,
                'claim_type' => $claim->claim_type,
                'subject' => $claim->subject,
                'predicate' => $claim->predicate,
                'value' => $claim->claim_value,
                'unit' => $claim->unit,
                'text' => '该示例型号公称压力为 PN1.6，实际以确认图纸为准。',
                'evidence_status' => $claim->evidence_status,
                'public_permission' => $claim->public_permission,
                'scope' => $claim->scope,
                'source_ids' => [$claim->source_id],
                'qualification' => [],
            ]],
            'body_sections' => [[
                'heading' => '先确认工况',
                'body' => '询价前应提供介质、温度、压力、口径、连接和位移条件，精确参数按对应型号资料核验。',
                'applicability' => '工业管道橡胶软接头询价准备',
                'source_ids' => [$claim->source_id],
            ]],
            'parameters' => [[
                'name' => '公称压力',
                'value' => 'PN1.6',
                'unit' => 'MPa',
                'applicability' => $claim->scope,
                'evidence_status' => $claim->evidence_status,
                'source_ids' => [$claim->source_id],
            ]],
            'applications' => [[
                'scenario' => '参数已完成逐型号核验的询价',
                'suitability' => 'conditional',
                'conditions' => '仍需核对介质、温度、位移和系统约束。',
                'source_ids' => [$claim->source_id],
            ]],
            'faq' => [[
                'question' => '询价时至少要提供哪些信息？',
                'answer' => '至少提供口径、压力、介质、温度、连接方式和位移要求。',
                'applicability' => '用于形成可复核的询价输入，不替代设计确认。',
                'source_ids' => [$claim->source_id],
            ]],
            'sources' => [[
                'source_id' => $claim->source_id,
                'title' => '恒佳已审核产品资料',
                'evidence_status' => $claim->evidence_status,
                'public_permission' => $claim->public_permission,
            ]],
            'internal_links' => [[
                'anchor' => '查看橡胶软接头产品',
                'target_role' => 'product_series',
                'target_url' => '/products/rubber-joints/',
            ]],
            'media' => [],
            'schema_nodes' => [[
                'type' => 'Article',
                'payload_json' => json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => '橡胶软接头选型与询价输入',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'source_ids' => [$claim->source_id],
            ]],
            'prohibited_claims' => [],
            'blockers' => [],
            'prompt_meta' => [
                'recipe_version' => '1.0.0',
                'input_hash' => str_repeat('2', 64),
                'model' => 'test-model',
                'generated_at' => now()->toAtomString(),
                'pipeline_versions' => ['master_content_generation@1.0.0'],
            ],
        ];
    }
}
