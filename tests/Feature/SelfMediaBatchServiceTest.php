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
use App\Services\SelfMedia\ManualPublicationLifecycleService;
use App\Services\SelfMedia\SelfMediaBatchGenerationService;
use App\Services\SelfMedia\SelfMediaBatchService;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReceiptService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SelfMediaBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

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

    public function test_manual_two_platform_plan_generates_exactly_two_v3_work_orders(): void
    {
        $generator = $this->fakeGenerator();
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $library = ImageLibrary::query()->create(['name' => '自媒体图片库']);
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('uploads/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $image = Image::query()->create([
            'library_id' => $library->id,
            'filename' => 'cover.png',
            'original_name' => '橡胶软接头封面.png',
            'file_name' => 'cover.png',
            'file_path' => 'storage/uploads/cover.png',
            'managed_path_hash' => hash('sha256', 'storage/uploads/cover.png'),
            'file_size' => 1024,
            'mime_type' => 'image/png',
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
        $this->assertCount(2, $batch->publications, json_encode($batch->generation_errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'no generation errors');
        $this->assertEqualsCanonicalizing(
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO, ManualPublicationAccount::PLATFORM_SOHU_MEDIA],
            $batch->publications->pluck('platform')->all(),
        );
        $this->assertTrue($batch->publications->every(
            static fn (ManualPublication $publication): bool => (int) $publication->publication_payload['schema_version'] === 3,
        ));
        $this->assertSame('body', $batch->media_manifest[0]['role']);
        $this->assertTrue($batch->media_manifest[0]['is_cover']);
        $this->assertNotEmpty($batch->media_manifest[0]['media_key']);
        $this->assertTrue($batch->publications->every(
            static fn (ManualPublication $publication): bool => str_contains($publication->publication_payload['media_manifest'][0]['download_path'], (string) $publication->id),
        ));
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $batch->status);
    }

    public function test_manual_plan_accepts_the_complete_ten_platform_draft_sync_set_without_generating_content(): void
    {
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.self-media.batches.store'), [
                'website_publication_receipt_id' => $receipt->id,
                'content_intent' => SelfMediaPlatformRouter::INTENT_ENTERPRISE_NEWS,
                'platforms' => ManualPublicationAccount::DRAFT_SYNC_PLATFORMS,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ManualPublicationBatch::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS, $batch->target_platforms);
        $this->assertDatabaseCount('manual_publications', 0);
        Queue::assertNothingPushed();
    }

    public function test_routing_v2_uses_only_the_ten_platform_scope_while_v1_keeps_legacy_weibo_rules(): void
    {
        $router = app(SelfMediaPlatformRouter::class);
        $v2 = $router->route(SelfMediaPlatformRouter::INTENT_ENTERPRISE_NEWS, null, SelfMediaPolicy::ROUTING_VERSION);
        $v1 = $router->route(SelfMediaPlatformRouter::INTENT_ENTERPRISE_NEWS, null, SelfMediaPolicy::LEGACY_ROUTING_VERSION);

        $this->assertContains(ManualPublicationAccount::PLATFORM_TOUTIAO, $v2['platforms']);
        $this->assertNotContains(ManualPublicationAccount::PLATFORM_WEIBO, $v2['platforms']);
        $this->assertContains(ManualPublicationAccount::PLATFORM_WEIBO, $v1['platforms']);
        $this->assertNotContains(ManualPublicationAccount::PLATFORM_TOUTIAO, $v1['platforms']);
    }

    public function test_body_markdown_html_and_attachment_images_are_frozen_in_body_order_and_deduplicated(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$admin, , $article] = $this->fixtures();
        $library = ImageLibrary::query()->create(['name' => '正文图片库']);
        $base = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $images = [];
        foreach (['a', 'b', 'c'] as $index => $name) {
            $bytes = $base.str_repeat("\0", $index + 1);
            Storage::disk('public')->put('uploads/'.$name.'.png', $bytes);
            $images[$name] = Image::query()->create([
                'library_id' => $library->id,
                'filename' => $name.'.png',
                'original_name' => strtoupper($name).'.png',
                'file_name' => $name.'.png',
                'file_path' => 'storage/uploads/'.$name.'.png',
                'managed_path_hash' => hash('sha256', 'storage/uploads/'.$name.'.png'),
                'file_size' => strlen($bytes),
                'mime_type' => 'image/png',
                'width' => 1,
                'height' => 1,
            ]);
            ArticleImage::query()->create(['article_id' => $article->id, 'image_id' => $images[$name]->id, 'position' => $index]);
        }
        $article->update(['content' => <<<'MD'
正文开始。

![B图](/storage/uploads/b.png)

![A图](/storage/uploads/a.png)

![重复B](/storage/uploads/b.png)

<img src="/storage/uploads/c.png" alt="C图">
MD]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article->fresh(), $this->receipt($article->fresh()), $admin);

        $batch = app(SelfMediaBatchService::class)->createManual(
            $article->fresh(),
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );

        $this->assertCount(3, $batch->media_manifest);
        $this->assertSame(['B图', 'A图', 'C图'], array_column($batch->media_manifest, 'name'));
        $this->assertTrue($batch->media_manifest[0]['is_cover']);
        $this->assertSame(['body_markdown', 'body_markdown', 'body_html'], array_map(
            static fn ($snapshot): string => $snapshot->source_type,
            $batch->mediaSnapshots()->orderBy('position')->get()->all(),
        ));
        $this->assertStringContainsString('{{media:'.$batch->media_manifest[0]['media_key'].'}}', $batch->source_snapshot['content']);
        $this->assertDatabaseCount('self_media_media_snapshots', 3);
    }

    public function test_missing_article_image_relation_is_logged_and_counted_in_batch_snapshot(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Log::spy();
        [$admin, , $article] = $this->fixtures();
        $library = ImageLibrary::query()->create(['name' => '缺图图片库']);
        Storage::disk('public')->put('uploads/ok.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $image = Image::query()->create([
            'library_id' => $library->id,
            'filename' => 'ok.png',
            'original_name' => '正常图.png',
            'file_name' => 'ok.png',
            'file_path' => 'storage/uploads/ok.png',
            'managed_path_hash' => hash('sha256', 'storage/uploads/ok.png'),
            'file_size' => 68,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
        ]);
        ArticleImage::query()->create(['article_id' => $article->id, 'image_id' => $image->id, 'position' => 0]);
        // 构造“配图关联还在、图片记录已丢失”的孤儿数据：article_images 的外键不允许
        // 直接删除图片行，因此在内存里挂一个 image 为 null 的关联来模拟。
        $phantom = new ArticleImage(['article_id' => $article->id, 'image_id' => 424242, 'position' => 1]);
        $phantom->id = 424242;
        $phantom->exists = true;
        $phantom->setRelation('image', null);
        $article->update(['content' => "正文开始。\n\n![正常图](/storage/uploads/ok.png)"]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article->fresh(), $this->receipt($article->fresh()), $admin);

        $articleWithMedia = $article->fresh();
        $articleWithMedia->setRelation('articleImages', collect([
            ArticleImage::query()->where('article_id', $article->id)->firstOrFail()->setRelation('image', $image),
            $phantom,
        ]));

        $batch = app(SelfMediaBatchService::class)->createManual(
            $articleWithMedia,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );

        $this->assertCount(1, $batch->media_manifest);
        $this->assertSame(1, (int) data_get($batch->source_snapshot, 'media_freeze.missing_image_relations'));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '图片记录缺失')
                && (int) ($context['article_id'] ?? 0) === (int) $article->id
                && (int) ($context['article_image_id'] ?? 0) === 424242
                && (int) ($context['manual_publication_batch_id'] ?? 0) === (int) $batch->id,
        );
    }

    public function test_batch_creation_rolls_back_when_body_image_cannot_be_frozen(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$admin, , $article] = $this->fixtures();
        $library = ImageLibrary::query()->create(['name' => '坏图图片库']);
        Storage::disk('public')->put('uploads/broken.png', 'this-is-not-an-image');
        $image = Image::query()->create([
            'library_id' => $library->id,
            'filename' => 'broken.png',
            'original_name' => '坏图.png',
            'file_name' => 'broken.png',
            'file_path' => 'storage/uploads/broken.png',
            'managed_path_hash' => hash('sha256', 'storage/uploads/broken.png'),
            'file_size' => 19,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
        ]);
        ArticleImage::query()->create(['article_id' => $article->id, 'image_id' => $image->id, 'position' => 0]);
        $article->update(['content' => "正文开始。\n\n![坏图](/storage/uploads/broken.png)"]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article->fresh(), $this->receipt($article->fresh()), $admin);

        try {
            app(SelfMediaBatchService::class)->createManual(
                $article->fresh(),
                $receipt,
                SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
                [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
                $admin,
            );
            $this->fail('Expected an unreadable body image to abort batch creation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('正文图片', $exception->getMessage());
        }

        $this->assertDatabaseCount('manual_publication_batches', 0);
        $this->assertDatabaseCount('self_media_media_snapshots', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('self-media'));
    }

    public function test_cancelled_batch_is_revived_when_same_plan_is_created_again(): void
    {
        $this->fakeGenerator();
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        ManualPublicationPersona::query()->create(['name' => '恒佳企业发布身份']);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $service = app(SelfMediaBatchService::class);

        $batch = $service->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );
        $batch->forceFill(['status' => ManualPublicationBatch::STATUS_CANCELLED])->save();

        $revived = $service->createManual(
            $article,
            $receipt->fresh(),
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            $admin,
        );

        $this->assertSame((int) $batch->id, (int) $revived->id);
        $this->assertSame(ManualPublicationBatch::STATUS_PLANNED, $revived->status);
    }

    public function test_body_html_is_derived_from_markdown_when_generator_returns_null(): void
    {
        $generator = new class implements SelfMediaContentGenerator
        {
            public function generateAndPersist(
                ManualPublicationBatch $batch,
                string $platform,
                \Closure $persistVariant,
            ): mixed {
                return $persistVariant([
                    'title' => $platform.'平台标题',
                    'summary' => '平台摘要',
                    'body_plain' => '平台正文纯文本',
                    'body_markdown' => "## 平台小节\n\n平台**加粗**正文",
                    'body_html' => null,
                    'tags' => ['橡胶软接头'],
                ], null);
            }
        };
        $this->app->instance(SelfMediaContentGenerator::class, $generator);
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
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $publication = $batch->publications->firstWhere('platform', ManualPublicationAccount::PLATFORM_BAIJIAHAO);
        $this->assertNotNull($publication);
        // body_html 必须由服务端从 markdown 确定性渲染并保留结构标签。
        $this->assertNotNull($publication->body_html);
        $this->assertStringContainsString('<h2>', (string) $publication->body_html);
        $this->assertStringContainsString('<strong>加粗</strong>', (string) $publication->body_html);
        $this->assertStringNotContainsString('```markdown', (string) $publication->body_html);
        $this->assertSame(
            $publication->body_html,
            $publication->publication_payload['body_html'],
        );
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
            ManualPublicationAccount::PLATFORM_JIANSHU,
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
        $this->assertSame(hash('sha256', implode('|', [
            (string) $article->id,
            app(SelfMediaSourceHasher::class)->hash($article),
            (string) $first->platform_combination_hash,
        ])), $first->idempotency_hash);
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
            ->assertRedirect(route('admin.manual-publications.index', ['view' => 'launch']));
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

    public function test_one_launch_creates_a_multi_account_batch_and_queues_generation(): void
    {
        Queue::fake();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '统一发布身份']);
        $accounts = collect(['账号一', '账号二'])->map(fn (string $name) => ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => $name,
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => $name,
            'browser_adapter_enabled' => true,
        ]));
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);

        $this->actingAs($admin, 'admin')->post(route('admin.manual-publications.self-media.launch'), [
            'website_publication_receipt_id' => $receipt->id,
            'persona_id' => $persona->id,
            'content_intent' => SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            'account_ids' => $accounts->pluck('id')->all(),
        ])->assertRedirect(route('admin.manual-publications.index', ['view' => 'pending', 'batch' => 1]))
            ->assertSessionHasNoErrors();

        $batch = ManualPublicationBatch::query()->firstOrFail();
        $this->assertSame($persona->id, $batch->persona_id);
        $this->assertEqualsCanonicalizing($accounts->pluck('id')->all(), $batch->target_account_ids);
        $this->assertSame(hash('sha256', $accounts->pluck('id')->sort()->implode('|')), $batch->account_selection_hash);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'manual_publication_batch.launched',
            'target_type' => 'manual_publication_batch',
            'target_id' => $batch->id,
        ]);
        Queue::assertPushed(GenerateSelfMediaBatchJob::class, fn (GenerateSelfMediaBatchJob $job): bool => $job->batchId === $batch->id);
    }

    public function test_one_platform_variant_is_reused_for_multiple_selected_accounts(): void
    {
        $generator = $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '多账号身份']);
        $accounts = collect(['CSDN A', 'CSDN B'])->map(fn (string $name) => ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => $name,
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => $name,
            'browser_adapter_enabled' => true,
        ]));
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [],
            $admin,
            $persona->id,
            $accounts->pluck('id')->all(),
        );

        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);

        $this->assertSame(1, $generator->calls);
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $batch->status);
        $this->assertCount(2, $batch->publications);
        $this->assertEqualsCanonicalizing($accounts->pluck('id')->all(), $batch->publications->pluck('account_id')->all());
        $this->assertCount(1, $batch->publications->pluck('body_markdown')->unique());
    }

    public function test_approval_never_replaces_a_selected_account_with_another_account(): void
    {
        $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '固定账号身份']);
        $selected = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => '已选择账号',
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => 'selected-account',
            'browser_adapter_enabled' => true,
        ]);
        $fallback = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => '不应替换的账号',
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => 'fallback-account',
            'browser_adapter_enabled' => true,
        ]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [],
            $admin,
            $persona->id,
            [$selected->id],
        );
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);
        $selected->update(['browser_adapter_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.self-media.batches.approve', ['batchId' => $batch->id]))
            ->assertSessionHasErrors(['approval']);

        $publication = $batch->publications()->firstOrFail();
        $this->assertSame($selected->id, $publication->account_id);
        $this->assertNotSame($fallback->id, $publication->account_id);
        $this->assertSame(ManualPublication::STATUS_DRAFT, $publication->status);
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_REVIEW, $batch->refresh()->status);
    }

    public function test_generated_platform_draft_can_be_edited_inside_the_batch_before_approval(): void
    {
        $this->fakeGenerator();
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '审核编辑身份']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => '审核编辑账号',
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => 'review-editor',
            'browser_adapter_enabled' => true,
        ]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual(
            $article,
            $receipt,
            SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION,
            [],
            $admin,
            $persona->id,
            [$account->id],
        );
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);
        $publication = $batch->publications->firstOrFail();
        $body = str_replace('平台正文', '审核后平台正文', (string) $publication->body_markdown);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.manual-publications.self-media.batches.publications.update', [
                'batchId' => $batch->id,
                'manualPublicationId' => $publication->id,
            ]), [
                'revision' => $publication->revision,
                'platform_title' => '审核后的平台标题',
                'platform_summary' => '审核后的摘要',
                'body_markdown' => $body,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $publication->refresh();
        $this->assertSame('审核后的平台标题', $publication->platform_title);
        $this->assertSame('审核后的平台标题', $publication->publication_payload['title']);
        $this->assertSame($body, $publication->body_markdown);
        $this->assertSame(2, $publication->revision);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'manual_publication_batch.draft_updated',
            'target_type' => 'manual_publication',
            'target_id' => $publication->id,
        ]);
    }

    public function test_batch_trash_restore_invalidation_and_thirty_day_pruning_are_atomic(): void
    {
        $this->fakeGenerator();
        Storage::fake('local');
        [$admin, , $article] = $this->fixtures();
        $persona = ManualPublicationPersona::query()->create(['name' => '回收站身份']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_CSDN,
            'account_name' => '回收站账号',
            'editor_url' => ManualPublicationAccount::editorUrlPresets()[ManualPublicationAccount::PLATFORM_CSDN],
            'homepage_identifier' => 'trash-account',
            'browser_adapter_enabled' => true,
        ]);
        $receipt = app(WebsitePublicationReceiptService::class)->record($article, $this->receipt($article), $admin);
        $batch = app(SelfMediaBatchService::class)->createManual($article, $receipt, SelfMediaPlatformRouter::INTENT_PRODUCT_EDUCATION, [], $admin, $persona->id, [$account->id]);
        $batch = app(SelfMediaBatchGenerationService::class)->generate($batch);
        $publicationId = $batch->publications->firstOrFail()->id;
        $lifecycle = app(ManualPublicationLifecycleService::class);

        $lifecycle->trashBatch($batch->id);
        $this->assertTrue(ManualPublicationBatch::withTrashed()->findOrFail($batch->id)->trashed());
        $this->assertTrue(ManualPublication::withTrashed()->findOrFail($publicationId)->trashed());

        $article->forceFill(['content' => $article->content.' 母稿已修改'])->save();
        $restored = $lifecycle->restoreBatch($batch->id);
        $this->assertSame(ManualPublicationBatch::STATUS_INVALIDATED, $restored->status);
        $this->assertNotNull(ManualPublication::query()->findOrFail($publicationId)->source_stale_at);

        $lifecycle->trashBatch($batch->id);
        $expiredAt = now()->subDays(31);
        $managedMediaPath = 'self-media/'.$batch->id.'/m_expired.jpg';
        Storage::disk('local')->put($managedMediaPath, 'expired managed media');
        Storage::disk('local')->assertExists($managedMediaPath);
        ManualPublicationBatch::onlyTrashed()->whereKey($batch->id)->update(['deleted_at' => $expiredAt]);
        ManualPublication::onlyTrashed()->whereKey($publicationId)->update(['deleted_at' => $expiredAt]);
        $pruned = $lifecycle->pruneExpired();
        $this->assertSame(['batches' => 1, 'publications' => 1], $pruned);
        $this->assertNull(ManualPublicationBatch::withTrashed()->find($batch->id));
        $this->assertNull(ManualPublication::withTrashed()->find($publicationId));
        Storage::disk('local')->assertMissing($managedMediaPath);
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

                $mediaNodes = array_map(
                    static fn (array $item): string => '{{media:'.(string) $item['media_key'].'}}',
                    array_values((array) $batch->media_manifest),
                );

                return $persistVariant([
                    'title' => $platform.'平台标题',
                    'summary' => '平台摘要',
                    'body_plain' => $platform.'平台正文',
                    'body_markdown' => '## '.$platform."\n\n平台正文".($mediaNodes === [] ? '' : "\n\n".implode("\n\n", $mediaNodes)),
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
