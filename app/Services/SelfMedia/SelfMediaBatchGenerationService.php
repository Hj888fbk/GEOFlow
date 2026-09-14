<?php

namespace App\Services\SelfMedia;

use App\Contracts\SelfMediaContentGenerator;
use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Services\BrowserOperations\PublicationPayloadBuilder;
use App\Services\GeoFlow\ManualPublicationService;
use App\Services\GeoFlow\WorkerAiModelInvocationGateway;
use App\Support\Site\ArticleHtmlPresenter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SelfMediaBatchGenerationService
{
    private const EXECUTION_LEASE_SECONDS = 960;

    public function __construct(
        private SelfMediaContentGenerator $generator,
        private SelfMediaSourceHasher $hasher,
        private ManualPublicationService $publications,
        private PublicationPayloadBuilder $payloadBuilder,
        private SelfMediaFactConstraintGuard $factGuard,
        private WorkerAiModelInvocationGateway $invocationGateway,
        private PortableArticleDocumentService $portableDocuments,
    ) {}

    public function generate(ManualPublicationBatch $batch): ManualPublicationBatch
    {
        $batch = $this->claim($batch);
        if ($batch->status === ManualPublicationBatch::STATUS_INVALIDATED) {
            throw new DomainException('官网母稿已经变化，旧批次已失效。');
        }
        $persona = ManualPublicationPersona::query()
            ->where('is_active', true)
            ->when($batch->persona_id !== null, fn ($query) => $query->whereKey((int) $batch->persona_id))
            ->oldest('id')
            ->first();
        if (! $persona instanceof ManualPublicationPersona) {
            throw new DomainException('批次指定的自媒体发布身份已停用或不存在。');
        }

        $errors = [];
        foreach ((array) $batch->target_platforms as $platform) {
            $platform = (string) $platform;
            if (! $this->leaseIsCurrent($batch)) {
                break;
            }
            $selectedAccountIds = $this->selectedAccountIdsForPlatform($batch, $platform);
            $existing = $batch->publications()->where('platform', $platform);
            if ($selectedAccountIds !== []) {
                $existing->whereIn('account_id', $selectedAccountIds);
            }
            if ($existing->count() === max(1, count($selectedAccountIds))) {
                continue;
            }

            try {
                $this->generator->generateAndPersist(
                    $batch,
                    $platform,
                    function (array $variant, ?array $invocation = null) use ($batch, $persona, $platform): ManualPublication {
                        $this->factGuard->assertVariant($batch, $variant);

                        return $this->persistVariant($batch, $persona, $platform, $variant, $invocation);
                    },
                );
            } catch (Throwable $exception) {
                report($exception);
                $errors[$platform] = $exception->getMessage();
            }
        }

        return $this->finalize($batch, $errors);
    }

    public function recoverExpiredLeases(int $limit = 50): int
    {
        $batchIds = ManualPublicationBatch::query()
            ->where('status', ManualPublicationBatch::STATUS_GENERATING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->pluck('id');
        $recovered = 0;

        foreach ($batchIds as $batchId) {
            DB::transaction(function () use ($batchId, &$recovered): void {
                $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->first();
                if (! $batch instanceof ManualPublicationBatch
                    || (string) $batch->status !== ManualPublicationBatch::STATUS_GENERATING
                    || $batch->lease_expires_at === null
                    || $batch->lease_expires_at->isFuture()) {
                    return;
                }

                $errors = (array) $batch->generation_errors;
                $errors['_batch'] = '生成进程超时，批次已安全释放，可人工重试。';
                $batch->forceFill([
                    'status' => ManualPublicationBatch::STATUS_FAILED,
                    'generation_errors' => $errors,
                    'execution_lease_token' => null,
                    'lease_expires_at' => null,
                    'revision' => (int) $batch->revision + 1,
                ])->save();
                $recovered++;
            }, 3);
        }

        return $recovered;
    }

    private function claim(ManualPublicationBatch $batch): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batch): ManualPublicationBatch {
            $current = ManualPublicationBatch::query()
                ->with(['article', 'creator'])
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($current->status, [ManualPublicationBatch::STATUS_PLANNED, ManualPublicationBatch::STATUS_FAILED], true)
                || $current->invalidated_at !== null) {
                throw new DomainException('只有有效的计划中或生成失败批次可以开始生成。');
            }
            if (! hash_equals((string) $current->source_hash, $this->hasher->hash($current->article))) {
                $at = now();
                $current->forceFill([
                    'status' => ManualPublicationBatch::STATUS_INVALIDATED,
                    'invalidated_at' => $at,
                    'execution_lease_token' => null,
                    'lease_expires_at' => null,
                    'revision' => (int) $current->revision + 1,
                ])->save();
                $current->publications()->whereNull('source_stale_at')->update([
                    'source_stale_at' => $at,
                    'updated_at' => $at,
                ]);

                return $current;
            }

            $current->forceFill([
                'status' => ManualPublicationBatch::STATUS_GENERATING,
                'generation_errors' => null,
                'execution_lease_token' => (string) Str::uuid7(),
                'lease_expires_at' => now()->addSeconds(self::EXECUTION_LEASE_SECONDS),
                'generation_attempt' => (int) $current->generation_attempt + 1,
                'revision' => (int) $current->revision + 1,
            ])->save();

            return $current->refresh()->load(['article', 'creator']);
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $variant
     * @param  array{context?:AiExecutionContext,receipt?:array<string,mixed>}|null  $invocation
     */
    private function persistVariant(
        ManualPublicationBatch $batch,
        ManualPublicationPersona $persona,
        string $platform,
        array $variant,
        ?array $invocation,
    ): ManualPublication {
        return DB::transaction(function () use ($batch, $persona, $platform, $variant, $invocation): ManualPublication {
            $current = ManualPublicationBatch::query()
                ->with(['article', 'creator'])
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertLeaseCurrent($batch, $current);

            if (! hash_equals((string) $current->source_hash, $this->hasher->hash($current->article))) {
                $this->invalidateLockedBatch($current);
                throw new DomainException('官网母稿已经变化，旧批次已失效。');
            }

            $document = is_array($variant['portable_document'] ?? null)
                ? $variant['portable_document']
                : $this->portableDocuments->build(
                    (string) ($variant['title'] ?? ''),
                    (string) ($variant['body_markdown'] ?? ''),
                    array_values((array) $current->media_manifest),
                    $platform,
                );
            $variant['body_markdown'] = (string) ($document['markdown'] ?? '');
            $variant['body_html'] = (string) ($document['html'] ?? '');
            $variant['body_plain'] = (string) ($document['plain_text'] ?? '');
            $variant['document_schema_version'] = (string) ($document['schema_version'] ?? PortableArticleDocumentService::SCHEMA_VERSION);
            $variant['portable_document'] = $document;
            $variant['render_fingerprint'] = (array) ($document['render_fingerprint'] ?? []);
            $variant['content_type'] = (string) ($variant['content_type'] ?? ($platform === ManualPublicationAccount::PLATFORM_DOUYIN ? 'douyin_article' : 'article'));

            $context = $invocation['context'] ?? null;
            $receipt = $invocation['receipt'] ?? null;
            if ($context instanceof AiExecutionContext && is_array($receipt)) {
                $this->invocationGateway->assertReceiptCurrent($context, $receipt);
            }

            $creator = $current->creator;
            if (! $creator instanceof Admin) {
                throw new DomainException('批次创建人已不可用，不能保存平台稿。');
            }
            $accountIds = $this->selectedAccountIdsForPlatform($current, $platform);
            $accounts = ManualPublicationAccount::query()
                ->where('persona_id', $persona->id)
                ->where('platform', $platform)
                ->where('is_active', true)
                ->when($accountIds !== [], fn ($query) => $query->whereIn('id', $accountIds))
                ->oldest('id')
                ->get();
            if ($accountIds !== [] && $accounts->count() !== count($accountIds)) {
                throw new DomainException('所选平台账号已停用或不再属于当前身份。');
            }

            $targets = $accounts->isEmpty() && $accountIds === [] ? [null] : $accounts->all();
            $saved = null;
            foreach ($targets as $account) {
                $existing = $current->publications()
                    ->where('platform', $platform)
                    ->when(
                        $account instanceof ManualPublicationAccount,
                        fn ($query) => $query->where('account_id', $account->id),
                        fn ($query) => $query->whereNull('account_id'),
                    )
                    ->first();
                if ($existing instanceof ManualPublication) {
                    $saved ??= $existing;

                    continue;
                }

                $publication = $this->publications->create([
                    'type' => ManualPublication::TYPE_POST,
                    'article_id' => (int) $current->article_id,
                    'persona_id' => (int) $persona->id,
                    'account_id' => $account?->id,
                    'assigned_admin_id' => $current->created_by_admin_id,
                    'platform' => $platform,
                    'custom_platform' => null,
                    'target_url' => $account?->editor_url,
                    'target_context' => null,
                    'content' => $variant['body_plain'],
                    'status' => ManualPublication::STATUS_DRAFT,
                    'manual_publication_batch_id' => (int) $current->id,
                    'platform_title' => $variant['title'],
                    'platform_summary' => $variant['summary'],
                    'body_markdown' => $variant['body_markdown'],
                    'body_html' => $this->resolveBodyHtml($variant),
                    'document_schema_version' => $variant['document_schema_version'] ?? PortableArticleDocumentService::SCHEMA_VERSION,
                    'portable_document' => $variant['portable_document'] ?? null,
                    'render_fingerprint' => $variant['render_fingerprint'] ?? null,
                    'content_type' => $variant['content_type'] ?? 'article',
                    'tags' => $variant['tags'],
                    'media_manifest' => $current->media_manifest,
                    'source_hash' => (string) $current->source_hash,
                    'source_snapshot' => array_merge((array) $current->source_snapshot, [
                        'formal_url' => (string) $current->source_url,
                    ]),
                ], $creator);

                $publication->forceFill([
                    'publication_payload' => $this->payloadBuilder->build(array_merge($publication->getAttributes(), [
                        'source_snapshot' => $publication->source_snapshot,
                        'identity_snapshot' => $publication->identity_snapshot,
                        'tags' => $publication->tags,
                        'media_manifest' => $publication->media_manifest,
                        'portable_document' => $publication->portable_document,
                        'render_fingerprint' => $publication->render_fingerprint,
                    ])),
                ])->save();
                $saved ??= $publication;
            }

            if (! $saved instanceof ManualPublication) {
                throw new DomainException('没有可用于该平台的账号。');
            }

            return $saved->refresh();
        }, 3);
    }

    /**
     * body_html 为空时从 body_markdown 确定性渲染；body_markdown 也为空则保持 null。
     *
     * @param  array{body_markdown?:string,body_html?:?string}  $variant
     */
    private function resolveBodyHtml(array $variant): ?string
    {
        $html = trim((string) ($variant['body_html'] ?? ''));
        if ($html !== '') {
            return $html;
        }

        $markdown = trim((string) ($variant['body_markdown'] ?? ''));

        return $markdown === '' ? null : ArticleHtmlPresenter::markdownToHtml($markdown);
    }

    /** @param array<string,string> $errors */
    private function finalize(ManualPublicationBatch $batch, array $errors): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batch, $errors): ManualPublicationBatch {
            $current = ManualPublicationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ((string) $current->status !== ManualPublicationBatch::STATUS_GENERATING
                || ! hash_equals(
                    trim((string) $batch->execution_lease_token),
                    trim((string) $current->execution_lease_token),
                )) {
                return $current->refresh()->load('publications');
            }

            $generatedCount = $current->publications()->count();
            $targetAccountIds = array_values(array_unique(array_map('intval', (array) $current->target_account_ids)));
            $targetCount = $targetAccountIds === []
                ? count(array_unique(array_map('strval', (array) $current->target_platforms)))
                : count($targetAccountIds);
            $current->forceFill([
                'status' => $generatedCount === $targetCount && $targetCount > 0
                    ? ManualPublicationBatch::STATUS_PENDING_REVIEW
                    : ManualPublicationBatch::STATUS_FAILED,
                'generation_errors' => $errors === [] ? null : $errors,
                'execution_lease_token' => null,
                'lease_expires_at' => null,
                'revision' => (int) $current->revision + 1,
            ])->save();

            return $current->refresh()->load('publications');
        }, 3);
    }

    /** @return list<int> */
    private function selectedAccountIdsForPlatform(ManualPublicationBatch $batch, string $platform): array
    {
        $accountIds = array_values(array_unique(array_map('intval', (array) $batch->target_account_ids)));
        if ($accountIds === []) {
            return [];
        }

        return ManualPublicationAccount::query()
            ->whereIn('id', $accountIds)
            ->where('platform', $platform)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function leaseIsCurrent(ManualPublicationBatch $batch): bool
    {
        return ManualPublicationBatch::query()
            ->whereKey($batch->id)
            ->where('status', ManualPublicationBatch::STATUS_GENERATING)
            ->where('execution_lease_token', $batch->execution_lease_token)
            ->where('lease_expires_at', '>', now())
            ->exists();
    }

    private function assertLeaseCurrent(
        ManualPublicationBatch $expected,
        ManualPublicationBatch $current,
    ): void {
        $expectedLease = trim((string) $expected->execution_lease_token);
        $currentLease = trim((string) $current->execution_lease_token);
        if ((string) $current->status !== ManualPublicationBatch::STATUS_GENERATING
            || $current->invalidated_at !== null
            || $expectedLease === ''
            || $currentLease === ''
            || ! hash_equals($expectedLease, $currentLease)
            || $current->lease_expires_at === null
            || $current->lease_expires_at->isPast()) {
            throw new DomainException('自媒体批次执行租约已经失效。');
        }
    }

    private function invalidateLockedBatch(ManualPublicationBatch $batch): void
    {
        $at = now();
        $batch->forceFill([
            'status' => ManualPublicationBatch::STATUS_INVALIDATED,
            'invalidated_at' => $at,
            'execution_lease_token' => null,
            'lease_expires_at' => null,
            'revision' => (int) $batch->revision + 1,
        ])->save();
        $batch->publications()->whereNull('source_stale_at')->update([
            'source_stale_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
