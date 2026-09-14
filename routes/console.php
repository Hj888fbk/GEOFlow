<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use App\Data\Ai\SystemAiIdentity;
use App\Models\KnowledgeFactGenerationRun;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\SelfMedia\SelfMediaBatchGenerationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'geoflow:recover-knowledge-syncs {--stale=600} {--limit=50}',
    function (KnowledgeChunkSyncCoordinator $coordinator): int {
        $recovered = $coordinator->recoverStale(
            SystemAiIdentity::knowledgeIndex(),
            max(60, (int) $this->option('stale')),
            max(1, min(200, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered stale knowledge syncs: %d', $recovered));

        return 0;
    }
)->purpose('Requeue knowledge chunk sync pipelines that stopped making progress');

Artisan::command('geoflow:prune-expired-cache {--limit=5000}', function (): int {
    $store = (string) config('cache.limiter', config('cache.default'));
    $storeConfig = (array) config('cache.stores.'.$store, []);
    if (($storeConfig['driver'] ?? null) !== 'database') {
        $this->info('Limiter cache does not use the database store.');

        return 0;
    }

    $connection = $storeConfig['connection'] ?? null;
    $table = (string) ($storeConfig['table'] ?? 'cache');
    $keys = DB::connection($connection)
        ->table($table)
        ->where('expiration', '<=', now()->getTimestamp())
        ->orderBy('expiration')
        ->limit(max(1, min(20000, (int) $this->option('limit'))))
        ->pluck('key');
    $deleted = $keys->isEmpty()
        ? 0
        : DB::connection($connection)->table($table)->whereIn('key', $keys->all())->delete();
    $this->info(sprintf('Pruned expired cache rows: %d', $deleted));

    return 0;
})->purpose('Delete expired database cache rows used by rate limiters');

Artisan::command('geoflow:prune-article-exports', function (ArticleMarkdownExportService $exports): int {
    $deleted = $exports->pruneExpired();
    $this->info(sprintf('Pruned article export artifacts: %d', $deleted));

    return 0;
})->purpose('Delete expired Markdown article export files');

Artisan::command(
    'geoflow:recover-self-media-batches {--limit=50}',
    function (SelfMediaBatchGenerationService $generation): int {
        $recovered = $generation->recoverExpiredLeases((int) $this->option('limit'));
        $this->info(sprintf('Recovered expired self-media batches: %d', $recovered));

        return 0;
    },
)->purpose('Release expired self-media generation leases for safe manual retry');

Artisan::command('geoflow:prune-knowledge-fact-generations {--limit=200} {--dry-run}', function (): int {
    $cutoff = now()->subDays((int) config('geoflow.knowledge_fact_generation_retention_days', 90));
    $pruned = 0;
    KnowledgeFactGenerationRun::query()->whereIn('status', ['completed', 'partial', 'failed', 'cancelled', 'obsolete'])
        ->whereNull('diagnostic_payload_pruned_at')->where('updated_at', '<', $cutoff)->orderBy('id')
        ->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id')->each(function (int $runId) use (&$pruned): void {
            DB::transaction(function () use ($runId, &$pruned): void {
                $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
                if (! $run || $run->diagnostic_payload_pruned_at !== null || ! in_array($run->status, ['completed', 'partial', 'failed', 'cancelled', 'obsolete'], true)) {
                    return;
                }
                $result = (array) $run->result_json;
                if ((array) ($result['conflicts'] ?? []) !== []) {
                    return;
                }
                $pruned++;
                if ((bool) $this->option('dry-run')) {
                    return;
                }
                $summary = ['summary' => ['candidate_count' => count((array) ($result['candidates'] ?? [])), 'batch_count' => count((array) ($result['batches'] ?? []))]];
                $run->forceFill([
                    'result_json' => $summary,
                    'result_hash' => hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'batch_meta_json' => null, 'coverage_json' => null, 'usage_json' => null, 'error_message' => null,
                    'diagnostic_payload_pruned_at' => now(),
                ])->save();
            }, 3);
        });
    $verb = (bool) $this->option('dry-run') ? 'Eligible' : 'Pruned';
    $this->info("{$verb} knowledge fact generation diagnostics: {$pruned}");

    return 0;
})->purpose('Prune old knowledge fact generation diagnostics without unresolved conflicts');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 */
Schedule::command('geoflow:schedule-tasks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-knowledge-syncs')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-ai-workspace')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-url-imports')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:recover-enterprise-knowledge-drafts')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:recover-self-media-batches')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

// B8 修复 — stale browser-claim 回收
// 阈值 = ManualPublicationBrowserService::STALE_AFTER_MINUTES (10) × 2 = 20 分钟
Schedule::command('geoflow:recover-browser-claims', ['--stale-after' => 20])
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

// B9 自愈 — 卡死的文章分发行：queued 重新入队 / sending 失联标记 failed（防重复发布，不自动重发）
Schedule::command('geoflow:recover-stuck-distributions')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-title-generations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:recover-knowledge-fact-generations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:reconcile-ai-usage-attempts --older-than=900')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:reconcile-ai-quality')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:poll-website-publications')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(4);

Schedule::command('geoflow:reconcile-ai-optimization')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('geoflow:converge-ai-quality')
    ->everyFiveSeconds()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:ai-quality-health --json')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(1);

Schedule::command('geoflow:prune-expired-cache')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-article-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('geoflow:prune-ai-workspace')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-knowledge-fact-generations')
    ->dailyAt('02:45')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-task-trash')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('geoflow:prune-manual-publication-trash')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('hosted-sites:reconcile', [
    '--limit' => (int) config('geoflow.hosted_sites.reconcile_limit', 500),
])
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
