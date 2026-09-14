<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Exceptions\ManualPublicationConflictException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Models\ManualPublicationTransition;
use App\Services\BrowserOperations\PublicationPayloadBuilder;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManualPublicationService
{
    public function __construct(
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ManualPublicationDuplicateDetector $duplicateDetector,
        private readonly PublicationPayloadBuilder $publicationPayloadBuilder,
        private readonly ArticlePublicationQualityGate $publicationQualityGate,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, Admin $creator): ManualPublication
    {
        return DB::transaction(function () use ($data, $creator): ManualPublication {
            $prepared = $this->prepare($data);
            $prepared['created_by_admin_id'] = $creator->getKey();
            $initialStatus = (string) ($data['status'] ?? ManualPublication::STATUS_DRAFT);
            if (! in_array($initialStatus, [ManualPublication::STATUS_DRAFT, ManualPublication::STATUS_READY], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }
            $prepared['status'] = $initialStatus;
            $prepared['status_changed_at'] = now();
            $prepared['revision'] = 1;
            $this->ensureReadyRequirements($prepared);
            if ($initialStatus === ManualPublication::STATUS_READY) {
                $prepared['publication_payload'] = $this->publicationPayloadBuilder->build($prepared);
            }
            $prepared['duplicate_warning_count'] = $this->duplicateDetector->find($prepared)->count();

            $publication = ManualPublication::query()->create($prepared);
            $this->recordTransition($publication, null, $initialStatus, $creator);

            return $publication->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ManualPublication $manualPublication, array $data, int $expectedRevision): ManualPublication
    {
        if (! in_array((string) $manualPublication->status, [ManualPublication::STATUS_DRAFT, ManualPublication::STATUS_READY], true)) {
            throw new DomainException((string) __('admin.manual_publications.error.claimed_immutable'));
        }

        $prepared = $this->prepare($data, $manualPublication);
        $this->ensureReadyRequirements($prepared + ['status' => (string) $manualPublication->status]);
        $prepared['duplicate_warning_count'] = $this->duplicateDetector
            ->find($prepared, (int) $manualPublication->getKey())
            ->count();
        if ($manualPublication->status === ManualPublication::STATUS_READY) {
            $prepared['publication_payload'] = $this->publicationPayloadBuilder->build($prepared);
        }
        $prepared['updated_at'] = now();
        $prepared['revision'] = $expectedRevision + 1;
        $casted = (new ManualPublication)->forceFill($prepared);
        $databaseUpdates = Arr::only($casted->getAttributes(), array_keys($prepared));

        $updated = ManualPublication::query()
            ->whereKey($manualPublication->getKey())
            ->where('revision', $expectedRevision)
            ->where('status', '!=', ManualPublication::STATUS_COMPLETED)
            ->update($databaseUpdates);

        if ($updated !== 1) {
            throw new ManualPublicationConflictException;
        }

        return $manualPublication->refresh();
    }

    public function transition(
        ManualPublication $manualPublication,
        string $targetStatus,
        int $expectedRevision,
        Admin $actor,
        ?string $completionUrl = null,
        ?string $resultNote = null,
    ): ManualPublication {
        return DB::transaction(function () use ($manualPublication, $targetStatus, $expectedRevision, $actor, $completionUrl, $resultNote): ManualPublication {
            $batchId = ManualPublication::query()
                ->whereKey($manualPublication->getKey())
                ->value('manual_publication_batch_id');
            if ($batchId !== null) {
                ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            }
            $current = ManualPublication::query()
                ->whereKey($manualPublication->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $current->revision !== $expectedRevision) {
                throw new ManualPublicationConflictException;
            }
            $ability = $current->isReopenTransition($targetStatus) ? 'reopen' : 'transition';
            Gate::forUser($actor)->authorize($ability, $current);
            if (! $current->canTransitionTo($targetStatus)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }
            // B10 修复：reopen（failed/skipped/cancelled → ready）次数上限，
            // 防止失败工作单被无限次重新入队形成重试循环（config geoflow.manual_publications.max_reopens，默认 5）
            if ($current->isReopenTransition($targetStatus)) {
                $maxReopens = max(1, (int) config('geoflow.manual_publications.max_reopens', 5));
                $reopenCount = ManualPublicationTransition::query()
                    ->where('manual_publication_id', $current->getKey())
                    ->whereIn('from_status', ManualPublication::REOPENABLE_STATUSES)
                    ->where('to_status', ManualPublication::STATUS_READY)
                    ->count();
                if ($reopenCount >= $maxReopens) {
                    throw new DomainException(sprintf(
                        '工作单已重开 %d 次（上限 %d），请检查失败根因或新建工作单',
                        $reopenCount,
                        $maxReopens
                    ));
                }
            }
            if ($current->article_id !== null && in_array($targetStatus, [
                ManualPublication::STATUS_READY,
                ManualPublication::STATUS_IN_PROGRESS,
                ManualPublication::STATUS_COMPLETED,
            ], true)) {
                $this->assertSourceArticleQuality((int) $current->article_id, 'manual_publication_transition');
            }
            if ($current->status === ManualPublication::STATUS_IN_PROGRESS
                && $targetStatus === ManualPublication::STATUS_READY) {
                $isBrowserClaim = $current->browser_claimed_at !== null;
                $isStale = $current->browser_claimed_by_token_id === null
                    || $current->browser_last_seen_at === null
                    || $current->browser_last_seen_at->lte(now()->subMinutes(10));
                // 仅浏览器认领的工单需要「失联十分钟」守卫；人工转入 in_progress
                // （无浏览器认领字段）的工单允许管理员随时释放回 ready，否则会永久卡死。
                if ($isBrowserClaim && ! $isStale) {
                    throw new DomainException((string) __('admin.manual_publications.error.browser_claim_active'));
                }
            }

            $fromStatus = (string) $current->status;
            $transitionedAt = now();
            $normalizedResultNote = trim((string) $resultNote) ?: null;
            $updates = [
                'status' => $targetStatus,
                'status_changed_at' => $transitionedAt,
                'revision' => $expectedRevision + 1,
                'result_note' => $normalizedResultNote,
            ];

            if ($targetStatus === ManualPublication::STATUS_READY) {
                $this->ensureReadyRequirements(array_merge($current->getAttributes(), ['status' => $targetStatus]));
                $updates['completion_url'] = null;
                $updates['completed_at'] = null;
                $updates['execution_receipt'] = null;
                $updates['browser_claimed_by_token_id'] = null;
                $updates['browser_claimed_at'] = null;
                $updates['browser_last_seen_at'] = null;
                $updates['publication_payload'] = $this->publicationPayloadBuilder->build(array_merge(
                    $current->getAttributes(),
                    $updates,
                ));
            }

            if ($targetStatus === ManualPublication::STATUS_COMPLETED) {
                $completionUrl = trim((string) $completionUrl);
                if (! $this->isHttpUrl($completionUrl)) {
                    throw new DomainException((string) __('admin.manual_publications.error.completion_url_required'));
                }
                $updates['completion_url'] = $completionUrl;
                $updates['completed_at'] = now();
            }

            if (in_array($targetStatus, [
                ManualPublication::STATUS_COMPLETED,
                ManualPublication::STATUS_FAILED,
                ManualPublication::STATUS_SKIPPED,
                ManualPublication::STATUS_CANCELLED,
                ManualPublication::STATUS_OUTCOME_UNKNOWN,
            ], true)) {
                $updates['browser_claimed_by_token_id'] = null;
                $updates['browser_claimed_at'] = null;
                $updates['browser_last_seen_at'] = null;
            }

            $current->forceFill($updates)->save();
            $this->recordTransition(
                $current,
                $fromStatus,
                $targetStatus,
                $actor,
                $targetStatus === ManualPublication::STATUS_COMPLETED ? $completionUrl : null,
                $normalizedResultNote,
                $transitionedAt,
            );
            $this->syncBatchStatus($current);

            return $current->refresh();
        });
    }

    /** @return Collection<int, ManualPublication> */
    public function duplicatesFor(ManualPublication $manualPublication): Collection
    {
        return $this->duplicateDetector->find([
            'platform' => (string) $manualPublication->platform,
            'article_id' => $manualPublication->article_id === null ? null : (int) $manualPublication->article_id,
            'target_url_hash' => $manualPublication->target_url_hash,
            'content' => (string) $manualPublication->content,
            'content_fingerprint' => (string) $manualPublication->content_fingerprint,
        ], (int) $manualPublication->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?ManualPublication $existing = null): array
    {
        $type = (string) $data['type'];
        if (! in_array($type, ManualPublication::TYPES, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_type'));
        }
        $platform = (string) $data['platform'];
        if (! in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_platform'));
        }
        $customPlatform = trim((string) ($data['custom_platform'] ?? '')) ?: null;
        if ($platform === ManualPublicationAccount::PLATFORM_CUSTOM && $customPlatform === null) {
            throw new DomainException((string) __('admin.manual_publications.error.custom_platform_required'));
        }

        $persona = ManualPublicationPersona::query()
            ->whereKey((int) $data['persona_id'])
            ->where('is_active', true)
            ->first();
        if (! $persona instanceof ManualPublicationPersona) {
            throw new DomainException((string) __('admin.manual_publications.error.persona_inactive'));
        }

        $account = null;
        if (! empty($data['account_id'])) {
            $account = ManualPublicationAccount::query()
                ->whereKey((int) $data['account_id'])
                ->where('is_active', true)
                ->first();
            if (! $account instanceof ManualPublicationAccount
                || (int) $account->persona_id !== (int) $persona->getKey()
                || $account->platform !== $platform
                || ($platform === ManualPublicationAccount::PLATFORM_CUSTOM
                    && $account->custom_platform !== null
                    && trim((string) $account->custom_platform) !== $customPlatform)) {
                throw new DomainException((string) __('admin.manual_publications.error.account_mismatch'));
            }
        }

        $article = null;
        if ($type === ManualPublication::TYPE_POST) {
            $article = Article::query()->find((int) $data['article_id']);
            if (! $article instanceof Article || ! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.article_not_approved'));
            }
            $targetStatus = (string) ($data['status'] ?? $existing?->status ?? ManualPublication::STATUS_DRAFT);
            if (in_array($targetStatus, [ManualPublication::STATUS_READY, ManualPublication::STATUS_IN_PROGRESS], true)) {
                $this->assertSourceArticleQuality((int) $article->id, 'manual_publication_prepare');
            }
        }

        if (! empty($data['assigned_admin_id'])) {
            $assigneeExists = Admin::query()
                ->whereKey((int) $data['assigned_admin_id'])
                ->where('status', 'active')
                ->exists();
            if (! $assigneeExists) {
                throw new DomainException((string) __('admin.manual_publications.error.assignee_inactive'));
            }
        }

        $content = trim((string) $data['content']);
        if ($content === '' || mb_strlen($content) > ManualPublication::maxContentCharactersForType($type)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_content'));
        }
        $targetUrl = trim((string) ($data['target_url'] ?? '')) ?: null;
        $targetContext = trim((string) ($data['target_context'] ?? '')) ?: null;
        if ($targetUrl !== null && ! $this->isHttpUrl($targetUrl)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_target_url'));
        }
        if ($type === ManualPublication::TYPE_COMMENT && ($targetUrl === null || $targetContext === null)) {
            throw new DomainException((string) __('admin.manual_publications.error.comment_target_required'));
        }
        $targetUrlHash = $this->duplicateDetector->targetUrlHash($targetUrl);
        $contentFingerprint = $this->duplicateDetector->fingerprint($content);
        $riskResult = $this->riskScanner->scan([
            'title' => (string) ($article?->title ?? ''),
            'excerpt' => $targetContext ?? '',
            'content' => $content,
            'keywords' => '',
            'meta_description' => (string) ($persona->disclosure_text ?? ''),
        ]);

        $sourceSnapshot = is_array($data['source_snapshot'] ?? null)
            ? $data['source_snapshot']
            : $existing?->source_snapshot;
        if ($article instanceof Article && ((int) $existing?->article_id !== (int) $article->getKey() || ! is_array($sourceSnapshot))) {
            $sourceSnapshot = [
                'article_id' => (int) $article->getKey(),
                'title' => (string) $article->title,
                'slug' => (string) $article->slug,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => (string) $article->content,
                'review_status' => (string) $article->review_status,
                'snapshotted_at' => now()->toAtomString(),
            ];
        }

        return [
            'type' => $type,
            'manual_publication_batch_id' => empty($data['manual_publication_batch_id'])
                ? $existing?->manual_publication_batch_id
                : (int) $data['manual_publication_batch_id'],
            'article_id' => $article?->getKey(),
            'persona_id' => $persona->getKey(),
            'account_id' => $account?->getKey(),
            'assigned_admin_id' => empty($data['assigned_admin_id']) ? null : (int) $data['assigned_admin_id'],
            'platform' => $platform,
            'custom_platform' => $customPlatform,
            'target_url' => $targetUrl,
            'target_url_hash' => $targetUrlHash,
            'target_context' => $targetContext,
            'content' => $content,
            'platform_title' => trim((string) ($data['platform_title'] ?? $existing?->platform_title ?? '')) ?: null,
            'platform_summary' => trim((string) ($data['platform_summary'] ?? $existing?->platform_summary ?? '')) ?: null,
            'body_markdown' => (string) ($data['body_markdown'] ?? $existing?->body_markdown ?? $content),
            'body_html' => trim((string) ($data['body_html'] ?? $existing?->body_html ?? '')) ?: null,
            'tags' => array_values((array) ($data['tags'] ?? $existing?->tags ?? [])),
            'media_manifest' => array_values((array) ($data['media_manifest'] ?? $existing?->media_manifest ?? [])),
            'source_hash' => trim((string) ($data['source_hash'] ?? $existing?->source_hash ?? '')) ?: null,
            'content_fingerprint' => $contentFingerprint,
            'source_snapshot' => $type === ManualPublication::TYPE_POST ? $sourceSnapshot : null,
            'identity_snapshot' => $this->identitySnapshot($persona, $account),
            'disclosure_snapshot' => trim((string) ($persona->disclosure_text ?? '')) ?: null,
            'risk_status' => (string) Arr::get($riskResult, 'status', 'clean'),
            'risk_result' => $riskResult,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'document_schema_version' => trim((string) ($data['document_schema_version'] ?? $existing?->document_schema_version ?? '')) ?: null,
            'portable_document' => is_array($data['portable_document'] ?? null)
                ? $data['portable_document']
                : $existing?->portable_document,
            'render_fingerprint' => is_array($data['render_fingerprint'] ?? null)
                ? $data['render_fingerprint']
                : $existing?->render_fingerprint,
            'content_type' => trim((string) ($data['content_type'] ?? $existing?->content_type ?? '')) ?: null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function ensureReadyRequirements(array $attributes): void
    {
        if (($attributes['status'] ?? null) !== ManualPublication::STATUS_READY) {
            return;
        }
        if (empty($attributes['assigned_admin_id'])) {
            throw new DomainException((string) __('admin.manual_publications.error.ready_requires_assignee'));
        }
        if (! empty($attributes['manual_publication_batch_id'])) {
            if (! empty($attributes['source_stale_at'])) {
                throw new DomainException('官网母稿已经变化，旧平台稿不能进入浏览器队列。');
            }
            if (empty($attributes['account_id']) || trim((string) ($attributes['target_url'] ?? '')) === '') {
                throw new DomainException('请先为该平台配置有效账号和编辑入口。');
            }
        }
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @return array<string, mixed> */
    private function identitySnapshot(ManualPublicationPersona $persona, ?ManualPublicationAccount $account): array
    {
        return [
            'persona' => [
                'id' => (int) $persona->getKey(),
                'name' => (string) $persona->name,
                'tone' => $persona->tone,
                'domain' => $persona->domain,
            ],
            'account' => $account instanceof ManualPublicationAccount ? [
                'id' => (int) $account->getKey(),
                'account_name' => (string) $account->account_name,
                'platform' => (string) $account->platform,
                'custom_platform' => $account->custom_platform,
                'profile_url' => $account->profile_url,
                'editor_url' => $account->editor_url,
                'account_uid' => $account->account_uid,
                'homepage_identifier' => $account->homepage_identifier,
                'browser_adapter_enabled' => (bool) $account->browser_adapter_enabled,
            ] : null,
            'snapshotted_at' => now()->toAtomString(),
        ];
    }

    private function recordTransition(
        ManualPublication $publication,
        ?string $fromStatus,
        string $toStatus,
        ?Admin $actor = null,
        ?string $completionUrl = null,
        ?string $resultNote = null,
        ?Carbon $createdAt = null,
    ): void {
        ManualPublicationTransition::query()->create([
            'manual_publication_id' => $publication->getKey(),
            'changed_by_admin_id' => $actor?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'completion_url' => $completionUrl,
            'result_note' => $resultNote,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function syncBatchStatus(ManualPublication $publication): void
    {
        if ($publication->manual_publication_batch_id === null) {
            return;
        }

        $batch = ManualPublicationBatch::query()
            ->whereKey($publication->manual_publication_batch_id)
            ->lockForUpdate()
            ->first();
        if (! $batch instanceof ManualPublicationBatch) {
            return;
        }

        $status = ManualPublicationBatch::statusFromPublications(
            $batch->publications()->pluck('status')->all(),
            (string) $batch->status,
        );
        if ($status !== $batch->status) {
            $batch->forceFill([
                'status' => $status,
                'revision' => (int) $batch->revision + 1,
            ])->save();
        }
    }

    private function assertSourceArticleQuality(int $articleId, string $trigger): void
    {
        $article = Article::query()->find($articleId);
        if (! $article instanceof Article) {
            throw new DomainException((string) __('admin.manual_publications.error.article_not_approved'));
        }

        try {
            $this->publicationQualityGate->check($article, $trigger);
        } catch (ArticleAiQualityGateException|ArticleRiskGateException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
    }
}
