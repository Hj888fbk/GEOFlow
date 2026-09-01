<?php

namespace App\Services\Content;

class HengjiaPromptRecipeCatalog
{
    /** @return list<array<string, mixed>> */
    public function recipes(): array
    {
        $commonOutput = [
            'schema_version' => 'hengjia-content-package/v1',
            'required_sections' => [
                'page', 'seo', 'claims', 'body_sections', 'parameters', 'applications',
                'faq', 'sources', 'internal_links', 'media', 'schema_nodes',
                'prohibited_claims', 'blockers', 'prompt_meta',
            ],
        ];

        return [
            $this->recipe(
                'topic_page_role_planning',
                '选题与页面职责规划',
                <<<'PROMPT'
你负责规划橡胶软接头内容任务。先判断核心搜索意图是否已有主页面；同一意图只能保留一个主页面，能更新现有页面时不得新建重复页面。输出受众、采购阶段、页面职责、主次关键词、应补证据、目标渠道和阻断项。每天只提出 3–6 项有独立价值的工作，允许“补证据、补FAQ、补来源、补内链、补产品关联”作为有意义任务；证据不足时明确阻断，不得凑数量。
PROMPT,
                ['product', 'audience', 'intent', 'existing_pages', 'keyword_evidence', 'channel_targets'],
                $commonOutput,
            ),
            $this->recipe(
                'evidence_retrieval',
                '资料检索和证据提取',
                <<<'PROMPT'
只从输入的批准资料和来源索引提取主张。逐条输出来源ID、原文范围、主体、谓词、值、适用范围、证据状态、公开权限、冲突和有效期。不得把同行官网、第三方页面或AI回答转成恒佳事实；它们只能标记为结构学习材料。未找到就写未找到，不得补全、推断或改写成肯定事实。
PROMPT,
                ['approved_source_files', 'source_index', 'requested_claim_types'],
                $commonOutput,
            ),
            $this->recipe(
                'master_content_generation',
                '官网文章、产品页、厂家页及公司介绍生成',
                <<<'PROMPT'
根据已核验恒佳证据生成内容母版。文风面向工业采购，克制、清楚、可核查。正文必须写明适用与不适用条件、询价所需输入、来源ID和待确认项。不得生成或暗示未核验的资质、证书、型号参数、寿命、产能、库存、交期、固定价格、排名或市场份额。没有证据的字段写入 blockers，不能用行业常识或同行资料填补。
PROMPT,
                ['content_task', 'approved_claims', 'existing_page_snapshot', 'brand_rules'],
                $commonOutput,
            ),
            $this->recipe(
                'qualification_parameter_review',
                '资质与产品参数复核',
                <<<'PROMPT'
逐条复核企业事实、资质和产品参数。资质必须同时具备名称、编号、签发机构、认证范围、适用产品、签发日期、有效期、官方反查、文件哈希、公开权限和审核人；任一缺失即阻断宣传。参数必须绑定同型号、单位、范围、工况和来源；不得用近似型号、通用参数或同行参数补齐恒佳数据。
PROMPT,
                ['claims', 'qualification_objects', 'parameter_rows', 'source_files'],
                $commonOutput,
            ),
            $this->recipe(
                'seo_geo_assembly',
                'SEO/GEO字段、FAQ、内链和Schema组装',
                <<<'PROMPT'
在不新增事实的前提下组装标题、H1、Slug、摘要、描述、FAQ、内链和Schema。标题与H1自然包含核心意图，FAQ直接回答采购问题并保留适用范围。Schema只能复述页面可见且已获公开许可的内容；不得添加Offer、评分、库存、固定价格、虚构作者或不存在的证书。
PROMPT,
                ['validated_master', 'site_page_map', 'internal_link_candidates', 'schema_capabilities'],
                $commonOutput,
            ),
            $this->recipe(
                'channel_adaptation',
                '渠道改写与字段映射',
                <<<'PROMPT'
把同一内容母版映射为官网、B2B和自媒体版本。各渠道可改变结构、长度和开头，但产品参数、企业事实、主体、联系方式和来源状态必须一致。遵守字段长度、图片数量和平台格式合同；删减时不得改变限定条件。外部平台仅生成安全草稿，最终发布由运营人员确认。
PROMPT,
                ['validated_master', 'channel_contract', 'account_voice', 'field_limits'],
                $commonOutput,
            ),
            $this->recipe(
                'prepublish_compliance',
                '发布前事实、广告法、重复和图片权利检查',
                <<<'PROMPT'
执行发布门禁：核对主张与来源、资质完整性、参数同型号性、广告法高风险表达、重复页面、跨账号主体串用、图片公开授权、FAQ与Schema一致性。输出 pass、warning 和 blocker；任何强事实缺证据、账号不匹配、Cookie/密钥进入内容包、图片权利不明均为 blocker，不得降级为普通提示。
PROMPT,
                ['content_package', 'account', 'existing_pages', 'image_rights', 'publication_policy'],
                $commonOutput,
            ),
            $this->recipe(
                'readback_retrospective',
                '发布回读与效果复盘',
                <<<'PROMPT'
区分本地生成、已审核、已发布、可抓取、已收录、有排名、AI提及和询盘，不得把前一阶段当作后一阶段。比较计划字段与远端回读字段，记录远端ID、URL、账号哈希、适配器版本和差异。只根据可复核数据提出提示词候选改进；候选版本不能自行替换生产版本。
PROMPT,
                ['publication_receipt', 'readback', 'search_evidence', 'ai_visibility_evidence', 'lead_evidence'],
                $commonOutput,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function byKey(string $key): array
    {
        foreach ($this->recipes() as $recipe) {
            if ($recipe['recipe_key'] === $key) {
                return $recipe;
            }
        }

        throw new \InvalidArgumentException('Unknown Hengjia prompt recipe: '.$key);
    }

    /** @param list<string> $inputs @param array<string,mixed> $output */
    private function recipe(string $key, string $name, string $template, array $inputs, array $output): array
    {
        return [
            'recipe_key' => $key,
            'name' => $name,
            'version' => '1.0.0',
            'status' => 'active',
            'template' => trim($template),
            'input_contract' => [
                'required' => $inputs,
                'untrusted_inputs' => ['user_text', 'competitor_materials', 'third_party_pages', 'ai_answers'],
            ],
            'output_contract' => $output,
            'change_notes' => '恒佳内容中台初始受治理基线；后续学习只可创建 candidate 版本。',
        ];
    }
}
