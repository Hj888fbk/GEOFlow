<?php

namespace App\Services\BrowserOperations;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationTransition;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ManualPublicationBrowserService
{
    public const STALE_AFTER_MINUTES = 10;

    public function __construct(private readonly ArticlePublicationQualityGate $publicationQualityGate) {}

    public function queue(Admin $admin, int $tokenId, int $perPage): LengthAwarePaginator
    {
        return ManualPublication::query()
            ->visibleTo($admin)
            ->with(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name'])
            ->whereNotNull('publication_payload')
            ->whereNotNull('target_url')
            ->whereNull('source_stale_at')
            ->where(function ($query) use ($tokenId): void {
                $query->where('status', ManualPublication::STATUS_READY)
                    ->orWhere(function ($claimed) use ($tokenId): void {
                        $claimed->whereIn('status', [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED])
                            ->where('browser_claimed_by_token_id', $tokenId);
                    });
            })
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function findVisible(Admin $admin, int $tokenId, int $publicationId): ManualPublication
    {
        $publication = ManualPublication::query()
            ->visibleTo($admin)
            ->with(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name'])
            ->find($publicationId);
        if (! $publication instanceof ManualPublication) {
            throw new ApiException('publication_not_found', '工作单不存在', 404);
        }
        if (in_array((string) $publication->status, [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED], true)
            && (int) $publication->browser_claimed_by_token_id !== $tokenId) {
            throw new ApiException('claim_owned_by_another_client', '当前浏览器连接不持有该工作单', 409);
        }

        return $publication;
    }

    public function claim(Admin $admin, int $tokenId, int $publicationId, int $revision): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            if ($publication->status !== ManualPublication::STATUS_READY) {
                throw new ApiException('publication_claimed', '工作单已被领取或不处于待执行状态', 409);
            }
            if ($publication->source_stale_at !== null) {
                throw new ApiException('source_changed', '官网母稿已经变化，旧平台稿不可执行', 409);
            }
            $activeForClient = ManualPublication::query()
                ->where('browser_claimed_by_token_id', $tokenId)
                ->whereIn('status', [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED])
                ->whereKeyNot($publication->id)
                ->lockForUpdate()
                ->exists();
            if ($activeForClient) {
                throw new ApiException('browser_concurrency_limit', '同一 Chrome 环境一次只能处理一条工作单', 409);
            }
            $this->assertSourceArticleQuality($publication);
            if (! is_array($publication->publication_payload)) {
                throw new ApiException('browser_payload_unavailable', '该历史工作单没有浏览器执行载荷', 409);
            }
            if (trim((string) $publication->target_url) === '') {
                throw new ApiException('browser_target_required', '浏览器执行工作单必须提供目标 URL', 409);
            }
            if (($publication->publication_payload['target_action'] ?? null) === 'zhihu_answer'
                && (! $publication->account || trim((string) $publication->account->profile_url) === '')) {
                throw new ApiException('account_profile_required', '浏览器执行账号缺少 profile_url', 409);
            }
            if ((int) ($publication->publication_payload['schema_version'] ?? 1) >= 2
                && (! $publication->account || ! $publication->account->browser_adapter_enabled)) {
                throw new ApiException('browser_adapter_disabled', '该平台账号的浏览器适配器尚未启用', 409);
            }
            if ((int) ($publication->publication_payload['schema_version'] ?? 1) >= 2
                && trim((string) ($publication->account?->profile_url ?? '')) === ''
                && trim((string) ($publication->account?->account_uid ?? '')) === ''
                && trim((string) ($publication->account?->homepage_identifier ?? '')) === '') {
                throw new ApiException('account_identity_required', '浏览器执行账号缺少可核验的账号标识', 409);
            }

            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $publication->forceFill([
                'status' => ManualPublication::STATUS_IN_PROGRESS,
                'status_changed_at' => $transitionedAt,
                'browser_claimed_by_token_id' => $tokenId,
                'browser_claimed_at' => now(),
                'browser_last_seen_at' => now(),
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition($publication, $admin, $fromStatus, ManualPublication::STATUS_IN_PROGRESS, createdAt: $transitionedAt);
            $this->syncBatchStatus($publication);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name']);
        });
    }

    public function heartbeat(Admin $admin, int $tokenId, int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertClaimOwner($publication, $tokenId);
            $publication->forceFill(['browser_last_seen_at' => now()])->save();

            return $publication->refresh();
        });
    }

