<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\WordPressMediaSyncService;
use App\Services\GeoFlow\WordPressRestPublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class WordPressMediaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uploads_base64_image_asset_to_wordpress_media(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
        ]);

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            $this->payloadWithImage(),
            '<p><img src="/storage/uploads/images/demo.png" alt="demo"></p>'
        );

        $this->assertStringContainsString('https://wp.example.com/wp-content/uploads/image.jpg', $html);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://wp.example.com/wp-json/wp/v2/media'
                && $request->hasHeader('Content-Disposition', 'attachment; filename="demo.png"')
                && $request->body() === 'image-bytes';
        });
    }

    public function test_it_rewrites_content_html_image_src_to_wordpress_media_url(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
        ]);

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            $this->payloadWithImage(),
            '<figure><img src="/storage/uploads/images/demo.png"></figure>'
        );

        $this->assertSame('<figure><img src="https://wp.example.com/wp-content/uploads/image.jpg"></figure>', $html);
    }

    public function test_it_keeps_original_src_when_asset_has_no_base64_content(): void
    {
        Http::fake();

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            [
                'assets' => [
                    'images' => [
                        ['source_url' => 'https://cdn.example.com/image.jpg', 'filename' => 'image.jpg'],
                    ],
                ],
            ],
            '<p><img src="https://cdn.example.com/image.jpg"></p>'
        );

        $this->assertSame('<p><img src="https://cdn.example.com/image.jpg"></p>', $html);
        Http::assertNothingSent();
    }

    public function test_it_skips_upload_when_image_strategy_is_keep_original(): void
    {
        Http::fake();

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(['wordpress_image_strategy' => 'keep_original']),
            $this->payloadWithImage(),
            '<p><img src="/storage/uploads/images/demo.png"></p>'
        );

        $this->assertSame('<p><img src="/storage/uploads/images/demo.png"></p>', $html);
        Http::assertNothingSent();
    }

    public function test_publisher_uses_uploaded_media_url_in_post_content(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello/',
            ], 201),
        ]);

        $channel = $this->makeChannel();
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $this->makeArticleId(),
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'wp-media-test',
        ]);

        app(WordPressRestPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Hello',
                'slug' => 'hello',
                'excerpt' => '',
                'content_html' => '<p><img src="/storage/uploads/images/demo.png"></p>',
                'keywords' => '',
            ],
            'assets' => $this->payloadWithImage()['assets'],
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
            && $request['content'] === '<p><img src="https://wp.example.com/wp-content/uploads/image.jpg"></p>');
    }

    public function test_it_logs_warning_when_asset_has_no_base64_content(): void
    {
        Log::spy();
        Http::fake();

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            [
                'article' => ['id' => 42],
                'assets' => [
                    'images' => [
                        ['source_url' => 'https://cdn.example.com/image.jpg', 'filename' => 'image.jpg'],
                    ],
                ],
            ],
            '<p><img src="https://cdn.example.com/image.jpg"></p>'
        );

        $this->assertSame('<p><img src="https://cdn.example.com/image.jpg"></p>', $html);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '裂图')
                && ($context['source_url'] ?? null) === 'https://cdn.example.com/image.jpg'
                && (int) ($context['article_id'] ?? 0) === 42
                && ($context['skip_reason'] ?? null) === 'missing_content_base64',
        );
    }

    public function test_it_logs_error_before_throwing_when_media_upload_fails(): void
    {
        Log::spy();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response('Server Error', 500),
        ]);

        try {
            app(WordPressMediaSyncService::class)->rewriteContentImages(
                $this->makeChannel(),
                $this->payloadWithImage(),
                '<p><img src="/storage/uploads/images/demo.png"></p>'
            );
            $this->fail('Expected media upload failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '媒体上传失败')
                && (int) ($context['http_status'] ?? 0) === 500
                && ($context['source_url'] ?? null) === '/storage/uploads/images/demo.png'
                && ($context['filename'] ?? null) === 'demo.png',
        );
    }

    public function test_it_reuses_mapped_media_from_distribution_remote_meta_without_uploading(): void
    {
        Http::fake();
        $channel = $this->makeChannel();
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $this->makeArticleId(),
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'sending',
            'idempotency_key' => 'wp-media-reuse',
            'remote_meta' => [
                'wp_media_map' => [
                    hash('sha256', 'image-bytes') => [
                        'id' => 456,
                        'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
                    ],
                ],
            ],
        ]);

        $service = app(WordPressMediaSyncService::class);
        $html = $service->rewriteContentImages(
            $channel,
            $this->payloadWithImage(),
            '<p><img src="/storage/uploads/images/demo.png"></p>',
            $distribution,
        );

        $this->assertSame('<p><img src="https://wp.example.com/wp-content/uploads/image.jpg"></p>', $html);
        Http::assertNothingSent();
        // 复用的媒体仍进入本次上传列表，保证特色图片（featured_media）不因重试丢失。
        $this->assertSame(456, $service->takeLastUploadedMedia()[0]['id'] ?? null);
        $this->assertSame(
            ['id' => 456, 'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg'],
            $service->takeMediaMap()[hash('sha256', 'image-bytes')] ?? null,
        );
    }

    public function test_publisher_returns_media_map_in_remote_meta_for_retry_reuse(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello/',
            ], 201),
        ]);

        $channel = $this->makeChannel();
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $this->makeArticleId(),
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'wp-media-map-test',
        ]);

        $result = app(WordPressRestPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Hello',
                'slug' => 'hello',
                'excerpt' => '',
                'content_html' => '<p><img src="/storage/uploads/images/demo.png"></p>',
                'keywords' => '',
            ],
            'assets' => $this->payloadWithImage()['assets'],
        ]);

        $contentHash = hash('sha256', 'image-bytes');
        $this->assertSame(
            ['id' => 456, 'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg'],
            $result['remote_meta']['wp_media_map'][$contentHash] ?? null,
        );
    }

    /**
     * @param  array<string,string>  $configOverrides
     */
    private function makeChannel(array $configOverrides = []): DistributionChannel
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WP',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => array_replace([
                'wordpress_username' => 'editor',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'upload_to_media',
            ], $configOverrides),
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        return $channel;
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadWithImage(): array
    {
        return [
            'assets' => [
                'images' => [
                    [
                        'source_url' => '/storage/uploads/images/demo.png',
                        'filename' => 'demo.png',
                        'mime_type' => 'image/png',
                        'content_base64' => base64_encode('image-bytes'),
                    ],
                ],
            ],
        ];
    }

    private function makeArticleId(): int
    {
        $category = Category::query()->create(['name' => 'Tech', 'slug' => 'tech']);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $article = Article::query()->create([
            'title' => 'Hello',
            'slug' => 'hello',
            'content' => 'Hello',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        return (int) $article->id;
    }
}
