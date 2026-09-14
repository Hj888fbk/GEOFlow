<?php

namespace App\Services\SelfMedia;

use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationBatch;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ManualPublicationLifecycleService
{
    private const TRASHABLE_PUBLICATION_STATUSES = [
        ManualPublication::STATUS_DRAFT,
        ManualPublication::STATUS_FAILED,
        ManualPublication::STATUS_SKIPPED,
        ManualPublication::STATUS_CANCELLED,
    ];

    private const TRASHABLE_BATCH_STATUSES = [
        ManualPublicationBatch::STATUS_PLANNED,
        ManualPublicationBatch::STATUS_PENDING_REVIEW,
        ManualPublicationBatch::STATUS_FAILED,
        ManualPublicationBatch::STATUS_CANCELLED,
        ManualPublicationBatch::STATUS_INVALIDATED,
    ];

    private const ACTIVE_PUBLICATION_STATUSES = [
        ManualPublication::STATUS_READY,
        ManualPublication::STATUS_IN_PROGRESS,
        ManualPublication::STATUS_DRAFT_FILLED,
        ManualPublication::STATUS_OUTCOME_UNKNOWN,
    ];

    public function __construct(
        private SelfMediaSourceHasher $sourceHasher,
        private SelfMediaMediaSnapshotService $mediaSnapshots,
    ) {}

    public function trashPublication(int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($publicationId): ManualPublication {
            $publication = ManualPublication::query()->whereKey($publicationId)->lockForUpdate()->firstOrFail();
            if ($publication->manual_publication_batch_id !== null) {
                throw new DomainException('批次内平台进度必须随发布任务一起移入回收站。');
            }
            if (! in_array((string) $publication->status, self::TRASHABLE_PUBLICATION_STATUSES, true)) {
                throw new DomainException('当前工单仍在执行或属于已完成记录，不能移入回收站。');
            }

            $publication->delete();

            return $publication;
        }, 3);
    }

    public function trashBatch(int $batchId): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batchId): ManualPublicationBatch {
            $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if (! in_array((string) $batch->status, self::TRASHABLE_BATCH_STATUSES, true)
                || $batch->publications()->whereIn('status', self::ACTIVE_PUBLICATION_STATUSES)->exists()) {
                throw new DomainException('发布任务仍在生成、领取或待核验，必须先安全取消或完成。');
            }

            foreach ($batch->publications()->lockForUpdate()->get() as $publication) {
                $publication->delete();
            }
            $batch->delete();

            return $batch;
        }, 3);
    }

    public function restorePublication(int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($publicationId): ManualPublication {
            $publication = ManualPublication::onlyTrashed()->whereKey($publicationId)->lockForUpdate()->firstOrFail();
            if ($publication->manual_publication_batch_id !== null) {
                throw new DomainException('批次内平台进度必须随发布任务一起恢复。');
            }
            $publication->restore();

            return $publication->refresh();
        }, 3);
    }

    public function restoreBatch(int $batchId): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batchId): ManualPublicationBatch {
            $batch = ManualPublicationBatch::onlyTrashed()->whereKey($batchId)->lockForUpdate()->firstOrFail();
            $batch->restore();
            $publications = ManualPublication::onlyTrashed()
                ->where('manual_publication_batch_id', $batch->id)
                ->lockForUpdate()
                ->get();
            foreach ($publications as $publication) {
                $publication->restore();
            }

            $article = Article::withTrashed()->find($batch->article_id);
            if (! $article instanceof Article
                || ! hash_equals((string) $batch->source_hash, $this->sourceHasher->hash($article))) {
                $at = now();
                $batch->forceFill([
                    'status' => ManualPublicationBatch::STATUS_INVALIDATED,
                    'invalidated_at' => $at,
                    'execution_lease_token' => null,
                    'lease_expires_at' => null,
                    'revision' => (int) $batch->revision + 1,
                ])->save();
                $batch->publications()->update([
                    'source_stale_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            return $batch->refresh();
        }, 3);
    }

    public function archivePublication(int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($publicationId): ManualPublication {
            $publication = ManualPublication::query()->whereKey($publicationId)->lockForUpdate()->firstOrFail();
            if ($publication->manual_publication_batch_id !== null) {
                throw new DomainException('批次内平台进度必须随发布任务一起归档。');
            }
            if ((string) $publication->status !== ManualPublication::STATUS_COMPLETED) {
                throw new DomainException('只有已完成工单可以归档。');
            }
            $publication->forceFill(['archived_at' => now()])->save();

            return $publication->refresh();
        }, 3);
    }

    public function unarchivePublication(int $publicationId): ManualPublication
    {
        return DB::transaction(function () use ($publicationId): ManualPublication {
            $publication = ManualPublication::query()->whereKey($publicationId)->lockForUpdate()->firstOrFail();
            $publication->forceFill(['archived_at' => null])->save();

            return $publication->refresh();
        }, 3);
    }

    public function archiveBatch(int $batchId): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batchId): ManualPublicationBatch {
            $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if ((string) $batch->status !== ManualPublicationBatch::STATUS_COMPLETED) {
                throw new DomainException('只有已完成发布任务可以归档。');
            }
            $at = now();
            $batch->forceFill(['archived_at' => $at])->save();
            $batch->publications()->update(['archived_at' => $at, 'updated_at' => $at]);

            return $batch->refresh();
        }, 3);
    }

    public function unarchiveBatch(int $batchId): ManualPublicationBatch
    {
        return DB::transaction(function () use ($batchId): ManualPublicationBatch {
            $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
            $batch->forceFill(['archived_at' => null])->save();
            $batch->publications()->update(['archived_at' => null, 'updated_at' => now()]);

            return $batch->refresh();
        }, 3);
    }

    /** @return array{batches:int,publications:int} */
    public function pruneExpired(): array
    {
        $cutoff = now()->subDays(ManualPublication::TRASH_RETENTION_DAYS);
        $batchIds = ManualPublicationBatch::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->pluck('id');
        $prunedBatches = 0;
        $prunedPublications = 0;

        foreach ($batchIds as $batchId) {
            $deleted = DB::transaction(function () use ($batchId, &$prunedPublications): bool {
                $batch = ManualPublicationBatch::onlyTrashed()->whereKey($batchId)->lockForUpdate()->first();
                if (! $batch instanceof ManualPublicationBatch) {
                    return false;
                }
                $publications = ManualPublication::onlyTrashed()
                    ->where('manual_publication_batch_id', $batch->id)
                    ->lockForUpdate()
                    ->get();
                foreach ($publications as $publication) {
                    $publication->forceDelete();
                    $prunedPublications++;
                }
                $batch->forceDelete();

                return true;
            }, 3);
            if ($deleted) {
                $this->mediaSnapshots->purgeBatchFiles((int) $batchId);
                $prunedBatches++;
            }
        }

        ManualPublication::onlyTrashed()
            ->whereNull('manual_publication_batch_id')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($publications) use (&$prunedPublications): void {
                foreach ($publications as $publication) {
                    if ($publication->forceDelete()) {
                        $prunedPublications++;
                    }
                }
            });

        return ['batches' => $prunedBatches, 'publications' => $prunedPublications];
    }
}