    public function release(Admin $admin, int $tokenId, int $publicationId, int $revision): ManualPublication
    {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            $this->assertClaimOwner($publication, $tokenId);
            if ($publication->status === ManualPublication::STATUS_DRAFT_FILLED) {
                throw new ApiException('draft_already_filled', '平台草稿已经填充，不能释放给其他浏览器处理', 409);
            }
            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $publication->forceFill([
                'status' => ManualPublication::STATUS_READY,
                'status_changed_at' => $transitionedAt,
                'browser_claimed_by_token_id' => null,
                'browser_claimed_at' => null,
                'browser_last_seen_at' => null,
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition($publication, $admin, $fromStatus, ManualPublication::STATUS_READY, createdAt: $transitionedAt);
            $this->syncBatchStatus($publication);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name']);
        });
    }

    /** @param array<string,mixed> $receipt */
    public function recordReceipt(
        Admin $admin,
        int $tokenId,
        int $publicationId,
        int $revision,
        array $receipt,
        string $clientVersion,
    ): ManualPublication {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision, $receipt, $clientVersion): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            $this->assertClaimOwner($publication, $tokenId);

            $outcome = (string) $receipt['outcome'];
            $status = match ($outcome) {
                'completed' => ManualPublication::STATUS_COMPLETED,
                'failed' => ManualPublication::STATUS_FAILED,
                'cancelled' => ManualPublication::STATUS_CANCELLED,
                'outcome_unknown' => ManualPublication::STATUS_OUTCOME_UNKNOWN,
                default => throw new ApiException('validation_failed', '执行结果无效', 422),
            };
            $completionUrl = trim((string) ($receipt['completion_url'] ?? '')) ?: null;
            if ($status === ManualPublication::STATUS_COMPLETED && $completionUrl === null) {
                throw new ApiException('validation_failed', '完成状态必须提供发布 URL', 422);
            }
            if ($status === ManualPublication::STATUS_COMPLETED
                && (int) ($publication->publication_payload['schema_version'] ?? 1) >= 2
                && ((int) ($receipt['public_url_readback_status'] ?? 0) !== 200
                    || ! (bool) ($receipt['public_url_readback_succeeded'] ?? false)
                    || ! hash_equals($completionUrl ?? '', trim((string) ($receipt['public_url_readback_url'] ?? ''))))) {
                throw new ApiException('public_url_readback_required', '公开 URL 回读成功后才能标记已发布', 422);
            }
            if ($completionUrl !== null) {
                $this->assertCompletionUrl($publication, $completionUrl);
            }
            $this->assertTargetOrigin($publication, (string) ($receipt['target_origin'] ?? ''));
            $requiresVerifiedAccount = ((int) ($publication->publication_payload['schema_version'] ?? 1) >= 2
                    || ($publication->publication_payload['target_action'] ?? null) === 'zhihu_answer')
                && in_array($outcome, ['completed', 'outcome_unknown'], true);
            $observedAccountHash = strtolower(trim((string) ($receipt['observed_account_hash'] ?? '')));
            if ($observedAccountHash === ''
                && $publication->status === ManualPublication::STATUS_DRAFT_FILLED
                && is_array($publication->draft_filled_receipt)) {
                $observedAccountHash = strtolower(trim((string) ($publication->draft_filled_receipt['observed_account_hash'] ?? '')));
            }
            $this->assertObservedAccount(
                $publication,
                $observedAccountHash,
                $requiresVerifiedAccount,
            );

