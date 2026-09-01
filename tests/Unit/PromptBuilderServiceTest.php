<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ContentStructureProfileCatalog;
use App\Services\GeoFlow\PromptBuilderService;
use InvalidArgumentException;
use Tests\TestCase;

class PromptBuilderServiceTest extends TestCase
{
    public function test_every_page_role_compiles_its_required_procurement_structure_and_fact_rules(): void
    {
        $builder = app(PromptBuilderService::class);
        $profiles = app(ContentStructureProfileCatalog::class);

        foreach ($profiles->pageRoles() as $role => $label) {
            $compiled = $builder->compile($this->config(['page_role' => $role]));
            $profile = $profiles->resolve(['page_role' => $role]);

            self::assertStringContainsString('页面职责：'.$label, $compiled);
            foreach ($profile['required_blocks'] as $requiredBlock) {
                self::assertStringContainsString($requiredBlock, $compiled, $role);
            }
            self::assertStringContainsString('同行官网、第三方页面和 AI 回答只能学习结构', $compiled);
            self::assertStringContainsString('缺失内容写“待技术确认”', $compiled);
            self::assertStringContainsString('{{knowledge}}', $compiled);
            self::assertStringContainsString('{{#if media_context}}', $compiled);
            self::assertStringContainsString('{{#if domain_rules}}', $compiled);
        }
    }

    public function test_normalization_accepts_text_lists_deduplicates_items_and_keeps_human_adopted_notes(): void
    {
        $normalized = app(PromptBuilderService::class)->normalizeConfig($this->config([
            'buyer_questions' => "口径怎么选？\n口径怎么选？；需要哪些工况？",
            'accepted_ai_notes' => '补充法兰标准与现场位移方向的确认问题。',
        ]));

        self::assertSame(['口径怎么选？', '需要哪些工况？'], $normalized['buyer_questions']);
        self::assertSame('补充法兰标准与现场位移方向的确认问题。', $normalized['accepted_ai_notes']);
    }

    public function test_ai_suggestion_prompt_can_only_request_questions_role_differences_and_missing_evidence(): void
    {
        $prompt = app(PromptBuilderService::class)->suggestionPrompt($this->config());

        self::assertStringContainsString('不生成文章', $prompt);
        self::assertStringContainsString('可能遗漏的采购问题', $prompt);
        self::assertStringContainsString('不同用户角色的关注差异', $prompt);
        self::assertStringContainsString('还应要求用户提供的证据或工况输入', $prompt);
        self::assertStringContainsString('不要断言参数、资质、排名、产能、价格或企业能力', $prompt);
    }

    public function test_unknown_page_role_is_rejected_instead_of_silently_selecting_a_structure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('页面职责不在支持范围内');

        app(PromptBuilderService::class)->normalizeConfig($this->config(['page_role' => 'invented_role']));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'procurement_problem' => '如何为给定介质、温度、压力和位移要求选择橡胶软接头？',
            'product_line' => 'KXT/JGD 橡胶软接头',
            'target_user' => '工业项目采购与设备技术人员',
            'audience_profile' => '需要核对供应商证据并形成可执行询价清单',
            'decision_stage' => 'selection',
            'buyer_questions' => ['口径怎么选？', '需要提供哪些工况？'],
            'procurement_direction' => '适用边界、连接、位移、检测、非标和询价输入',
            'page_role' => 'procurement_selection',
            'desired_action' => '提交介质、温度、压力、口径、连接和位移信息进入技术确认',
            'tone' => 'professional_restrained',
            'allowed_evidence' => '已审核且允许公开的恒佳知识库资料',
            'forbidden_claims' => '未核验资质、参数、寿命、产能、库存、交期、价格和排名',
            'target_channels' => '恒佳官网、B2B 与自媒体',
        ], $overrides);
    }
}
