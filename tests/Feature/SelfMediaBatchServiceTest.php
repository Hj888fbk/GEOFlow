<?php

namespace Tests\Feature;

use App\Contracts\SelfMediaContentGenerator;
use App\Jobs\GenerateSelfMediaBatchJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Models\SelfMediaPolicy;
use App\Models\Task;
use App\Models\WebsitePublicationReceipt;
use App\Services\SelfMedia\SelfMediaBatchGenerationService;
use App\Services\SelfMedia\SelfMediaBatchService;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReceiptService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SelfMediaBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_policy_records_website_receipt_without_creating_any_self_media_work(): void
    {
        $generator = $this->fakeGenerator();
        Queue::fake();
        [$admin, $task, $article] = $this->fixtures();

        app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);

        $this->assertDatabaseCount('website_publication_receipts', 1);
        $this->assertDatabaseCount('manual_publication_batches', 0);
        $this->assertDatabaseCount('manual_publications', 0);
        $this->assertSame(0, $generator->calls);
        Queue::assertNothingPushed();
        $this->assertFalse($task->selfMediaPolicy()->exists());
    }

    public function test_manual_two_platform_plan_generates_exactly_two_v2_work_orders(): void
    {
        $generator = $this->fakeGenerator();
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $library = ImageLibrary::query()->create(['name' => '自媒体图片库']);
        $image = Image::query()->create([
            'library_id' => $library->id,
            'filename' => 'cover.webp',
            'original_name' => '橡胶软接头封面.webp',
            'file_name' => 'cover.webp',
            'file_path' => 'storage/uploads/cover.webp',
            'managed_path_hash' => hash('sha256', 'storage/uploads/cover.webp'),
            'file_size' => 1024,
            'mime_type' => 'image/webp',
            'width' => 1200,
            'height' => 900,
        ]);
        ArticleImage::query()->create(['article_id' => $article->id, 'image_id' => $image->id, 'position' => 0]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);

        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA],
            $admin,
        );

        $this->assertDatabaseCount('manual_publications', 0);
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(2, $generator->calls);
        $this->assertCount(2, $batch->publications);
        $this->assertEqualsCanonicalizing(
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA],
            $batch->publications->pluck('platform')->all(),
        );
        $this->assertTrue($batch->publications->every(
            static fn (ManualPublication $publication): bool => (int) $publication->publication_payload['schema_version'] === 2,
        ));
        $this->assertSame('cover', $batch->media_manifest[0]['role']);
        $this->assertSame('/storage/uploads/cover.webp', $batch->media_manifest[0]['preview_url']);
        $this->assertTrue($batch->publications->every(
            static fn (ManualPublication $publication): bool => $publication->publication_payload['media_manifest'][0]['image_id'] > 0,
        ));
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $batch->status);
    }

    public function test_automatic_routing_is_deterministic_and_daily_limit_stops_new_batches_without_model_calls(): void
    {
        $generator = $this->fakeGenerator();
        Queue::fake();
        [$admin, $task, $first] = $this->fixtures();
        SelfMediaPolicy::query()->create([
            'task_id' => $task->id,
            'enabled' => true,
            'content_intent' => SelfMediaPlatformRouter::INTENT_ENGINEERING_DIGITAL,
            'daily_source_limit' => 1,
            'pending_batch_limit' => 2,
        ]);
        $second = $this->article($task, '第二篇官网文章');

        app(WebsitePublicationReceiptService::class)->record($first, $this->receipt($first), $admin);
        app(WebsitePublicationReceiptService::class)->record($second, $this->receipt($second), $admin);

        $this->assertDatabaseCount('website_publication_receipts', 2);
        $this->assertDatabaseCount('manual_publication_batches', 1);
        $batch = ManualPublicationBatch::query()->firstOrFail();
        $this->assertSame('deterministic_rule:engineering_digital', $batch->routing_reason);
        $this->assertSame(SelfMediaPolicy::ROUTING_VERSION, $batch->routing_version);
        $this->assertEqualsCanonicalizing([
            ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
            ManualPublicationAccount::PLATFORM_CSDN,
            ManualPublicationAccount::PLATFORM_NETEASE_MEDIA,
            ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
        ], $batch->target_platforms);
        $this->assertSame(0, $generator->calls);
        Queue::assertPushed(GenerateSelfMediaBatchJob::class, 1);
    }

    public function test_unverified_or_hash_mismatched_website_receipt_cannot_enter_the_queue(): void
    {
        [$admin, , $article] = $this->fixtures();
        $bad = $this->receipt($article);
        $bad['http_status'] = 302;

        try {
            app(WebsitePublicationReceiptService::class)->record($article, $bad, $admin);
            $this->fail('Expected failed website readback to be rejected.');
        } catch (DomainException) {
            $this->assertDatabaseCount('website_publication_receipts', 0);
        }

        $unverified = WebsitePublicationReceipt::query()->create([
            'article_id' => $article->id,
            'formal_url' => 'https://www.example.com/article/unverified',
            'http_status' => 200,
            'source_hash' => str_repeat('a', 64),
            'readback_hash' => str_repeat('a', 64),
            'readback_succeeded' => true,
            'verified_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        app(SelfMediaBatchService::class)->createManual(
            $article,
            $unverified,
            SelfMediaPlatformRouter::INTENT_SHORT_UPDATE,
            [ManualPublicationAccount::PLATFORM_WEIBO],
            $admin,
        );
    }

    public function test_same_source_and_platform_combination_is_idempotent(): void
    {
        [$admin, , $article] = $this->fixtures();
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $service = app(SelfMediaBatchService::class);
        $platforms = [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA];

        $first = $service->createManual($article, $receipt, SelfMediaPlatformRouter::INTENT_ENTERPRISE_NEWS, $platforms, $admin);
        $second = $service->createManual($article, $receipt, SelfMediaPlatformRouter::INTENT_ENTERPRISE_NEWS, array_reverse($platforms), $admin);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('manual_publication_batches', 1);
    }

    public function test_source_change_invalidates_batch_and_marks_generated_work_order_stale(): void
    {
        $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_SHORT_UPDATE,
            [ManualPublicationAccount::PLATFORM_WEIBO],
            $admin,
        );
        app(SelfMediaBatchGenerationService::class)->generate($batch);

        $article->update(['content' => '官网母稿已经修订，旧平台稿不得静默覆盖。']);

        $this->assertSame(ManualPublicationBatch::STATUS_INVALIDATED, $batch->refresh()->status);
        $this->assertNotNull($batch->publications()->firstOrFail()->source_stale_at);
    }

    public function test_pending_limit_blocks_a_third_batch_and_worker_concurrency_is_two(): void
    {
        [$admin, $task, $first] = $this->fixtures();
        $second = $this->article($task, '第二篇待审文章');
        $third = $this->article($task, '第三篇待审文章');
        $receipts = app(WebsitePublicationReceiptService::class);
        $service = app(SelfMediaBatchService::class);

        foreach ([$first, $second] as $article) {
            $receipt = $receipts->record($article, $this->receipt($article), $admin);
            $service->createManual($article, $receipt, SelfMediaPlatformRouter::INTENT_SHORT_UPDATE, [ManualPublicationAccount::PLATFORM_WEIBO], $admin);
        }

        $this->assertSame(2, config('horizon.defaults.supervisor-self-media.maxProcesses'));
        $thirdReceipt = $receipts->record($third, $this->receipt($third), $admin);
        $this->expectException(DomainException::class);
        $service->createManual($third, $thirdReceipt, SelfMediaPlatformRouter::INTENT_SHORT_UPDATE, [ManualPublicationAccount::PLATFORM_WEIBO], $admin);
    }

    public function test_disabling_auto_policy_stops_new_batches_but_keeps_website_receipts_running(): void
    {
        Queue::fake();
        [$admin, $task, $first] = $this->fixtures();
        $policy = SelfMediaPolicy::query()->create([
            'task_id' => $task->id,
            'enabled' => true,
            'content_intent' => SelfMediaPlatformRouter::INTENT_SHORT_UPDATE,
        ]);
        app(WebsitePublicationReceiptService::class)->record($first, $this->receipt($first), $admin);
        $policy->update(['enabled' => false]);
        $second = $this->article($task, '关闭自动模式后的官网文章');
        app(WebsitePublicationReceiptService::class)->record($second, $this->receipt($second), $admin);

        $this->assertDatabaseCount('website_publication_receipts', 2);
        $this->assertDatabaseCount('manual_publication_batches', 1);
        Queue::assertPushed(GenerateSelfMediaBatchJob::class, 1);
    }

    public function test_expired_generation_lease_is_released_for_safe_manual_retry(): void
    {
        [$admin, , $article] = $this->fixtures();
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_SHORT_UPDATE,
            [ManualPublicationAccount::PLATFORM_WEIBO],
            $admin,
        );
        $batch->forceFill([
            'status' => ManualPublicationBatch::STATUS_GENERATING,
            'execution_lease_token' => '018f0c10-8b31-7a22-8da7-111111111111',
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('geoflow:recover-self-media-batches', ['--limit' => 10])
            ->expectsOutput('Recovered expired self-media batches: 1')
            ->assertExitCode(0);

        $batch->refresh();
        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $batch->status);
        $this->assertNull($batch->execution_lease_token);
        $this->assertNull($batch->lease_expires_at);
        $this->assertArrayHasKey('_batch', $batch->generation_errors);
    }

    public function test_self_media_planning_page_is_readable_only_by_super_admin(): void
    {
        [$superAdmin] = $this->fixtures();
        $worker = Admin::query()->create([
            'username' => uniqid('self_media_worker_'),
            'password' => 'secret-123',
            'email' => uniqid('self-media-worker-').'@example.com',
            'display_name' => 'Self Media Worker',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.self-media.index'))
            ->assertOk()
            ->assertSee('候选列表不会生成平台稿')
            ->assertSee('0');
        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.self-media.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_save_task_policy_with_an_explicit_platform_override(): void
    {
        [$admin, $task] = $this->fixtures();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.manual-publications.self-media.policies.update', ['taskId' => $task->id]), [
                'enabled' => '1',
                'content_intent' => SelfMediaPlatformRouter::INTENT_ENGINEERING_DIGITAL,
                'platform_override' => [
                    ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                    ManualPublicationAccount::PLATFORM_CSDN,
                ],
                'daily_source_limit' => 1,
                'pending_batch_limit' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $policy = $task->selfMediaPolicy()->firstOrFail();
        $this->assertTrue($policy->enabled);
        $this->assertSame(SelfMediaPolicy::ROUTING_VERSION, $policy->routing_version);
        $this->assertSame(
            [ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN, ManualPublicationAccount::PLATFORM_CSDN],
            $policy->platform_override,
        );
    }

    public function test_failed_platform_generation_can_retry_without_regenerating_successful_platforms(): void
    {
        $generator = new class implements SelfMediaContentGenerator
        {
            public int $calls = 0;

            public bool $failSohuOnce = true;

            public function generateAndPersist(
                ManualPublicationBatch $batch,
                string $platform,
                \Closure $persistVariant,
            ): mixed {
                $this->calls++;
                if ($platform === ManualPublicationAccount::PLATFORM_SOHU_MEDIA && $this->failSohuOnce) {
                    $this->failSohuOnce = false;
                    throw new DomainException('搜狐号临时生成失败');
                }

                return $persistVariant([
                    'title' => $platform.'标题',
                    'summary' => '摘要',
                    'body_plain' => $platform.'正文',
                    'body_markdown' => $platform.'正文',
                    'body_html' => null,
                    'tags' => [],
                ], null);
            }
        };
        $this->app->instance(SelfMediaContentGenerator::class, $generator);
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA],
            $admin,
        );

        $firstAttempt = app(SelfMediaBatchGenerationService::class)->generate($batch);
        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $firstAttempt->status);
        $this->assertCount(1, $firstAttempt->publications);
        $this->assertArrayHasKey(ManualPublicationAccount::PLATFORM_SOHU_MEDIA, $firstAttempt->generation_errors);

        $secondAttempt = app(SelfMediaBatchGenerationService::class)->generate($firstAttempt);
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $secondAttempt->status);
        $this->assertCount(2, $secondAttempt->publications);
        $this->assertNull($secondAttempt->generation_errors);
        $this->assertSame(3, $generator->calls);
    }

    public function test_admin_generate_action_queues_work_without_running_the_model_inline(): void
    {
        $generator = $this->fakeGenerator();
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.self-media.batches.generate', ['batchId' => $batch->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateSelfMediaBatchJob::class, fn (GenerateSelfMediaBatchJob $job): bool => $job->batchId === $batch->id);
        $this->assertSame(0, $generator->calls);
        $this->assertSame(ManualPublicationBatch::STATUS_PLANNED, $batch->refresh()->status);
    }

    public function test_approval_binds_an_account_configured_after_generation_and_refreshes_payload(): void
    {
        $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);
        $this->assertNull($batch->publications->firstOrFail()->account_id);

        $profileUrl = 'https://baijiahao.baidu.com/bjournal/profile/hengjia';
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'account_name' => '恒佳百家号',
            'profile_url' => $profileUrl,
            'editor_url' => 'https://baijiahao.baidu.com/builder/rc/edit',
            'browser_adapter_enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.self-media.batches.approve', ['batchId' => $batch->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $publication = $batch->publications()->firstOrFail();
        $this->assertSame(ManualPublication::STATUS_READY, $publication->status);
        $this->assertSame($account->id, $publication->account_id);
        $this->assertSame($account->editor_url, $publication->target_url);
        $this->assertSame(
            hash('sha256', strtolower(rtrim($profileUrl, '/'))),
            $publication->publication_payload['account_verification']['expected_profile_hash'],
        );
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_PLATFORM, $batch->refresh()->status);
    }

    public function test_generation_rejects_numbers_outside_the_frozen_fact_constraints(): void
    {
        $this->app->instance(SelfMediaContentGenerator::class, new class implements SelfMediaContentGenerator
        {
            public function generateAndPersist(
                ManualPublicationBatch $batch,
                string $platform,
                \Closure $persistVariant,
            ): mixed {
                return $persistVariant([
                    'title' => '擅自增加参数的平台稿',
                    'summary' => '摘要',
                    'body_plain' => '工作压力可达 2.5MPa。',
                    'body_markdown' => '工作压力可达 2.5MPa。',
                    'body_html' => '<p>工作压力可达 2.5MPa。</p>',
                    'tags' => [],
                ], null);
            }
        });
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );

        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, $batch->status);
        $this->assertCount(0, $batch->publications);
        $this->assertStringContainsString('2.5mpa', strtolower((string) $batch->generation_errors[ManualPublicationAccount::PLATFORM_BAIJIAHAO]));
    }

    public function test_approval_failure_rolls_back_all_work_order_transitions(): void
    {
        $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA],
            $admin,
        );
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);
        ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'account_name' => '恒佳百家号',
            'profile_url' => 'https://baijiahao.baidu.com/bjournal/profile/hengjia',
            'editor_url' => 'https://baijiahao.baidu.com/builder/rc/edit',
            'browser_adapter_enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.self-media.batches.approve', ['batchId' => $batch->id]))
            ->assertSessionHasErrors(['approval']);

        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $batch->refresh()->status);
        $this->assertSame(0, $batch->publications()->where('status', '!=', ManualPublication::STATUS_DRAFT)->count());
        $this->assertSame(0, $batch->publications()->whereNotNull('account_id')->count());
    }

    private function fakeGenerator(): object
    {
        $generator = new class implements SelfMediaContentGenerator
        {
            public int $calls = 0;

            public function generateAndPersist(
                ManualPublicationBatch $batch,
                string $platform,
                \Closure $persistVariant,
            ): mixed {
                $this->calls++;

                return $persistVariant([
                    'title' => $platform.'平台标题',
                    'summary' => '平台摘要',
                    'body_plain' => $platform.'平台正文',
                    'body_markdown' => '## '.$platform."\n\n平台正文",
                    'body_html' => '<h2>'.$platform.'</h2><p>平台正文</p>',
                    'tags' => ['橡胶软接头', '恒佳'],
                ], null);
            }
        };
        $this->app->instance(SelfMediaContentGenerator::class, $generator);

        return $generator;
    }

    /** @return array{Admin,Task,Article} */
    private function fixtures(): array
    {
        $admin = Admin::query()->create([
            'username' => uniqid('self_media_'),
            'password' => 'secret-123',
            'email' => uniqid('self-media-').'@example.com',
            'display_name' => 'Self Media Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $task = Task::query()->create(['name' => '官网生产任务', 'status' => 'active']);

        return [$admin, $task, $this->article($task, '官网正式文章')];
    }

    private function article(Task $task, string $title): Article
    {
        $category = Category::query()->create(['name' => uniqid('分类'), 'slug' => uniqid('self-media-category-')]);
        $author = Author::query()->create(['name' => uniqid('作者')]);

        return Article::query()->create([
            'title' => $title,
            'slug' => uniqid('self-media-article-'),
            'excerpt' => '正式摘要',
            'content' => '橡胶软接头安装维护正文，允许数字 1.6MPa。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function receipt(Article $article): array
    {
        $hash = app(SelfMediaSourceHasher::class)->hash($article);

        return [
            'receipt_id' => uniqid('HJ-WEB-'),
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => 'https://www.example.com/article/'.$article->slug,
            'http_status' => 200,
            'source_hash' => $hash,
            'readback_hash' => $hash,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
