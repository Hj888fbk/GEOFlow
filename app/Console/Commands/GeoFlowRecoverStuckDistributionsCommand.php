<?php

namespace App\Console\Commands;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\ArticleDistribution;
use App\Models\DistributionLog;
use Illuminate\Console\Command;

/**
 * B9 自愈：回收卡死的文章分发行。
 *
 * 背景：ProcessArticleDistributionJob 有意保持 $tries=1（防止框架级重试造成重复发布），
 * 领域级重试靠 catch 中重新 dispatch。但如果延迟任务在队列崩溃/flush 中丢失，
 * 对应行会永久停留在 queued（next_retry_at 已过期）或 sending（worker 中途死亡）。
 *
 * 策略（保守，防止重复发布）：
 *  - queued 且 next_retry_at 已到期、updated_at 超过防抖窗口 → 重新入队（尚未发出，安全）
 *  - sending 且 last_attempt_at 失联超过阈值 → 标记 failed（远端结果不确定，绝不自动重发，需人工核对）
 */
final class GeoFlowRecoverStuckDistributionsCommand extends Command
{
    protected $signature = 'geoflow:recover-stuck-distributions {--dry-run}';

    protected $description = 'Re-dispatch stranded queued distributions and fail stale sending rows (prevents double-publish)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $queuedDebounceMinutes = max(5, (int) config('geoflow.distribution_recovery.queued_debounce_minutes', 10));
        $sendingStaleMinutes = max(15, (int) config('geoflow.distribution_recovery.sending_stale_minutes', 30));
        $batchLimit = max(1, (int) config('geoflow.distribution_recovery.batch_limit', 100));

        $reDispatched = $this->recoverQueued($dryRun, $queuedDebounceMinutes, $batchLimit);
        $failedStale = $this->failStaleSending($dryRun, $sendingStaleMinutes, $batchLimit);

        $this->info(sprintf(
            'Recovery done: %d queued re-dispatched, %d stale sending rows marked failed%s.',
            $reDispatched,
            $failedStale,
            $dryRun ? ' (dry-run)' : ''
        ));

        return self::SUCCESS;
    }

    private function recoverQueued(bool $dryRun, int $debounceMinutes, int $limit): int
    {
        $cutoff = now()->subMinutes($debounceMinutes);
        $rows = ArticleDistribution::query()
            ->where('status', 'queued')
            ->where('updated_at', '<', $cutoff)
            ->where(static function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'distribution_channel_id', 'article_id', 'updated_at', 'next_retry_at']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->info(sprintf('Found %d stranded queued distribution row(s).', $rows->count()));
        $count = 0;
        foreach ($rows as $row) {
            if ($dryRun) {
                $this->line(sprintf('  [dry-run] would re-dispatch distribution #%d (updated_at=%s)', (int) $row->id, (string) $row->updated_at));

                continue;
            }
            // 条件更新防止与丢失的延迟任务竞态：若 updated_at 已变（有其他进程动过），跳过
            $updated = ArticleDistribution::query()
                ->whereKey((int) $row->id)
                ->where('status', 'queued')
                ->where('updated_at', (string) $row->updated_at)
                ->update(['updated_at' => now()]);
            if ($updated !== 1) {
                continue;
            }
            ProcessArticleDistributionJob::dispatch((int) $row->id)->onQueue('distribution');
            $this->log((int) $row->distribution_channel_id, (int) $row->id, (int) $row->article_id,
                'warning', 'distribution.stuck_recovered',
                sprintf('queued 行卡死超过 %d 分钟，已重新入队（自愈命令）', $debounceMinutes));
            $count++;
        }

        return $count;
    }

    private function failStaleSending(bool $dryRun, int $staleMinutes, int $limit): int
    {
        $cutoff = now()->subMinutes($staleMinutes);
        $rows = ArticleDistribution::query()
            ->where('status', 'sending')
            ->where(static function ($query) use ($cutoff): void {
                $query->where('last_attempt_at', '<', $cutoff)
                    ->orWhereNull('last_attempt_at');
            })
            ->orderBy('last_attempt_at')
            ->limit($limit)
            ->get(['id', 'distribution_channel_id', 'article_id', 'last_attempt_at']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->info(sprintf('Found %d stale sending distribution row(s) (>%d min).', $rows->count(), $staleMinutes));
        $count = 0;
        foreach ($rows as $row) {
            if ($dryRun) {
                $this->line(sprintf('  [dry-run] would fail distribution #%d (last_attempt_at=%s)', (int) $row->id, (string) $row->last_attempt_at));

                continue;
            }
            $updated = ArticleDistribution::query()
                ->whereKey((int) $row->id)
                ->where('status', 'sending')
                ->update([
                    'status' => 'failed',
                    'last_error_message' => sprintf(
                        'sending 状态失联超过 %d 分钟，已由自愈命令标记失败。远端是否已发布不确定，请人工核对后再决定是否重试。',
                        $staleMinutes
                    ),
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                continue;
            }
            $this->log((int) $row->distribution_channel_id, (int) $row->id, (int) $row->article_id,
                'error', 'distribution.sending_stale_failed',
                sprintf('sending 失联超过 %d 分钟，标记 failed（自愈命令，未自动重发）', $staleMinutes));
            $count++;
        }

        return $count;
    }

    private function log(int $channelId, int $distributionId, int $articleId, string $level, string $event, string $message): void
    {
        try {
            DistributionLog::query()->create([
                'distribution_channel_id' => $channelId,
                'article_distribution_id' => $distributionId,
                'article_id' => $articleId,
                'level' => $level,
                'event' => $event,
                'message' => $message,
                'context' => ['recovered_by' => 'geoflow:recover-stuck-distributions'],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响回收主流程
            report($e);
        }
    }
}
