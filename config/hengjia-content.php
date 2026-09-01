<?php

use App\Models\EvidenceClaim;

return [
    'schema_version' => 'hengjia-content-package/v1',

    /*
    |--------------------------------------------------------------------------
    | GEOFlow 原生内容链影子开关
    |--------------------------------------------------------------------------
    |
    | 默认关闭。只有同时开启开关并列入 test_task_ids 的任务，Worker 才会
    | 使用 content_brief、结构模板、作者简介与标签选图上下文。这样可以先
    | 对一个恒佳测试任务做盲测，不影响现有任务或生产发布。
    |
    */
    'native_flow' => [
        'enabled' => filter_var(env('HENGJIA_NATIVE_CONTENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'test_task_ids' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('HENGJIA_NATIVE_CONTENT_TEST_TASK_IDS', '')),
        ), static fn (int $id): bool => $id > 0)),
        'generation_rules' => [
            '只有公共记录已核验或恒佳内部确认且允许公开的资料，才可以写成恒佳企业事实。',
            '标准、行业资料和同行页面只能说明适用范围或学习结构，不能转写成恒佳的型号、参数、资质、荣誉或能力。',
            '不得自动生成证书、证书编号、寿命、产能、库存、交期、固定价格、厂家排名或市场份额。',
            '参数、资质、设备、检测、案例或图片证据不足时，明确写待确认或阻断，不使用近似型号和通用宣传补齐。',
            '正文必须同时说明适用条件、不适用条件、证据边界和有效询价所需输入。',
        ],
        'structure_profiles' => [],
    ],

    'source_roots' => [
        'hengjia_geo_library' => [
            'path' => env('HENGJIA_GEO_LIBRARY_PATH', 'D:\\Desktop\\恒佳GEO总库_整理版'),
            'subpaths' => [
                ['path' => '00-主控库', 'evidence_status' => 'internal_confirmed_public', 'public_permission' => 'internal_only', 'approved' => false],
                ['path' => '01-分类素材库', 'evidence_status' => 'internal_confirmed_public', 'public_permission' => 'internal_only', 'approved' => false],
                ['path' => '02-证据库', 'evidence_status' => 'public_record_verified', 'public_permission' => 'internal_only', 'approved' => false],
                ['path' => '03-索引与台账', 'evidence_status' => 'internal_confirmed_public', 'public_permission' => 'internal_only', 'approved' => false],
            ],
        ],
    ],

    'source_sync' => [
        'extensions' => ['md', 'markdown', 'txt', 'csv', 'json', 'yaml', 'yml', 'pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'html', 'htm', 'xml'],
        'excluded_segments' => [
            '.git', '.env', '.codex-tmp', '.seo-cache', '.tmp', 'tmp', 'outputs',
            'node_modules', 'vendor', 'backups', 'backup', 'sessions', 'cookies', 'credentials',
            'secrets', 'tokens', 'private_keys',
        ],
        'excluded_extensions' => ['env', 'key', 'pem', 'p12', 'pfx', 'session', 'cookie', 'token'],
        'excluded_name_patterns' => [
            '/^\.env(?:\..+)?$/i',
            '/^id_(?:rsa|ed25519|ecdsa)(?:\.pub)?$/i',
            '/(?:^|[._-])(?:api[-_]?key|access[-_]?token|refresh[-_]?token|client[-_]?secret|password|credential|session|cookie)(?:[._-]|$)/i',
        ],
        'max_file_bytes' => 25 * 1024 * 1024,
    ],

    'daily_candidate_limit' => [
        'min' => 3,
        'max' => 6,
    ],

    'daily_candidates' => [
        [
            'key' => 'kxt-jgd-selection-loop',
            'title' => '完善 KXT/JGD 橡胶软接头选型决策链',
            'audience' => '项目采购、设计与设备维护人员',
            'intent' => '比较工况并准备有效询价',
            'page_role' => 'product_decision_update',
            'primary_keyword' => '橡胶软接头选型',
            'secondary_keywords' => ['KXT橡胶软接头', 'JGD橡胶接头', '橡胶接头参数'],
            'target_channels' => ['wordpress'],
            'priority' => 1,
            'required_claim_types' => [EvidenceClaim::TYPE_PRODUCT, EvidenceClaim::TYPE_INSPECTION],
        ],
        [
            'key' => 'rubber-joint-inquiry-checklist',
            'title' => '建立橡胶软接头询价输入清单与不适用条件',
            'audience' => '首次询价的工业采购人员',
            'intent' => '准备口径、压力、介质、温度和连接信息',
            'page_role' => 'procurement_guide',
            'primary_keyword' => '橡胶软接头询价',
            'secondary_keywords' => ['橡胶接头怎么询价', '橡胶接头选型参数'],
            'target_channels' => ['wordpress', 'sohu', 'baijiahao'],
            'priority' => 1,
            'required_claim_types' => [],
        ],
        [
            'key' => 'manufacturer-evidence-refresh',
            'title' => '补齐橡胶软接头厂家页企业、生产与检测证据',
            'audience' => '供应商筛选与工厂审核人员',
            'intent' => '核对生产主体和可验证能力',
            'page_role' => 'manufacturer_page_update',
            'primary_keyword' => '橡胶软接头厂家',
            'secondary_keywords' => ['橡胶软接头生产厂家', '橡胶接头供应商'],
            'target_channels' => ['wordpress'],
            'priority' => 1,
            'required_claim_types' => [EvidenceClaim::TYPE_COMPANY, EvidenceClaim::TYPE_PRODUCTION, EvidenceClaim::TYPE_INSPECTION],
        ],
        [
            'key' => 'qualification-evidence-card',
            'title' => '整理可公开资质卡片并核验编号、范围与有效期',
            'audience' => '供应商准入和质量审核人员',
            'intent' => '核验企业资质适用范围',
            'page_role' => 'evidence_improvement',
            'primary_keyword' => '橡胶软接头厂家资质',
            'secondary_keywords' => ['橡胶接头认证', '橡胶接头证书'],
            'target_channels' => ['wordpress'],
            'priority' => 2,
            'required_claim_types' => [EvidenceClaim::TYPE_QUALIFICATION],
        ],
        [
            'key' => 'rubber-joint-faq-source-refresh',
            'title' => '更新橡胶软接头 FAQ、来源和产品内链',
            'audience' => '正在比较型号和工况的采购人员',
            'intent' => '快速回答适用范围并进入产品决策页',
            'page_role' => 'faq_source_internal_link_update',
            'primary_keyword' => '橡胶软接头常见问题',
            'secondary_keywords' => ['橡胶接头FAQ', '橡胶接头适用范围'],
            'target_channels' => ['wordpress'],
            'priority' => 2,
            'required_claim_types' => [],
        ],
        [
            'key' => 'b2b-product-field-readiness',
            'title' => '准备爱采购与1688橡胶软接头商品字段草稿',
            'audience' => 'B2B平台采购人员',
            'intent' => '按工况筛选产品并提交询价',
            'page_role' => 'channel_product_variant',
            'primary_keyword' => '橡胶软接头',
            'secondary_keywords' => ['可曲挠橡胶接头', '法兰橡胶接头'],
            'target_channels' => ['baidu-aicaigou', '1688'],
            'priority' => 3,
            'required_claim_types' => [EvidenceClaim::TYPE_PRODUCT],
        ],
    ],

    'wordpress_bridge' => [
        'namespace' => 'hengjia-content/v1',
        'capabilities_path' => '/capabilities',
        'preview_path' => '/content/preview',
        'content_path' => '/content',
    ],

    'allowed_internal_link_hosts' => [
        'hengjiashebei.com',
        'www.hengjiashebei.com',
    ],
];