            $storedReceipt = [
                'schema_version' => max(1, (int) ($publication->publication_payload['schema_version'] ?? 1)),
                'outcome' => $outcome,
                'completion_url' => $completionUrl,
                'protocol_version' => 1,
                'extension_version' => $clientVersion,
                'adapter_version' => (string) ($receipt['adapter_version'] ?? ''),
                'target_origin' => (string) ($receipt['target_origin'] ?? ''),
                'observed_account_hash' => $observedAccountHash,
                'started_at' => $receipt['started_at'] ?? null,
                'finished_at' => $receipt['finished_at'] ?? now()->toIso8601String(),
                'error_code' => $receipt['error_code'] ?? null,
                'public_url_readback_status' => $receipt['public_url_readback_status'] ?? null,
                'public_url_readback_succeeded' => (bool) ($receipt['public_url_readback_succeeded'] ?? false),
                'public_url_readback_url' => trim((string) ($receipt['public_url_readback_url'] ?? '')) ?: null,
            ];

            $fromStatus = (string) $publication->status;
            $transitionedAt = now();
            $resultNote = trim((string) ($receipt['result_note'] ?? '')) ?: null;
            $publication->forceFill([
                'status' => $status,
                'status_changed_at' => $transitionedAt,
                'completion_url' => $completionUrl,
                'result_note' => $resultNote,
                'execution_receipt' => $storedReceipt,
                'completed_at' => $status === ManualPublication::STATUS_COMPLETED ? now() : null,
                'browser_claimed_by_token_id' => null,
                'browser_claimed_at' => null,
                'browser_last_seen_at' => null,
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition(
                $publication,
                $admin,
                $fromStatus,
                $status,
                $completionUrl,
                $resultNote,
                $transitionedAt,
            );
            $this->syncBatchStatus($publication);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name']);
        });
    }

    /** @param array<string,mixed> $receipt */
    public function recordDraftFilled(
        Admin $admin,
        int $tokenId,
        int $publicationId,
        int $revision,
        array $receipt,
        string $clientVersion,
    ): ManualPublication {
        return DB::transaction(function () use ($admin, $tokenId, $publicationId, $revision, $receipt, $clientVersion): ManualPublication {
            $publication = $this->lockVisible($admin, $publicationId);
            $this->assertRevision($publication, $revision);
            $this->assertClaimOwner($publication, $tokenId);
            $this->assertTargetOrigin($publication, (string) ($receipt['target_origin'] ?? ''));
            $this->assertObservedAccount($publication, (string) ($receipt['observed_account_hash'] ?? ''), true);

            $stored = [
                'schema_version' => max(1, (int) ($publication->publication_payload['schema_version'] ?? 1)),
                'outcome' => 'draft_filled',
                'protocol_version' => 1,
                'extension_version' => $clientVersion,
                'adapter_version' => (string) ($receipt['adapter_version'] ?? ''),
                'target_origin' => (string) ($receipt['target_origin'] ?? ''),
                'observed_account_hash' => (string) ($receipt['observed_account_hash'] ?? ''),
                'filled_fields' => array_values((array) ($receipt['filled_fields'] ?? [])),
                'finished_at' => $receipt['finished_at'] ?? now()->toIso8601String(),
            ];
            $fromStatus = (string) $publication->status;
            $at = now();
            $publication->forceFill([
                'status' => ManualPublication::STATUS_DRAFT_FILLED,
                'status_changed_at' => $at,
                'draft_filled_receipt' => $stored,
                'browser_last_seen_at' => $at,
                'revision' => $revision + 1,
            ])->save();
            $this->recordTransition($publication, $admin, $fromStatus, ManualPublication::STATUS_DRAFT_FILLED, resultNote: '草稿已填充，等待人工审核发布', createdAt: $at);
            $this->syncBatchStatus($publication);

            return $publication->refresh()->load(['account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled', 'persona:id,name']);
        });
    }

    private function lockVisible(Admin $admin, int $publicationId): ManualPublication
    {
        $batchId = ManualPublication::query()
            ->visibleTo($admin)
            ->whereKey($publicationId)
            ->value('manual_publication_batch_id');
        if ($batchId !== null) {
            ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->first();
        }
        $publication = ManualPublication::query()
            ->visibleTo($admin)
            ->with('account:id,account_name,platform,profile_url,editor_url,account_uid,homepage_identifier,browser_adapter_enabled')
            ->whereKey($publicationId)
            ->lockForUpdate()
            ->first();
        if (! $publication instanceof ManualPublication) {
            throw new ApiException('publication_not_found', '工作单不存在', 404);
        }

        return $publication;
    }

    private function assertRevision(ManualPublication $publication, int $revision): void
    {
        if ((int) $publication->revision !== $revision) {
            throw new ApiException('revision_conflict', '工作单版本已经变化，请刷新后重试', 409, [
                'current_revision' => (int) $publication->revision,
            ]);
        }
    }

    private function assertSourceArticleQuality(ManualPublication $publication): void
    {
        if ($publication->article_id === null) {
            return;
        }

        $article = Article::query()->find((int) $publication->article_id);
        if (! $article instanceof Article) {
            throw new ApiException('article_unavailable', '源文章不可用，无法领取工作单', 409);
        }

        try {
            $this->publicationQualityGate->check($article, 'browser_publication_claim');
        } catch (ArticleAiQualityGateException $exception) {
            throw new ApiException($exception->getErrorCode(), $exception->getMessage(), 409);
        } catch (ArticleRiskGateException $exception) {
            throw new ApiException('article_risk_blocked', $exception->getMessage(), 409);
        }
    }

    private function assertClaimOwner(ManualPublication $publication, int $tokenId): void
    {
        if (! in_array((string) $publication->status, [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED], true)
            || (int) $publication->browser_claimed_by_token_id !== $tokenId) {
            throw new ApiException('claim_owned_by_another_client', '当前浏览器连接不持有该工作单', 409);
        }
    }

    private function recordTransition(
        ManualPublication $publication,
        Admin $actor,
        string $fromStatus,
        string $toStatus,
        ?string $completionUrl = null,
        ?string $resultNote = null,
        ?CarbonInterface $createdAt = null,
    ): void {
        ManualPublicationTransition::query()->create([
            'manual_publication_id' => $publication->getKey(),
            'changed_by_admin_id' => $actor->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'completion_url' => $completionUrl,
            'result_note' => $resultNote,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function assertCompletionUrl(ManualPublication $publication, string $url): void
    {
        $this->assertSafeHttpUrl($url, 'invalid_completion_url', '发布 URL 格式无效');

        if ($publication->platform === ManualPublicationAccount::PLATFORM_ZHIHU) {
            $this->assertZhihuHost($url, 'invalid_completion_origin');
            $targetPath = (string) parse_url((string) $publication->target_url, PHP_URL_PATH);
            $completionPath = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#\A/question/(\d+)#', $targetPath, $targetMatch) === 1
                && (preg_match('#\A/question/(\d+)/answer/\d+#', $completionPath, $completionMatch) !== 1
                    || $targetMatch[1] !== $completionMatch[1])) {
                throw new ApiException('invalid_completion_target', '发布 URL 不属于该知乎问题', 422);
            }
        }
        $this->assertPlatformHost($publication, $url, 'invalid_completion_origin');
    }

    private function assertTargetOrigin(ManualPublication $publication, string $origin): void
    {
        $path = parse_url($origin, PHP_URL_PATH);
        if (filter_var($origin, FILTER_VALIDATE_URL) === false
            || ($path !== null && ! in_array($path, ['', '/'], true))
            || parse_url($origin, PHP_URL_QUERY) !== null
            || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
            throw new ApiException('invalid_target_origin', '目标页面来源格式无效', 422);
        }

        $this->assertSafeHttpUrl($origin, 'invalid_target_origin', '目标页面来源格式无效');
        if ($publication->platform === ManualPublicationAccount::PLATFORM_ZHIHU) {
            $this->assertZhihuHost($origin, 'invalid_target_origin');
        }
        $this->assertPlatformHost($publication, $origin, 'invalid_target_origin');
    }

    private function assertObservedAccount(
        ManualPublication $publication,
        string $observedHash,
        bool $required,
    ): void {
        $profileUrl = trim((string) ($publication->account?->profile_url ?? ''));
        $accountUid = trim((string) ($publication->account?->account_uid ?? ''));
        $homepageIdentifier = trim((string) ($publication->account?->homepage_identifier ?? ''));
        if (! $required && $observedHash === '') {
            return;
        }
        if ($profileUrl === '' && $accountUid === '' && $homepageIdentifier === '') {
            throw new ApiException('account_identity_required', '浏览器执行账号缺少可核验的账号标识', 409);
        }

        $expectedHashes = array_values(array_filter([
            $profileUrl === '' ? null : hash('sha256', $this->normalizeProfileUrl($profileUrl)),
            $accountUid === '' ? null : hash('sha256', 'uid:'.strtolower($accountUid)),
            $homepageIdentifier === '' ? null : hash('sha256', 'homepage:'.strtolower($homepageIdentifier)),
        ]));
        $matches = $observedHash !== '' && collect($expectedHashes)
            ->contains(static fn (string $expected): bool => hash_equals($expected, strtolower($observedHash)));
        if (! $matches) {
            throw new ApiException('account_mismatch', '页面登录账号与工作单指定账号不一致', 409);
        }
    }

    private function normalizeProfileUrl(string $profileUrl): string
    {
        $scheme = strtolower((string) parse_url($profileUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($profileUrl, PHP_URL_HOST));
        $port = parse_url($profileUrl, PHP_URL_PORT);
        $path = rtrim((string) parse_url($profileUrl, PHP_URL_PATH), '/');

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port).strtolower($path);
    }

    private function assertSafeHttpUrl(string $url, string $code, string $message): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            throw new ApiException($code, $message, 422);
        }
    }

    private function assertZhihuHost(string $url, string $code): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'zhihu.com' && ! str_ends_with($host, '.zhihu.com')) {
            throw new ApiException($code, '页面 URL 与目标平台不匹配', 422);
        }
    }

    private function assertPlatformHost(ManualPublication $publication, string $url, string $code): void
    {
        $allowed = match ((string) $publication->platform) {
            ManualPublicationAccount::PLATFORM_QQ_PENGUIN => ['om.qq.com', 'mp.qq.com'],
            ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN => ['zhihu.com'],
            ManualPublicationAccount::PLATFORM_BAIJIAHAO => ['baijiahao.baidu.com'],
            ManualPublicationAccount::PLATFORM_NETEASE_MEDIA => ['mp.163.com'],
            ManualPublicationAccount::PLATFORM_SOHU_MEDIA => ['sohu.com'],
            ManualPublicationAccount::PLATFORM_WEIBO => ['weibo.com'],
            ManualPublicationAccount::PLATFORM_CSDN => ['csdn.net'],
            ManualPublicationAccount::PLATFORM_DAYU => ['mp.dayu.com'],
            default => [],
        };
        if ($allowed === []) {
            return;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ($allowed as $candidate) {
            if ($host === $candidate || str_ends_with($host, '.'.$candidate)) {
                return;
            }
        }

        throw new ApiException($code, '页面 URL 与目标平台不匹配', 422);
    }

    private function syncBatchStatus(ManualPublication $publication): void
    {
        $batch = $publication->batch;
        if ($batch === null) {
            return;
        }
        $statuses = $batch->publications()->pluck('status')->all();
        $status = ManualPublicationBatch::statusFromPublications($statuses, (string) $batch->status);
        if ($status !== $batch->status) {
            $batch->forceFill(['status' => $status, 'revision' => (int) $batch->revision + 1])->save();
        }
    }
}
