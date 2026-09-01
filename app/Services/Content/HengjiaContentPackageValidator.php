<?php

namespace App\Services\Content;

use App\Models\ContentMaster;
use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\EvidenceClaim;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HengjiaContentPackageValidator
{
    private const REQUIRED_TOP_LEVEL = [
        'schema_version', 'page', 'seo', 'claims', 'body_sections', 'parameters',
        'applications', 'faq', 'sources', 'internal_links', 'media', 'schema_nodes',
        'prohibited_claims', 'blockers', 'prompt_meta',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @return array{valid:bool,errors:list<array<string,string>>,warnings:list<array<string,string>>,blockers:list<array<string,string>>}
     */
    public function validate(array $package, ?ContentTask $task = null): array
    {
        $errors = [];
        $warnings = [];
        $blockers = [];

        foreach (self::REQUIRED_TOP_LEVEL as $field) {
            if (! array_key_exists($field, $package)) {
                $errors[] = $this->issue('missing_field', $field, '缺少内容包字段。');
            }
        }

        if (($package['schema_version'] ?? null) !== ContentMaster::SCHEMA_VERSION) {
            $errors[] = $this->issue('invalid_schema_version', 'schema_version', '内容包版本必须为 hengjia-content-package/v1。');
        }

        $page = $this->arrayValue($package, 'page');
        foreach (['role', 'audience', 'intent', 'target_channels'] as $field) {
            if ($this->blank($page[$field] ?? null)) {
                $errors[] = $this->issue('missing_page_field', 'page.'.$field, '页面职责字段不能为空。');
            }
        }

        $seo = $this->arrayValue($package, 'seo');
        foreach (['title', 'h1', 'slug', 'primary_keyword', 'summary', 'meta_description'] as $field) {
            if ($this->blank($seo[$field] ?? null)) {
                $errors[] = $this->issue('missing_seo_field', 'seo.'.$field, 'SEO/GEO字段不能为空。');
            }
        }
        if (! $this->blank($seo['slug'] ?? null) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', (string) $seo['slug']) !== 1) {
            $errors[] = $this->issue('invalid_slug', 'seo.slug', 'Slug 只能使用小写字母、数字和连字符。');
        }

        $claims = is_array($package['claims'] ?? null) ? array_values($package['claims']) : [];
        if ($claims === []) {
            $warnings[] = $this->issue('no_publishable_claims', 'claims', '内容包没有可发布主张，只能作为待补证据草稿。');
        }

        foreach ($claims as $index => $claim) {
            if (! is_array($claim)) {
                $errors[] = $this->issue('invalid_claim', 'claims.'.$index, '主张必须是对象。');

                continue;
            }

            $path = 'claims.'.$index;
            foreach (['claim_id', 'claim_type', 'subject', 'predicate', 'value', 'text', 'evidence_status', 'public_permission', 'scope', 'source_ids'] as $field) {
                if ($this->blank($claim[$field] ?? null)) {
                    $errors[] = $this->issue('missing_claim_field', $path.'.'.$field, '主张字段不能为空。');
                }
            }
            if (! array_key_exists('unit', $claim)) {
                $errors[] = $this->issue('missing_claim_field', $path.'.unit', '主张必须显式提供单位；无单位时使用空字符串。');
            }

            $status = (string) ($claim['evidence_status'] ?? '');
            if (! in_array($status, ContentSourceFile::EVIDENCE_STATUSES, true)) {
                $errors[] = $this->issue('invalid_evidence_status', $path.'.evidence_status', '证据状态不受支持。');
            }

            $permission = (string) ($claim['public_permission'] ?? '');
            $isStrongFact = in_array($status, [
                ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            ], true);
            if ($permission === ContentSourceFile::PERMISSION_PUBLISHABLE && ! $isStrongFact) {
                $blockers[] = $this->issue(
                    'unsupported_public_claim',
                    $path,
                    '同行自述、第三方主张、AI观察、冲突或未找到信息不得作为恒佳公开事实。',
                );
            }
            if ($isStrongFact && $permission === ContentSourceFile::PERMISSION_PUBLISHABLE
                && (! is_array($claim['source_ids'] ?? null) || $claim['source_ids'] === [])) {
                $blockers[] = $this->issue('missing_claim_source', $path.'.source_ids', '可发布事实必须绑定来源ID。');
            }

            if (($claim['claim_type'] ?? '') === EvidenceClaim::TYPE_QUALIFICATION) {
                $this->validateQualification($claim, $path, $blockers);
            }
        }

        $this->validateParameters($package, $errors, $blockers);
        $this->validateSections($package, $errors, $warnings);
        $this->validateApplications($package, $errors);
        $this->validateSources($package, $errors, $blockers);
        $this->validateMedia($package, $errors);
        $this->validateSchemaNodes($package, $errors, $blockers);
        $this->validateInternalLinks($package, $errors, $blockers);
        $this->validatePromptMeta($package, $errors);
        $this->scanHighRiskLanguage($package, $claims, $blockers);

        foreach ((array) ($package['blockers'] ?? []) as $index => $blocker) {
            if (is_string($blocker) && trim($blocker) !== '') {
                $blockers[] = $this->issue('model_reported_blocker', 'blockers.'.$index, trim($blocker));
            } elseif (is_array($blocker) && trim((string) ($blocker['message'] ?? '')) !== '') {
                $blockers[] = $this->issue(
                    (string) ($blocker['code'] ?? 'model_reported_blocker'),
                    'blockers.'.$index,
                    (string) $blocker['message'],
                );
            }
        }

        if ($task instanceof ContentTask) {
            if ((string) ($page['role'] ?? '') !== (string) $task->page_role) {
                $blockers[] = $this->issue('page_role_mismatch', 'page.role', '生成结果与任务页面职责不一致。');
            }
            if ((string) ($seo['primary_keyword'] ?? '') !== (string) $task->primary_keyword) {
                $warnings[] = $this->issue('primary_keyword_changed', 'seo.primary_keyword', '生成结果改变了任务核心关键词，需人工复核。');
            }
            if ($this->channelKeys((array) ($page['target_channels'] ?? [])) !== $this->channelKeys((array) $task->target_channels)) {
                $blockers[] = $this->issue('target_channels_mismatch', 'page.target_channels', '生成结果改变了任务目标渠道。');
            }
            $this->validateAuthoritativeEvidence($package, $task, $blockers);
        }

        return [
            'valid' => $errors === [] && $blockers === [],
            'errors' => $this->uniqueIssues($errors),
            'warnings' => $this->uniqueIssues($warnings),
            'blockers' => $this->uniqueIssues($blockers),
        ];
    }

    /** @param array<string,mixed> $package */
    public function hash(array $package): string
    {
        $normalized = $this->sortRecursively($package);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $claim @param list<array<string,string>> $blockers */
    private function validateQualification(array $claim, string $path, array &$blockers): void
    {
        $qualification = is_array($claim['qualification'] ?? null) ? $claim['qualification'] : [];
        foreach ([
            'qualification_name', 'certificate_number', 'issuer', 'certification_scope',
            'applicable_products', 'issued_at', 'valid_until', 'official_lookup_url',
            'file_sha256', 'public_permission', 'reviewer',
        ] as $field) {
            if ($this->blank($qualification[$field] ?? null)) {
                $blockers[] = $this->issue('incomplete_qualification', $path.'.qualification.'.$field, '资质字段不完整，禁止宣传。');
            }
        }

        $sha = (string) ($qualification['file_sha256'] ?? '');
        if ($sha !== '' && preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
            $blockers[] = $this->issue('invalid_qualification_hash', $path.'.qualification.file_sha256', '资质文件哈希格式无效。');
        }
        $lookup = trim((string) ($qualification['official_lookup_url'] ?? ''));
        if ($lookup !== '' && (filter_var($lookup, FILTER_VALIDATE_URL) === false || parse_url($lookup, PHP_URL_SCHEME) !== 'https')) {
            $blockers[] = $this->issue('invalid_qualification_lookup', $path.'.qualification.official_lookup_url', '资质官方反查必须使用有效 HTTPS 地址。');
        }
        $validUntil = trim((string) ($qualification['valid_until'] ?? ''));
        $validUntilTimestamp = $validUntil === '' ? false : strtotime($validUntil.' 23:59:59');
        if ($validUntil !== '' && ($validUntilTimestamp === false || $validUntilTimestamp < now()->startOfDay()->getTimestamp())) {
            $blockers[] = $this->issue('qualification_expired', $path.'.qualification.valid_until', '资质已过期或有效期格式无效，禁止宣传。');
        }
        if (($qualification['public_permission'] ?? null) !== ContentSourceFile::PERMISSION_PUBLISHABLE) {
            $blockers[] = $this->issue('qualification_not_publishable', $path.'.qualification.public_permission', '资质对象未获得公开权限。');
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @param list<array<string,string>> $blockers */
    private function validateParameters(array $package, array &$errors, array &$blockers): void
    {
        $rows = is_array($package['parameters'] ?? null) ? array_values($package['parameters']) : [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->issue('invalid_parameter', 'parameters.'.$index, '参数行必须是对象。');

                continue;
            }

            $path = 'parameters.'.$index;
            foreach (['name', 'value', 'applicability', 'evidence_status', 'source_ids'] as $field) {
                if (! array_key_exists($field, $row) || $this->blank($row[$field])) {
                    $errors[] = $this->issue('missing_parameter_field', $path.'.'.$field, '参数字段不能为空。');
                }
            }
            if (! array_key_exists('unit', $row)) {
                $errors[] = $this->issue('missing_parameter_field', $path.'.unit', '参数必须显式提供单位；无单位时使用空字符串。');
            }

            $value = trim((string) ($row['value'] ?? ''));
            $status = (string) ($row['evidence_status'] ?? '');
            if (! in_array($status, ContentSourceFile::EVIDENCE_STATUSES, true)) {
                $errors[] = $this->issue('invalid_evidence_status', $path.'.evidence_status', '参数证据状态不受支持。');
            }
            $looksPrecise = preg_match('/\d/u', $value) === 1 && ! Str::contains($value, ['待确认', '按图纸', '询价']);
            if ($looksPrecise && ! in_array($status, [
                ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            ], true)) {
                $blockers[] = $this->issue('unsupported_parameter', $path, '精确参数缺少恒佳同型号已核验证据。');
            }
            if ($looksPrecise && (! is_array($row['source_ids'] ?? null) || $row['source_ids'] === [])) {
                $blockers[] = $this->issue('missing_parameter_source', $path.'.source_ids', '精确参数必须绑定同型号来源ID。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @param list<array<string,string>> $warnings */
    private function validateSections(array $package, array &$errors, array &$warnings): void
    {
        $sections = is_array($package['body_sections'] ?? null) ? array_values($package['body_sections']) : [];
        if ($sections === []) {
            $errors[] = $this->issue('empty_body', 'body_sections', '正文结构不能为空。');
        }
        foreach ($sections as $index => $section) {
            if (! is_array($section)
                || $this->blank($section['heading'] ?? null)
                || $this->blank($section['body'] ?? null)
                || ! array_key_exists('source_ids', $section)
                || $this->blank($section['source_ids'] ?? null)
                || $this->blank($section['applicability'] ?? null)) {
                $errors[] = $this->issue('invalid_body_section', 'body_sections.'.$index, '正文段落必须包含标题、正文、来源ID和适用范围。');
            }
        }

        $faq = is_array($package['faq'] ?? null) ? array_values($package['faq']) : [];
        if ($faq === []) {
            $warnings[] = $this->issue('missing_faq', 'faq', '建议补充可直接回答采购问题的FAQ。');
        }
        foreach ($faq as $index => $item) {
            if (! is_array($item)
                || $this->blank($item['question'] ?? null)
                || $this->blank($item['answer'] ?? null)
                || $this->blank($item['applicability'] ?? null)
                || $this->blank($item['source_ids'] ?? null)) {
                $errors[] = $this->issue('invalid_faq', 'faq.'.$index, 'FAQ必须包含问题、回答和适用范围。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors */
    private function validateApplications(array $package, array &$errors): void
    {
        $applications = is_array($package['applications'] ?? null) ? array_values($package['applications']) : [];
        foreach ($applications as $index => $application) {
            if (! is_array($application)
                || $this->blank($application['scenario'] ?? null)
                || ! in_array((string) ($application['suitability'] ?? ''), ['suitable', 'conditional', 'not_suitable'], true)
                || $this->blank($application['conditions'] ?? null)
                || $this->blank($application['source_ids'] ?? null)) {
                $errors[] = $this->issue(
                    'invalid_application',
                    'applications.'.$index,
                    '应用场景必须包含场景、适用判断、条件和来源ID。',
                );
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @param list<array<string,string>> $blockers */
    private function validateSchemaNodes(array $package, array &$errors, array &$blockers): void
    {
        $nodes = is_array($package['schema_nodes'] ?? null) ? array_values($package['schema_nodes']) : [];
        foreach ($nodes as $index => $node) {
            $path = 'schema_nodes.'.$index;
            if (! is_array($node)
                || $this->blank($node['type'] ?? null)
                || $this->blank($node['payload_json'] ?? null)
                || $this->blank($node['source_ids'] ?? null)) {
                $errors[] = $this->issue('invalid_schema_node', $path, 'Schema节点必须包含类型、JSON载荷和来源ID。');

                continue;
            }
            try {
                $payload = json_decode((string) $node['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors[] = $this->issue('invalid_schema_json', $path.'.payload_json', 'Schema载荷不是有效JSON。');

                continue;
            }
            if (! is_array($payload)) {
                $errors[] = $this->issue('invalid_schema_json', $path.'.payload_json', 'Schema载荷必须是JSON对象或数组。');

                continue;
            }
            $type = strtolower(trim((string) $node['type']));
            $forbiddenKeys = ['offers', 'aggregaterating', 'review', 'price', 'pricecurrency', 'availability', 'inventorylevel'];
            if (in_array($type, ['offer', 'aggregaterating', 'review'], true)
                || array_intersect($forbiddenKeys, $this->jsonKeys($payload)) !== []) {
                $blockers[] = $this->issue(
                    'prohibited_schema_claim',
                    $path,
                    'Schema包含价格、库存、评分或评价等当前禁止自动生成的主张。',
                );
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @param list<array<string,string>> $blockers */
    private function validateInternalLinks(array $package, array &$errors, array &$blockers): void
    {
        $allowedHosts = array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            (array) config('hengjia-content.allowed_internal_link_hosts', []),
        );
        foreach (array_values((array) ($package['internal_links'] ?? [])) as $index => $link) {
            $path = 'internal_links.'.$index;
            if (! is_array($link)
                || $this->blank($link['anchor'] ?? null)
                || $this->blank($link['target_role'] ?? null)
                || $this->blank($link['target_url'] ?? null)) {
                $errors[] = $this->issue('invalid_internal_link', $path, '内链必须包含锚文本、页面职责和目标URL。');

                continue;
            }
            $url = trim((string) $link['target_url']);
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($host, $allowedHosts, true)) {
                $blockers[] = $this->issue('external_or_unsafe_internal_link', $path.'.target_url', '内链目标不是获准的恒佳站内地址。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $blockers */
    private function validateAuthoritativeEvidence(array $package, ContentTask $task, array &$blockers): void
    {
        $authoritative = EvidenceClaim::query()
            ->with('sourceFile:id,sha256,evidence_status,public_permission,is_approved')
            ->where(function ($query) use ($task): void {
                $query->whereNull('content_task_id')->orWhere('content_task_id', $task->getKey());
            })
            ->whereIn('evidence_status', [
                ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            ])
            ->where('public_permission', ContentSourceFile::PERMISSION_PUBLISHABLE)
            ->whereNotNull('reviewed_at')
            ->whereNotNull('source_id')
            ->where('source_id', '!=', '')
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->whereHas('sourceFile', fn ($query) => $query->approvedForPublication())
            ->get()
            ->filter(function (EvidenceClaim $claim): bool {
                if (! $claim->isPublishableFact() || ! $claim->hasCompleteQualificationPayload()) {
                    return false;
                }
                if ($claim->claim_type !== EvidenceClaim::TYPE_QUALIFICATION) {
                    return true;
                }

                return hash_equals(
                    (string) ($claim->sourceFile?->sha256 ?? ''),
                    (string) data_get($claim->structured_payload, 'file_sha256', ''),
                );
            })
            ->values();
        $byClaimId = $authoritative->keyBy(fn (EvidenceClaim $claim): string => (string) $claim->claim_id);
        $bySourceId = $authoritative->groupBy(fn (EvidenceClaim $claim): string => (string) $claim->source_id);

        foreach (array_values((array) ($package['claims'] ?? [])) as $index => $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $path = 'claims.'.$index;
            $claimId = trim((string) ($claim['claim_id'] ?? ''));
            $expected = $byClaimId->get($claimId);
            if (! $expected instanceof EvidenceClaim) {
                $blockers[] = $this->issue('unknown_or_unapproved_claim', $path.'.claim_id', '主张ID不在当前任务获准证据集合中。');

                continue;
            }
            $fieldMap = [
                'claim_type' => $expected->claim_type,
                'subject' => $expected->subject,
                'predicate' => $expected->predicate,
                'value' => $expected->claim_value,
                'unit' => $expected->unit ?? '',
                'scope' => $expected->scope,
                'evidence_status' => $expected->evidence_status,
                'public_permission' => $expected->public_permission,
            ];
            foreach ($fieldMap as $field => $expectedValue) {
                if ($this->normalizeEvidenceValue($claim[$field] ?? '') !== $this->normalizeEvidenceValue($expectedValue)) {
                    $blockers[] = $this->issue('claim_evidence_mismatch', $path.'.'.$field, '模型主张与数据库权威证据不一致。');
                }
            }
            $actualSourceIds = $this->sourceIds((array) ($claim['source_ids'] ?? []));
            $expectedSourceIds = $this->sourceIds([(string) $expected->source_id]);
            if ($actualSourceIds !== $expectedSourceIds) {
                $blockers[] = $this->issue('claim_source_mismatch', $path.'.source_ids', '模型主张改变了权威来源ID。');
            }
            if ($expected->claim_type === EvidenceClaim::TYPE_QUALIFICATION) {
                $actualQualification = (array) ($claim['qualification'] ?? []);
                $expectedQualification = (array) $expected->structured_payload;
                foreach (array_keys($expectedQualification) as $field) {
                    if ($this->normalizeEvidenceValue($actualQualification[$field] ?? '')
                        !== $this->normalizeEvidenceValue($expectedQualification[$field] ?? '')) {
                        $blockers[] = $this->issue('qualification_evidence_mismatch', $path.'.qualification.'.$field, '资质对象与已审核记录不一致。');
                    }
                }
            }
        }

        foreach ($this->sourceReferences($package) as $reference) {
            $sourceId = $reference['source_id'];
            $candidates = $bySourceId->get($sourceId, collect());
            if ($sourceId === '' || $candidates->isEmpty()) {
                $blockers[] = $this->issue('unknown_or_unapproved_source', $reference['path'], '内容引用了未获准或不属于当前任务的来源ID。');

                continue;
            }
            if ($reference['kind'] === 'parameter'
                && ! $candidates->contains(fn (EvidenceClaim $claim): bool => $claim->claim_type === EvidenceClaim::TYPE_PRODUCT)) {
                $blockers[] = $this->issue('parameter_source_type_mismatch', $reference['path'], '参数来源没有对应的已审核产品证据主张。');
            }
            if ($reference['kind'] === 'media'
                && ! $candidates->contains(fn (EvidenceClaim $claim): bool => $claim->claim_type === EvidenceClaim::TYPE_IMAGE_RIGHTS)) {
                $blockers[] = $this->issue('media_rights_source_missing', $reference['path'], '媒体资源没有对应的已审核图片授权证据。');
            }
        }

        foreach (array_values((array) ($package['sources'] ?? [])) as $index => $source) {
            if (! is_array($source)) {
                continue;
            }
            $candidates = $bySourceId->get(trim((string) ($source['source_id'] ?? '')), collect());
            if ($candidates->isEmpty()) {
                continue;
            }
            if (! $candidates->contains(fn (EvidenceClaim $claim): bool => $claim->evidence_status === ($source['evidence_status'] ?? null)
                && $claim->public_permission === ($source['public_permission'] ?? null))) {
                $blockers[] = $this->issue('source_metadata_mismatch', 'sources.'.$index, '来源状态或公开权限与权威记录不一致。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors @param list<array<string,string>> $blockers */
    private function validateSources(array $package, array &$errors, array &$blockers): void
    {
        $sources = is_array($package['sources'] ?? null) ? array_values($package['sources']) : [];
        $listedSourceIds = [];
        foreach ($sources as $index => $source) {
            if (! is_array($source)
                || $this->blank($source['source_id'] ?? null)
                || $this->blank($source['title'] ?? null)
                || $this->blank($source['evidence_status'] ?? null)
                || ! array_key_exists('public_permission', $source)) {
                $errors[] = $this->issue('invalid_source', 'sources.'.$index, '来源必须包含来源ID、标题、证据状态和公开权限。');
            } elseif (! in_array((string) $source['evidence_status'], ContentSourceFile::EVIDENCE_STATUSES, true)) {
                $errors[] = $this->issue('invalid_evidence_status', 'sources.'.$index.'.evidence_status', '来源证据状态不受支持。');
            }
            if (is_array($source) && ! $this->blank($source['source_id'] ?? null)) {
                $listedSourceIds[] = trim((string) $source['source_id']);
            }
        }

        $listedSourceIds = $this->sourceIds($listedSourceIds);
        foreach ($this->sourceReferences($package) as $reference) {
            if ($reference['source_id'] !== '' && ! in_array($reference['source_id'], $listedSourceIds, true)) {
                $blockers[] = $this->issue('unlisted_source_reference', $reference['path'], '内容引用的来源ID未登记在来源清单中。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors */
    private function validateMedia(array $package, array &$errors): void
    {
        foreach (array_values((array) ($package['media'] ?? [])) as $index => $media) {
            if (! is_array($media)
                || $this->blank($media['source_id'] ?? null)
                || $this->blank($media['usage'] ?? null)
                || $this->blank($media['alt'] ?? null)
                || $this->blank($media['rights_status'] ?? null)) {
                $errors[] = $this->issue('invalid_media', 'media.'.$index, '媒体必须包含来源ID、用途、替代文本和权利状态。');
            }
        }
    }

    /** @param array<string,mixed> $package @param list<array<string,string>> $errors */
    private function validatePromptMeta(array $package, array &$errors): void
    {
        $meta = $this->arrayValue($package, 'prompt_meta');
        foreach (['recipe_version', 'input_hash', 'model', 'generated_at'] as $field) {
            if ($this->blank($meta[$field] ?? null)) {
                $errors[] = $this->issue('missing_prompt_meta', 'prompt_meta.'.$field, '生成追溯字段不能为空。');
            }
        }
        $hash = (string) ($meta['input_hash'] ?? '');
        if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            $errors[] = $this->issue('invalid_input_hash', 'prompt_meta.input_hash', '输入哈希格式无效。');
        }
    }

    /** @param array<string,mixed> $package @param list<mixed> $claims @param list<array<string,string>> $blockers */
    private function scanHighRiskLanguage(array $package, array $claims, array &$blockers): void
    {
        $text = implode("\n", Arr::flatten([
            Arr::get($package, 'seo.title', ''),
            Arr::get($package, 'seo.h1', ''),
            Arr::get($package, 'seo.summary', ''),
            Arr::get($package, 'seo.meta_description', ''),
            collect((array) ($package['body_sections'] ?? []))->pluck('body')->all(),
            collect((array) ($package['faq'] ?? []))->pluck('answer')->all(),
        ]));

        $highRiskTerms = ['国家级', '行业第一', '市场第一', '最佳', '顶级', '100%', '永久', '绝对', '最低价', '现货充足', '固定交期'];
        $found = collect($highRiskTerms)->filter(fn (string $term): bool => str_contains($text, $term))->values()->all();
        if ($found !== []) {
            $blockers[] = $this->issue('advertising_law_risk', 'content', '检测到高风险绝对化或无条件宣传：'.implode('、', $found));
        }

        $requiresClaim = [
            EvidenceClaim::TYPE_QUALIFICATION => ['认证', '资质', '证书'],
            EvidenceClaim::TYPE_PRODUCTION => ['产能', '日产', '月产'],
            EvidenceClaim::TYPE_PRODUCT => ['寿命', '库存', '交期', '价格'],
        ];
        foreach ($requiresClaim as $type => $terms) {
            $mentioned = collect($terms)->contains(fn (string $term): bool => str_contains($text, $term));
            if (! $mentioned) {
                continue;
            }

            $supported = collect($claims)->contains(function (mixed $claim) use ($type): bool {
                return is_array($claim)
                    && ($claim['claim_type'] ?? '') === $type
                    && in_array(($claim['evidence_status'] ?? ''), [
                        ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                        ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
                    ], true)
                    && ($claim['public_permission'] ?? '') === ContentSourceFile::PERMISSION_PUBLISHABLE;
            });
            if (! $supported) {
                $blockers[] = $this->issue('unsupported_strong_fact', 'content', '正文包含需强证据支撑的表达，但没有对应可发布主张：'.implode('、', $terms));
            }
        }
    }

    /** @return array<string,mixed> */
    private function arrayValue(array $input, string $key): array
    {
        return is_array($input[$key] ?? null) ? $input[$key] : [];
    }

    private function blank(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }

    /** @param list<mixed> $channels @return list<string> */
    private function channelKeys(array $channels): array
    {
        $keys = [];
        foreach ($channels as $channel) {
            $key = is_array($channel)
                ? trim((string) ($channel['channel_key'] ?? ''))
                : trim((string) $channel);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param array<string|int,mixed> $payload @return list<string> */
    private function jsonKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = strtolower(trim($key));
            }
            if (is_array($value)) {
                $keys = array_merge($keys, $this->jsonKeys($value));
            }
        }

        return array_values(array_unique($keys));
    }

    private function normalizeEvidenceValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_array($value)) {
            return json_encode(
                $this->sortRecursively($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''))));
    }

    /** @param list<mixed> $ids @return list<string> */
    private function sourceIds(array $ids): array
    {
        $normalized = [];
        foreach (Arr::flatten($ids) as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $normalized[] = $id;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param array<string,mixed> $package @return list<array{source_id:string,path:string,kind:string}> */
    private function sourceReferences(array $package): array
    {
        $references = [];
        $collections = [
            'claims' => 'claim',
            'body_sections' => 'body',
            'parameters' => 'parameter',
            'applications' => 'application',
            'faq' => 'faq',
            'schema_nodes' => 'schema',
        ];
        foreach ($collections as $field => $kind) {
            foreach (array_values((array) ($package[$field] ?? [])) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($this->sourceIds((array) ($item['source_ids'] ?? [])) as $sourceIndex => $sourceId) {
                    $references[] = [
                        'source_id' => $sourceId,
                        'path' => $field.'.'.$index.'.source_ids.'.$sourceIndex,
                        'kind' => $kind,
                    ];
                }
            }
        }
        foreach (array_values((array) ($package['media'] ?? [])) as $index => $media) {
            if (! is_array($media)) {
                continue;
            }
            $references[] = [
                'source_id' => trim((string) ($media['source_id'] ?? '')),
                'path' => 'media.'.$index.'.source_id',
                'kind' => 'media',
            ];
        }

        return $references;
    }

    /** @return array{code:string,path:string,message:string} */
    private function issue(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }

    /** @param list<array<string,string>> $issues @return list<array<string,string>> */
    private function uniqueIssues(array $issues): array
    {
        return collect($issues)->unique(fn (array $issue): string => implode('|', $issue))->values()->all();
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}
