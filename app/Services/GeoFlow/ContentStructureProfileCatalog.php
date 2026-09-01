<?php

namespace App\Services\GeoFlow;

final class ContentStructureProfileCatalog
{
    public const PROFILE_PRODUCT = 'product_page';

    public const PROFILE_MANUFACTURER = 'manufacturer_company';

    public const PROFILE_SELECTION = 'procurement_selection';

    public const PROFILE_TECHNICAL = 'technical_qa';

    public const PROFILE_CASE = 'application_case';

    public const PROFILE_FAQ = 'faq_refresh';

    public const PROFILE_B2B = 'b2b_product';

    /** @return list<string> */
    public function profileKeys(): array
    {
        return array_keys($this->profiles());
    }

    /** @return list<string> */
    public function pageRoleKeys(): array
    {
        return array_keys($this->pageRoles());
    }

    /** @return array<string,string> */
    public function pageRoles(): array
    {
        return [
            'product_page' => '产品页',
            'manufacturer_company' => '厂家/公司介绍页',
            'procurement_selection' => '采购选型文章',
            'technical_qa' => '技术问题解答',
            'application_case' => '应用案例',
            'faq_refresh' => 'FAQ 与旧页面更新',
            'b2b_product' => 'B2B 商品页',
        ];
    }

    /**
     * @return array<string,array{label:string,objective:string,sections:list<string>,required_blocks:list<string>}>
     */
    public function profiles(): array
    {
        $profiles = [
            self::PROFILE_PRODUCT => [
                'label' => '产品页',
                'objective' => '帮助采购人员判断产品是否适合当前工况，并准备有效询价。',
                'sections' => [
                    '结论先行：产品是什么、适合谁',
                    '适用工况与明确不适用条件',
                    '选型必须输入的介质、口径、压力、温度、连接与位移信息',
                    '参数表：只填写知识库已核验字段，缺失项标记待确认',
                    '结构、材质与连接说明',
                    '安装、维护与常见失效风险',
                    '生产、检测和资质证据边界',
                    '常见问题与结构化询价清单',
                ],
                'required_blocks' => ['适用条件', '不适用条件', '参数表', '询价输入', 'FAQ'],
            ],
            self::PROFILE_MANUFACTURER => [
                'label' => '厂家/公司介绍页',
                'objective' => '让供应商筛选人员核对企业主体、能力范围和可验证证据。',
                'sections' => [
                    '企业主体与品牌定位',
                    '主要产品与服务边界',
                    '生产流程、设备与人员能力：仅写已确认事实',
                    '检测流程、报告与可追溯记录',
                    '资质证书：编号、机构、范围、有效期与反查入口',
                    '案例与交付经验：区分已核验和待确认',
                    '非标、报价和技术沟通所需输入',
                    '联系与下一步动作',
                ],
                'required_blocks' => ['主体', '产品范围', '生产检测证据', '资质边界', '询价动作'],
            ],
            self::PROFILE_SELECTION => [
                'label' => '采购选型文章',
                'objective' => '把模糊需求转化为可执行的选型判断与询价输入。',
                'sections' => [
                    '采购场景与先给结论',
                    '选型前必须确认的工况输入',
                    '比较维度与判断顺序',
                    '不同条件下的选择路径',
                    '不适用、误选和安装风险',
                    '需要厂家确认的非标项目',
                    '询价清单、FAQ 与相关产品入口',
                ],
                'required_blocks' => ['工况输入', '选择路径', '风险', '询价清单', 'FAQ'],
            ],
            self::PROFILE_TECHNICAL => [
                'label' => '技术问题解答',
                'objective' => '先回答具体问题，再说明适用范围、排查步骤和证据限制。',
                'sections' => [
                    '直接回答与适用范围',
                    '原理、可能原因或影响因素',
                    '分步骤检查与处理方法',
                    '不能仅凭当前信息判断的边界',
                    '需要补充的数据、照片、图纸或报告',
                    '安全提示、FAQ 与下一步动作',
                ],
                'required_blocks' => ['直接答案', '适用范围', '排查步骤', '判断边界', '所需资料'],
            ],
            self::PROFILE_CASE => [
                'label' => '应用案例',
                'objective' => '说明一个已授权案例的输入、选择依据、执行与验证，不夸大结果。',
                'sections' => [
                    '项目背景与公开权限',
                    '已确认的工况输入',
                    '选型依据与方案边界',
                    '实施、安装或交付过程',
                    '检测、验收与结果：仅写已确认记录',
                    '限制条件、经验和可复用检查项',
                    '同类需求询价所需资料',
                ],
                'required_blocks' => ['背景', '输入', '选型依据', '验证', '限制条件'],
            ],
            self::PROFILE_FAQ => [
                'label' => 'FAQ 与旧页面更新',
                'objective' => '补齐用户高频问题、证据来源和相关页面路径，而非重复造新页面。',
                'sections' => [
                    '本次更新解决的问题与适用范围',
                    '简短、可独立引用的问答卡片',
                    '参数、资质和公司事实的来源边界',
                    '待补证据与不能回答的问题',
                    '相关产品、案例、技术文章和询价入口',
                ],
                'required_blocks' => ['更新说明', 'FAQ', '来源边界', '待补证据', '内链'],
            ],
            self::PROFILE_B2B => [
                'label' => 'B2B 商品页',
                'objective' => '按平台字段快速说明产品、选型条件和报价输入，避免无证据参数堆砌。',
                'sections' => [
                    '商品标题与核心用途',
                    '适用场景和不适用条件',
                    '型号、参数和可选项：仅填已核验内容',
                    '材质、连接、非标与定制说明',
                    '生产检测和资质边界',
                    '包装、交期、库存和价格：无实时依据则要求询价确认',
                    '询价输入与平台合规说明',
                ],
                'required_blocks' => ['商品用途', '参数表', '适用边界', '可选项', '询价输入'],
            ],
        ];

        $overrides = config('hengjia-content.native_flow.structure_profiles', []);

        return is_array($overrides) ? array_replace_recursive($profiles, $overrides) : $profiles;
    }

