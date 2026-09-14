<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DistributionRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_redispatches_stranded_queued_rows_and_fails_stale_sending_rows(): void
    {
        Queue::fake();
        config()->set('geoflow.distribution_recovery.queued_debounce_minutes', 5);
        config()->set('geoflow.distribution_recovery.sending_stale_minutes', 15);
        [$article, $firstChannel, $secondChannel] = $this->fixtures();
        $queued = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $firstChannel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'stale-queued-distribution',
            'next_retry_at' => now()->subMinute(),
        ]);
        $sending = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $secondChannel->id,
            'action' => 'publish',
            'status' => 'sending',
            'idempotency_key' => 'stale-sending-distribution',
            'last_attempt_at' => now()->subHour(),
        ]);
        DB::table('article_distributions')->where('id', $queued->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('geoflow:recover-stuck-distributions')->assertExitCode(0);

        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Queue::assertPushed(ProcessArticleDistributionJob::class, static fn (ProcessArticleDistributionJob $job): bool => in_array(
            'article-distribution:'.$queued->id,
            $job->tags(),
            true,
        ));
        $this->assertSame('queued', $queued->refresh()->status);
        $this->assertSame('failed', $sending->refresh()->status);
        $this->assertNull($sending->next_retry_at);
        $this->assertStringContainsString('远端是否已发布不确定', (string) $sending->last_error_message);
        $this->assertDatabaseHas('distribution_logs', [
            'article_distribution_id' => $queued->id,
            'event' => 'distribution.stuck_recovered',
        ]);
        $this->assertDatabaseHas('distribution_logs', [
            'article_distribution_id' => $sending->id,
            'event' => 'distribution.sending_stale_failed',
        ]);
    }

    /** @return array{Article,DistributionChannel,DistributionChannel} */
    private function fixtures(): array
    {
        $category = Category::query()->create(['name' => '回收测试分类', 'slug' => 'distribution-recovery']);
        $author = Author::query()->create(['name' => 'Recovery Tester']);
        $article = Article::query()->create([
            'title' => '分发回收测试文章',
            'slug' => 'distribution-recovery-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $channel = static fn (string $suffix): DistributionChannel => DistributionChannel::query()->create([
            'name' => '回收测试站点 '.$suffix,
            'domain' => $suffix.'.recovery.example.com',
            'endpoint_url' => 'https://'.$suffix.'.recovery.example.com/geoflow/agent',
            'status' => 'active',
        ]);

        return [$article, $channel('queued'), $channel('sending')];
    }
}
