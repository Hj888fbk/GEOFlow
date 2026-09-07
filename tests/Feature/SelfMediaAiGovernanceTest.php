<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Models\Task;
use App\Services\SelfMedia\SelfMediaBatchGenerationService;
use App\Services\SelfMedia\SelfMediaBatchService;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReceiptService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SelfMediaAiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_generation_uses_frozen_admin_identity_and_records_batch_scoped_usage(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion()),
        ]);
        [$owner, , $article] = $this->fixtures();
        $batch = $this->batch($article, $owner);

        $result = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $result->status);
        $this->assertCount(1, $result->publications);
        $this->assertNull($result->execution_lease_token);
        $this->assertNull($result->lease_expires_at);
        $this->assertDatabaseCount('task_runs', 0);
        $usage = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usage->status);
        $this->assertSame('self_media_platform_generation', $usage->business_source);
        $this->assertSame('self_media.generate', $usage->operation);
        $this->assertSame(ManualPublicationBatch::class, $usage->source_type);
        $this->assertSame((string) $batch->id, (string) $usage->source_id);
        $this->assertSame((int) $owner->id, (int) $usage->execution_admin_id);
    }

    public function test_revoked_admin_configuration_stops_before_provider_and_persists_no_platform_draft(): void
    {
        Http::fake();
        [$owner, , $article] = $this->fixtures();
        $batch = $this->batch($article, $owner);
        Admin::query()->whereKey($owner->id)->increment('ai_config_access_version');

        $result = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $result->status);
        $this->assertCount(0, $result->publications);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        Http::assertNothingSent();
    }

    public function test_model_configuration_change_after_provider_response_revokes_result_before_persistence(): void
    {
        [$owner, $model, $article] = $this->fixtures();
        Http::fake(function () use ($model) {
            AiModel::query()->whereKey($model->id)->update(['version' => 'changed-after-response']);

            return Http::response($this->completion());
        });
        $batch = $this->batch($article, $owner);

        $result = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $result->status);
        $this->assertCount(0, $result->publications);
        $usage = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $usage->status);
        $this->assertDatabaseCount('manual_publications', 0);
    }

    public function test_persistence_failure_discards_provider_result_and_keeps_no_partial_draft(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion()),
        ]);
        [$owner, , $article] = $this->fixtures();
        $actor = Admin::query()->create([
            'username' => uniqid('self_media_actor_'),
            'password' => 'safe-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $batch = $this->batch($article, $actor);
        $actor->delete();

        $result = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $result->status);
        $this->assertCount(0, $result->publications);
        $usage = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $usage->status);
        $this->assertSame('ai_result_persistence_failed', $usage->error_code);
        $this->assertSame((int) $owner->id, (int) $usage->execution_admin_id);
    }

    /** @return array{Admin,AiModel,Article} */
    private function fixtures(): array
    {
        $owner = Admin::query()->create([
            'username' => uniqid('self_media_owner_'),
            'password' => 'safe-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Self Media Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task = Task::query()->create([
            'name' => '官网生产任务',
            'ai_model_id' => $model->id,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $owner->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $category = Category::query()->create(['name' => uniqid('分类'), 'slug' => uniqid('self-media-category-')]);
        $author = Author::query()->create(['name' => uniqid('作者')]);
        $article = Article::query()->create([
            'title' => '官网正式文章',
            'slug' => uniqid('self-media-ai-'),
            'excerpt' => '正式摘要',
            'content' => '橡胶软接头安装维护正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);

        return [$owner, $model, $article];
    }

    private function batch(Article $article, Admin $actor): ManualPublicationBatch
    {
        $hash = app(SelfMediaSourceHasher::class)->hash($article);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, [
            'receipt_id' => uniqid('HJ-WEB-'),
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => 'https://www.example.com/article/'.$article->slug,
            'http_status' => 200,
            'source_hash' => $hash,
            'readback_hash' => $hash,
            'verified_at' => now()->toIso8601String(),
        ], $actor);

        return app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $actor,
        );
    }

    /** @return array<string,mixed> */
    private function completion(): array
    {
        return [
            'model' => 'test-chat-model',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'title' => '百家号标题',
                        'summary' => '百家号摘要',
                        'body_plain' => '橡胶软接头安装维护正文。',
                        'body_markdown' => '橡胶软接头安装维护正文。',
                        'body_html' => '<p>橡胶软接头安装维护正文。</p>',
                        'tags' => ['橡胶软接头'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }
}
