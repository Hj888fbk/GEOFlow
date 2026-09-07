<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublicationBatch;
use App\Models\SelfMediaPolicy;
use App\Models\WebsitePublicationReceipt;
use App\Support\GeoFlow\ImageUrlNormalizer;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SelfMediaBatchService
{
    public const DEFAULT_DAILY_SOURCE_LIMIT = 1;

    public const DEFAULT_PENDING_BATCH_LIMIT = 2;

    public const GENERATION_CONCURRENCY = 2;

    public const BROWSER_CONCURRENCY = 1;

    public function __construct(
        private SelfMediaSourceHasher $hasher,
        private SelfMediaPlatformRouter $router,
        private SelfMediaFactConstraintGuard $factGuard,
        private SelfMediaAiExecutionGuard $aiExecutionGuard,
    ) {}

    /** @param list<string> $platforms */
    public function createManual(
        Article $article,
        WebsitePublicationReceipt $receipt,
        string $intent,
        array $platforms,
        Admin $actor,
    ): ManualPublicationBatch {
        $route = $this->router->route($intent, $platforms);

        return $this->create($article, $receipt, ManualPublicationBatch::TRIGGER_MANUAL, $intent, $route, $actor);
    }

    public function createAutomaticIfEnabled(
        Article $article,
        WebsitePublicationReceipt $receipt,
        Admin $actor,
    ): ?ManualPublicationBatch {
        if ($article->task_id === null) {
            return null;
        }

        $policy = SelfMediaPolicy::query()->where('task_id', $article->task_id)->first();
        if (! $policy instanceof SelfMediaPolicy || ! $policy->enabled) {
            return null;
        }

        $intent = trim((string) $policy->content_intent);
        $override = is_array($policy->platform_override) && $policy->platform_override !== []
            ? $policy->platform_override
            : null;
        $route = $this->router->route($intent, $override);

        return $this->create($article, $receipt, ManualPublicationBatch::TRIGGER_AUTOMATIC, $intent, $route, $actor, $policy);
    }

    /**
     * @param  array{platforms:list<string>,version:string,reason:string}  $route
     */
    private function create(
        Article $article,
        WebsitePublicationReceipt $receipt,
        string $trigger,
        string $intent,
        array $route,
        Admin $actor,
        ?SelfMediaPolicy $policy = null,
    ): ManualPublicationBatch {
        return DB::transaction(function () use ($article, $receipt, $trigger, $intent, $route, $actor, $policy): ManualPublicationBatch {
            $sourceHash = $this->hasher->hash($article);
            $lockedReceipt = WebsitePublicationReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();
            if (! $lockedReceipt instanceof WebsitePublicationReceipt
                || (int) $lockedReceipt->article_id !== (int) $article->id
                || ! $lockedReceipt->isVerifiedFor($sourceHash)) {
                throw new DomainException('官网正式 URL 或在线回读门禁未通过。');
            }

            $platforms = $route['platforms'];
            sort($platforms, SORT_STRING);
            $combinationHash = hash('sha256', implode('|', $platforms));
            $idempotencyHash = hash('sha256', implode('|', [
                (string) $article->id,
                $sourceHash,
                $combinationHash,
            ]));
            $existing = ManualPublicationBatch::query()->where('idempotency_hash', $idempotencyHash)->first();
            if ($existing instanceof ManualPublicationBatch) {
                return $existing;
            }

            $pendingLimit = max(1, (int) ($policy?->pending_batch_limit ?? self::DEFAULT_PENDING_BATCH_LIMIT));
            $pendingCount = ManualPublicationBatch::query()
                ->whereIn('status', ManualPublicationBatch::PENDING_STATUSES)
                ->lockForUpdate()
                ->count();
            if ($pendingCount >= $pendingLimit) {
                throw new DomainException('待审核发布批次已达到上限。');
            }

            if ($trigger === ManualPublicationBatch::TRIGGER_AUTOMATIC) {
                $dailyLimit = max(1, (int) ($policy?->daily_source_limit ?? self::DEFAULT_DAILY_SOURCE_LIMIT));
                $todayCount = ManualPublicationBatch::query()
                    ->where('trigger', ManualPublicationBatch::TRIGGER_AUTOMATIC)
                    ->whereDate('created_at', today())
                    ->lockForUpdate()
                    ->count();
                if ($todayCount >= $dailyLimit) {
                    throw new DomainException('今日自动来源文章已达到上限。');
                }
            }

            $article->loadMissing('articleImages.image');
            $media = $article->articleImages
                ->sortBy('position')
                ->values()
                ->map(static function ($item, int $index): array {
                    $image = $item->image;
                    $previewUrl = ImageUrlNormalizer::toPublicUrl((string) ($image?->file_path ?? ''));
                    if (str_starts_with(strtolower($previewUrl), 'data:')) {
                        $previewUrl = '';
                    }

                    return [
                        'article_image_id' => (int) $item->id,
                        'image_id' => (int) $item->image_id,
                        'position' => (int) $item->position,
                        'role' => $index === 0 ? 'cover' : 'body',
                        'name' => (string) ($image?->original_name ?: $image?->file_name ?: $image?->filename ?: '图片 #'.$item->image_id),
                        'preview_url' => $previewUrl ?: null,
                        'mime_type' => $image?->mime_type,
                        'width' => $image?->width,
                        'height' => $image?->height,
                        'file_size' => $image?->file_size,
                        'upload_required' => true,
                    ];
                })->all();
            $sourceSnapshot = [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => (string) $article->content,
                'keywords' => (string) ($article->keywords ?? ''),
                'source_hash' => $sourceHash,
                'snapshotted_at' => now()->toIso8601String(),
            ];
            $evidence = (array) ($article->generation_evidence_snapshot ?? []);
            $constraints = [
                'evidence' => $evidence,
                'allowed_numbers' => $this->factGuard->extractAllowedNumbers([
                    strip_tags((string) $article->title),
                    strip_tags((string) ($article->excerpt ?? '')),
                    strip_tags((string) $article->content),
                    json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                ]),
                'forbidden_expansion' => ['资质', '认证', '参数', '库存', '交期', '客户案例', '工程案例'],
                'rule' => '不得添加母稿与证据包之外的事实性主张。',
            ];
            $aiExecution = $this->aiExecutionGuard->snapshotForTask(
                $article->task_id === null ? null : (int) $article->task_id,
            );

            return ManualPublicationBatch::query()->create(array_merge([
                'article_id' => (int) $article->id,
                'task_id' => $article->task_id,
                'website_publication_receipt_id' => (int) $lockedReceipt->id,
                'created_by_admin_id' => (int) $actor->id,
                'trigger' => $trigger,
                'content_intent' => $intent,
                'routing_version' => (string) $route['version'],
                'routing_reason' => (string) $route['reason'],
                'target_platforms' => $platforms,
                'platform_combination_hash' => $combinationHash,
                'source_url' => (string) $lockedReceipt->formal_url,
                'website_readback' => [
                    'responsible_project_id' => (string) $lockedReceipt->responsible_project_id,
                    'http_status' => (int) $lockedReceipt->http_status,
                    'source_hash' => (string) $lockedReceipt->source_hash,
                    'readback_hash' => (string) $lockedReceipt->readback_hash,
                    'verified_at' => $lockedReceipt->verified_at?->toIso8601String(),
                ],
                'source_hash' => $sourceHash,
                'source_snapshot' => $sourceSnapshot,
                'fact_constraints' => $constraints,
                'media_manifest' => $media,
                'idempotency_hash' => $idempotencyHash,
                'status' => ManualPublicationBatch::STATUS_PLANNED,
            ], $aiExecution));
        }, 3);
    }

    public function invalidateForChangedArticle(Article $article): int
    {
        $currentHash = $this->hasher->hash($article);
        $batches = ManualPublicationBatch::query()
            ->where('article_id', $article->id)
            ->where('source_hash', '!=', $currentHash)
            ->whereNull('invalidated_at')
            ->get();

        foreach ($batches as $batch) {
            DB::transaction(function () use ($batch): void {
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
            });
        }

        return $batches->count();
    }
}
