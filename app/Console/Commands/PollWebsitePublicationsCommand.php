<?php

namespace App\Console\Commands;

use App\Models\ArticleDistribution;
use App\Services\SelfMedia\WebsitePublicationReadbackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 轮询 WordPress 渠道的已同步分发：草稿在官网被人工发布后，自动完成回读并提交官网回执，
 * 使文章无需人工介入即可进入自媒体可分发列表（触发任务级自动批次）。
 */
final class PollWebsitePublicationsCommand extends Command
{
    protected $signature = 'geoflow:poll-website-publications {--limit=20}';

    protected $description = 'Poll WordPress distributions for published posts and submit website receipts automatically';

    public function handle(WebsitePublicationReadbackService $readback): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        // 每篇文章只取最新一条发布分发，避免历史 update 记录重复触发。
        $candidates = ArticleDistribution::query()
            ->with(['channel', 'article.task'])
            ->where('action', 'publish')
            ->where('status', 'synced')
            ->whereNotNull('remote_id')
            ->where('remote_id', '!=', '')
            ->whereHas('channel', function ($query): void {
                $query->where('channel_type', 'wordpress_rest')
                    ->where('status', 'active');
            })
            ->orderByDesc('updated_at')
            ->limit($limit * 3)
            ->get()
            ->unique('article_id')
            ->take($limit);

        $submitted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $distribution) {
            try {
                $receipt = $readback->attemptReceipt($distribution);
                if ($receipt !== null) {
                    $submitted++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $this->info(sprintf(
            'website publications polled: %d submitted, %d pending, %d failed',
            $submitted,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
