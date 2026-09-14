<?php

namespace App\Console\Commands;

use App\Models\ManualPublication;
use App\Models\ManualPublicationTransition;
use App\Services\BrowserOperations\ManualPublicationBrowserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stale browser-claim 回收 — B8 修复
 *
 * 浏览器扩展卡死、worker OOM、网络断电等都会让 manual_publications 行停在
 * in_progress + 旧 last_seen_at，claim token 已无效。本命令把超过
 * --stale-after 分钟没心跳的行释放回 ready，并写一条 transition 日志。
 *
 * 注：draft_filled 状态不动 —— 平台端已经有数据，回收会丢失工作。
 *
 * 参数：
 *   --dry-run             只打印候选项，不改 DB
 *   --stale-after=MIN     阈值分钟（>=1，未传则用 ManualPublicationBrowserService::STALE_AFTER_MINUTES）
 *   --limit=N             单次最多回收条数（1~500）
 */
final class RecoverStaleBrowserClaimsCommand extends Command
{
    protected $signature = 'geoflow:recover-browser-claims {--stale-after=} {--limit=100} {--dry-run}';

    protected $description = 'Release manual_publication browser claims whose last_seen_at is older than --stale-after minutes';

    public function handle(): int
    {
        $defaultCutoff = (int) ManualPublicationBrowserService::STALE_AFTER_MINUTES;
        $staleAfterMinutes = (int) ($this->option('stale-after') ?: $defaultCutoff);
        if ($staleAfterMinutes < 1) {
            $this->error('--stale-after 必须 >= 1 分钟');

            return self::FAILURE;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($staleAfterMinutes);

        $candidates = ManualPublication::query()
            ->where('status', ManualPublication::STATUS_IN_PROGRESS)
            ->whereNotNull('browser_claimed_by_token_id')
            ->whereNotNull('browser_last_seen_at')
            ->where('browser_last_seen_at', '<', $cutoff)
            ->orderBy('browser_last_seen_at')
            ->limit($limit)
            ->get([
                'id',
                'status',
                'browser_claimed_by_token_id',
                'browser_last_seen_at',
                'revision',
                'manual_publication_batch_id',
            ]);

        if ($candidates->isEmpty()) {
            $this->info(sprintf('No browser claims older than %d minutes (cutoff=%s).', $staleAfterMinutes, $cutoff->toIso8601String()));

            return 0;
        }

        $this->info(sprintf('Found %d stale browser-claim row(s) older than %d minutes (cutoff=%s).',
            $candidates->count(), $staleAfterMinutes, $cutoff->toIso8601String()));

        if ($dryRun) {
            foreach ($candidates as $row) {
                $this->line(sprintf('  - #%d  last_seen=%s  token=%s  rev=%d',
                    (int) $row->id,
                    (string) $row->browser_last_seen_at,
                    (string) $row->browser_claimed_by_token_id,
                    (int) $row->revision,
                ));
            }
            $this->warn('Dry run, no changes applied.');

            return 0;
        }

        $recovered = 0;
        foreach ($candidates as $row) {
            try {
                DB::transaction(function () use ($row, $staleAfterMinutes, &$recovered): void {
                    /** @var ManualPublication|null $locked */
                    $locked = ManualPublication::query()
                        ->whereKey((int) $row->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $locked instanceof ManualPublication) {
                        return;
                    }
                    if ($locked->status !== ManualPublication::STATUS_IN_PROGRESS) {
                        return; // 并发 worker 可能在循环间隙已处理
                    }
                    // 双检：循环期间心跳可能又跳了
                    if ($locked->browser_last_seen_at === null
                        || $locked->browser_last_seen_at->gte(now()->subMinutes($staleAfterMinutes))) {
                        return;
                    }
                    $fromStatus = (string) $locked->status;
                    $transitionedAt = now();
                    $staleToken = (int) $locked->browser_claimed_by_token_id;
                    $locked->forceFill([
                        'status' => ManualPublication::STATUS_READY,
                        'status_changed_at' => $transitionedAt,
                        'browser_claimed_by_token_id' => null,
                        'browser_claimed_at' => null,
                        'browser_last_seen_at' => null,
                        'revision' => (int) $locked->revision + 1,
                    ])->save();
                    ManualPublicationTransition::query()->create([
                        'manual_publication_id' => (int) $locked->id,
                        'changed_by_admin_id' => null,
                        'from_status' => $fromStatus,
                        'to_status' => ManualPublication::STATUS_READY,
                        'completion_url' => null,
                        'result_note' => sprintf(
                            'stale-claim recovery (last_seen=%s, token_id=%d, cutoff_min=%d)',
                            (string) $row->browser_last_seen_at,
                            $staleToken,
                            $staleAfterMinutes
                        ),
                        'created_at' => $transitionedAt,
                    ]);
                    $recovered++;
                }, 3);
            } catch (\Throwable $e) {
                Log::warning('geoflow:recover-browser-claims single row failed', [
                    'publication_id' => (int) $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('Recovered %d stale browser claim(s) → ready.', $recovered));

        return 0;
    }
}
