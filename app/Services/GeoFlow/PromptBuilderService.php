<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class PromptBuilderService
{
    public function __construct(private readonly ContentStructureProfileCatalog $profiles) {}

    /** @return array<string,string> */
    public function decisionStages(): array
    {
        return [
            'problem_discovery' => '问题确认',
            'requirements' => '需求与工况梳理',
            'selection' => '选型比较',
            'supplier_validation' => '供应商与证据核验',
            'inquiry' => '询价与技术确认',
            'purchase' => '采购决策',
            'installation' => '安装与使用',
            'maintenance' => '维护与故障排查',
        ];
    }

    /** @return array<string,string> */
    public function tones(): array
    {
        return [
            'professional_restrained' => '专业、克制、采购友好',
            'technical_clear' => '技术清晰、先结论后说明',
            'plain_practical' => '通俗、务实、便于执行',
            'company_formal' => '正式企业介绍',
            'b2b_concise' => 'B2B 简洁字段化表达',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function normalizeConfig(array $input): array
    {
        $config = [];
        $limits = [
            'procurement_problem' => 1000,
            'product_line' => 300,
            'target_user' => 500,
            'audience_profile' => 800,
            'decision_stage' => 80,
            'procurement_direction' => 1200,
            'page_role' => 80,
            'desired_action' => 600,
            'tone' => 80,
            'allowed_evidence' => 1600,
            'forbidden_claims' => 1600,
            'target_channels' => 600,
            'accepted_ai_notes' => 1600,
            'lineage_id' => 80,
            'production_status' => 20,
        ];
        foreach ($limits as $field => $limit) {
            $value = $this->scalar($input[$field] ?? null, $field, $limit);
            if ($value !== '') {
                $config[$field] = $value;
            }
        }

        $questions = $this->list($input['buyer_questions'] ?? [], 'buyer_questions', 20, 300);
        if ($questions !== []) {
            $config['buyer_questions'] = $questions;
        }

        if (isset($config['page_role']) && ! in_array($config['page_role'], $this->profiles->pageRoleKeys(), true)) {
            throw new InvalidArgumentException('页面职责不在支持范围内');
        }
        if (isset($config['decision_stage']) && ! array_key_exists($config['decision_stage'], $this->decisionStages())) {
            throw new InvalidArgumentException('采购决策阶段不在支持范围内');
        }
        if (isset($config['tone']) && ! array_key_exists($config['tone'], $this->tones())) {
            throw new InvalidArgumentException('表达语气不在支持范围内');
        }
        if (isset($config['production_status']) && ! in_array($config['production_status'], ['candidate', 'active'], true)) {
            throw new InvalidArgumentException('提示词状态无效');
        }

        return $config;
    }

    /** @param array<string,mixed> $config */
    public function compile(array $config): string
    {
        $config = $this->normalizeConfig($config);
        $profile = $this->profiles->resolve([
            'page_role' => $config['page_role'] ?? '',
            'structure_profile' => $config['page_role'] ?? '',
        ]);
        $questions = $config['buyer_questions'] ?? [];
        $questionText = $questions === []
            ? '未预设；请根据标题与知识库识别真实采购问题，但不得虚构用户反馈。'
            : implode("\n", array_map(static fn (string $question): string => '- '.$question, $questions));
        $stageLabel = $this->decisionStages()[$config['decision_stage'] ?? ''] ?? ($config['decision_stage'] ?? '未指定');
        $toneLabel = $this->tones()[$config['tone'] ?? ''] ?? ($config['tone'] ?? '专业、克制、采购友好');
        $problem = $this->value($config, 'procurement_problem', '围绕标题解决一个明确采购问题');
        $product = $this->value($config, 'product_line', '以任务中的产品信息为准');
        $targetUser = $this->value($config, 'target_user', '工业采购、技术、设备维护或供应商审核人员');
        $audienceProfile = $this->value($config, 'audience_profile', '根据任务上下文判断，不虚构用户数据');
        $direction = $this->value($config, 'procurement_direction', '适用条件、不适用条件、参数边界、生产检测证据、非标与询价输入');
        $desiredAction = $this->value($config, 'desired_action', '整理完整工况与连接信息后进入人工询价或技术确认');
        $channels = $this->value($config, 'target_channels', '以任务选择的发布渠道为准');
        $allowedEvidence = $this->value($config, 'allowed_evidence', '任务选择的已审核知识库与明确允许公开的资料');
        $forbiddenClaims = $this->value($config, 'forbidden_claims', '无来源资质、参数、寿命、产能、库存、交期、价格、排名和市场份额');
        $acceptedNotes = $this->value($config, 'accepted_ai_notes', '无；AI 建议只有经人工确认后才会出现在这里');

        return trim(<<<PROMPT
你是一名面向工业采购决策的中文内容编辑。请基于任务标题、关键词和已审核知识库，生成可核验、可执行、便于搜索与 AI 引用的内容。

【本提示词解决的问题】
{$problem}

【产品或产品线】
{$product}

【目标用户】
{$targetUser}
用户画像：{$audienceProfile}
采购阶段：{$stageLabel}

【常见提问】
{$questionText}

【采购关注方向】
{$direction}

【页面职责与文章结构】
页面职责：{$profile['label']}
{$profile['instruction']}

【希望读者完成的动作】
{$desiredAction}

【表达要求】
语气：{$toneLabel}
目标渠道：{$channels}

【证据规则】
允许使用：{$allowedEvidence}
禁止主张：{$forbiddenClaims}
1. 同行官网、第三方页面和 AI 回答只能学习结构，不能转写为本企业事实。
2. 参数表只填写知识库已核验字段；缺失内容写“待技术确认”，不得用近似型号或行业常见值补齐。
3. 公司、资质、生产、检测和案例结论必须能追到允许公开的来源；证据不足时明确说明边界。
4. 必须写出适用与不适用条件，以及有效询价需要的输入。

【已采纳的 AI 候选建议】
{$acceptedNotes}

【运行时上下文】
标题：{{title}}
关键词：{{keyword}}
参考知识：
{{knowledge}}
{{#if product}}产品上下文：{{product}}{{/if}}
{{#if page_role}}页面职责：{{page_role}}{{/if}}
{{#if audience}}目标受众：{{audience}}{{/if}}
{{#if decision_stage}}采购阶段：{{decision_stage}}{{/if}}
{{#if buyer_questions}}采购问题：\n{{buyer_questions}}{{/if}}
{{#if procurement_direction}}采购关注：{{procurement_direction}}{{/if}}
{{#if desired_action}}目标动作：{{desired_action}}{{/if}}
{{#if author}}作者身份：{{author}}{{/if}}
{{#if structure}}任务结构：\n{{structure}}{{/if}}
{{#if media_context}}可用图片：\n{{media_context}}{{/if}}
{{#if domain_rules}}领域事实规则：\n{{domain_rules}}{{/if}}
PROMPT);
    }

    /** @param array<string,mixed> $config */
    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);
        $role = $this->profiles->pageRoles()[$config['page_role'] ?? ''] ?? '未指定页面职责';
        $product = (string) ($config['product_line'] ?? '未指定产品');
        $audience = (string) ($config['target_user'] ?? '未指定目标用户');

        return $product.' · '.$role.' · '.$audience;
    }

    /** @param array<string,mixed> $config */
    public function suggestionPrompt(array $config): string
    {
        $config = $this->normalizeConfig($config);
        $questions = implode('；', $config['buyer_questions'] ?? []);
        $problem = $this->value($config, 'procurement_problem', '未填写');
        $product = $this->value($config, 'product_line', '未填写');
        $targetUser = $this->value($config, 'target_user', '未填写');
        $audienceProfile = $this->value($config, 'audience_profile', '未填写');
        $decisionStage = $this->value($config, 'decision_stage', '未填写');
        $direction = $this->value($config, 'procurement_direction', '未填写');
        $pageRole = $this->value($config, 'page_role', '未填写');
        $desiredAction = $this->value($config, 'desired_action', '未填写');

        return <<<PROMPT
你只负责审查一个工业内容提示词向导是否遗漏采购问题，不生成文章，不补写任何企业事实或产品参数。

当前输入：
- 要解决的问题：{$problem}
- 产品：{$product}
- 目标用户：{$targetUser}
- 用户画像：{$audienceProfile}
- 决策阶段：{$decisionStage}
- 已列问题：{$questions}
- 采购关注：{$direction}
- 页面职责：{$pageRole}
- 目标动作：{$desiredAction}

请只输出三组简短建议：
1. 可能遗漏的采购问题；
2. 不同用户角色的关注差异；
3. 还应要求用户提供的证据或工况输入。
每条必须是问题或检查项。不要断言参数、资质、排名、产能、价格或企业能力。
PROMPT;
    }

    public function newLineageId(): string
    {
        return (string) Str::uuid();
    }

    private function value(array $config, string $key, string $fallback): string
    {
        $value = trim((string) ($config[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    private function scalar(mixed $value, string $field, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException($field.' 必须是文本');
        }
        $value = trim((string) $value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($field.' 内容过长');
        }

        return $value;
    }

    /** @return list<string> */
    private function list(mixed $value, string $field, int $maxItems, int $maxItemLength): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/[\r\n,，;；]+/u', $value) ?: [];
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException($field.' 必须是文本列表');
        }

        $items = [];
        foreach ($value as $item) {
            $item = trim(is_string($item) || is_numeric($item) ? (string) $item : '');
            if ($item === '') {
                continue;
            }
            if (mb_strlen($item, 'UTF-8') > $maxItemLength) {
                throw new InvalidArgumentException($field.' 单项内容过长');
            }
            $items[$item] = true;
            if (count($items) > $maxItems) {
                throw new InvalidArgumentException($field.' 项目过多');
            }
        }

        return array_keys($items);
    }
}