    public function suggestKey(?string $pageRole): string
    {
        $pageRole = trim((string) $pageRole);

        return array_key_exists($pageRole, $this->profiles()) ? $pageRole : self::PROFILE_SELECTION;
    }

    /**
     * @param  array<string,mixed>  $brief
     * @return array{key:string,label:string,objective:string,sections:list<string>,required_blocks:list<string>,instruction:string}
     */
    public function resolve(array $brief): array
    {
        $profiles = $this->profiles();
        $key = trim((string) ($brief['structure_profile'] ?? ''));
        if (! array_key_exists($key, $profiles)) {
            $key = $this->suggestKey((string) ($brief['page_role'] ?? ''));
        }
        $profile = $profiles[$key];
        $sections = array_values(array_filter(array_map('strval', $profile['sections'] ?? [])));
        $requiredBlocks = array_values(array_filter(array_map('strval', $profile['required_blocks'] ?? [])));

        return [
            'key' => $key,
            'label' => (string) ($profile['label'] ?? $key),
            'objective' => (string) ($profile['objective'] ?? ''),
            'sections' => $sections,
            'required_blocks' => $requiredBlocks,
            'instruction' => $this->instruction((string) ($profile['objective'] ?? ''), $sections, $requiredBlocks),
        ];
    }

    /** @param list<string> $sections @param list<string> $requiredBlocks */
    private function instruction(string $objective, array $sections, array $requiredBlocks): string
    {
        $lines = ['页面目标：'.$objective, '建议正文顺序：'];
        foreach ($sections as $index => $section) {
            $lines[] = ($index + 1).'. '.$section;
        }
        if ($requiredBlocks !== []) {
            $lines[] = '必需内容块：'.implode('、', $requiredBlocks).'。';
        }
        $lines[] = '允许根据证据和用户问题调整小标题，但不得删掉必需内容块，也不得用未经核验的参数填满表格。';

        return implode("\n", $lines);
    }
}
