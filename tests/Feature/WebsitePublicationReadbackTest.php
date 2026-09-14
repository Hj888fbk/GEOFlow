<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReadbackService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebsitePublicationReadbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_hash_matches_published_html_representation(): void
    {
        $article = $this->makeArticle("# 示例标题\n\n第一段正文。\n\n## 小节\n\n| 列 | 值 |\n| --- | --- |\n| 甲 | 1 |\n\n![image](/storage/uploads/images/x.jpg)");
        $hasher = app(SelfMediaSourceHasher::class);

        $source = $hasher->hash($article);
        // 官网侧读回的是发布管线渲染后的 HTML（markdown 已转 HTML）。
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );
        $readback = $hasher->hashNormalized($hasher->normalizeReadback(
            (string) $article->title,
            (string) ($article->excerpt ?? ''),
            $renderedHtml,
        ));

        $this->assertSame($source, $readback);
    }

    public function test_html_content_is_hashed_without_double_conversion(): void
    {
        $article = $this->makeArticle('<h2>已是 HTML</h2><p>正文。</p>');
        $hasher = app(SelfMediaSourceHasher::class);

        $readback = $hasher->hashNormalized($hasher->normalizeReadback(
            (string) $article->title,
            (string) ($article->excerpt ?? ''),
            (string) $article->content,
        ));

        $this->assertSame($hasher->hash($article), $readback);
    }

    public function test_wordpress_generated_image_caption_does_not_break_readback_hash(): void
    {
        $article = $this->makeArticle("正文前\n\n![image](/storage/uploads/images/example.jpg)\n\n正文后");
        $hasher = app(SelfMediaSourceHasher::class);
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );
        $wordpressHtml = preg_replace(
            '/(<img\b[^>]*>)/iu',
            '<figure>$1<figcaption>由媒体库自动生成的说明</figcaption></figure><p>运输层自动生成的图片说明</p>',
            $renderedHtml,
            1,
        ) ?? $renderedHtml;

        $readback = $hasher->hashNormalized($hasher->normalizeReadback(
            (string) $article->title,
            (string) ($article->excerpt ?? ''),
            $wordpressHtml,
        ));

        $this->assertSame($hasher->hash($article), $readback);
    }

    public function test_gutenberg_block_comments_and_empty_caption_paragraphs_do_not_break_readback_hash(): void
    {
        $article = $this->makeArticle("正文前\n\n![image](/storage/uploads/images/example.jpg)\n\n正文后");
        $hasher = app(SelfMediaSourceHasher::class);
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );
        $wordpressHtml = preg_replace(
            '/(<img\\b[^>]*>)/iu',
            '<!-- wp:image --><figure>$1</figure><!-- /wp:image --><!-- wp:paragraph --><p>图片标题</p><!-- /wp:paragraph --><!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            $renderedHtml,
            1,
        ) ?? $renderedHtml;

        $readback = $hasher->hashNormalized($hasher->normalizeReadback(
            (string) $article->title,
            (string) ($article->excerpt ?? ''),
            $wordpressHtml,
        ));

        $this->assertSame($hasher->hash($article), $readback);
    }

    public function test_receipt_is_submitted_when_remote_post_is_published_and_content_matches(): void
    {
        $admin = $this->makeAdmin();
        $article = $this->makeArticle("## 采购要点\n\n先看工况，再看报价。");
        [$distribution, $channel] = $this->makeWordPressDistribution($article);

        $hasher = app(SelfMediaSourceHasher::class);
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );

        Http::fake(function ($request) use ($article, $renderedHtml) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/')) {
                return Http::response([
                    'status' => 'publish',
                    'link' => 'https://official.example.com/news/test/',
                    'title' => ['raw' => (string) $article->title, 'rendered' => (string) $article->title],
                    'excerpt' => ['raw' => (string) ($article->excerpt ?? ''), 'rendered' => (string) ($article->excerpt ?? '')],
                    'content' => ['raw' => $renderedHtml, 'rendered' => $renderedHtml],
                ]);
            }

            return Http::response('<html><body>ok</body></html>', 200);
        });

        $receipt = app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution);

        $this->assertNotNull($receipt);
        $this->assertSame($hasher->hash($article->fresh()), $receipt->source_hash);
        $this->assertSame('https://official.example.com/news/test/', $receipt->formal_url);
        $this->assertTrue((bool) $receipt->readback_succeeded);
        $this->assertDatabaseHas('website_publication_receipts', [
            'article_id' => (int) $article->id,
            'responsible_project_id' => 'HJ-WEB',
        ]);

        // 幂等：再次轮询返回同一条回执，不产生重复记录。
        $again = app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution->fresh());
        $this->assertNotNull($again);
        $this->assertSame(1, \App\Models\WebsitePublicationReceipt::query()->where('article_id', $article->id)->count());
    }

    public function test_existing_receipt_backfills_stale_distribution_url(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle('## 已回读文章');
        [$distribution] = $this->makeWordPressDistribution($article);
        $hasher = app(SelfMediaSourceHasher::class);
        $hash = $hasher->hash($article);

        \App\Models\WebsitePublicationReceipt::query()->create([
            'article_id' => $article->id,
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => 'https://official.example.com/news/canonical/',
            'http_status' => 200,
            'source_hash' => $hash,
            'readback_hash' => $hash,
            'readback_succeeded' => true,
            'verified_at' => now(),
        ]);
        $distribution->update(['remote_url' => 'https://official.example.com/news/legacy/']);

        $receipt = app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution->fresh());

        $this->assertNotNull($receipt);
        $this->assertSame(
            'https://official.example.com/news/canonical/',
            (string) $distribution->fresh()->remote_url,
        );
        $this->assertSame(
            'https://official.example.com/news/canonical/',
            data_get($distribution->fresh()->remote_meta, 'canonical_url'),
        );
    }

    public function test_readback_accepts_wordpress_endpoint_that_already_contains_wp_json(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle("## 带 REST 基址的文章\n\n正文内容。");
        [$distribution, $channel] = $this->makeWordPressDistribution($article);
        $channel->update(['endpoint_url' => 'https://official.example.com/wp-json']);
        $distribution->update(['remote_url' => null]);

        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );
        Http::fake(function ($request) use ($article, $renderedHtml) {
            $this->assertStringNotContainsString('/wp-json/wp-json/', $request->url());
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/')) {
                return Http::response([
                    'status' => 'publish',
                    'link' => 'https://official.example.com/news/rest-base/',
                    'title' => ['raw' => (string) $article->title],
                    'excerpt' => ['raw' => (string) ($article->excerpt ?? '')],
                    'content' => ['raw' => $renderedHtml],
                ]);
            }

            return Http::response('<html><body>ok</body></html>', 200);
        });

        $receipt = app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution->fresh());

        $this->assertNotNull($receipt);
        $this->assertSame('https://official.example.com/news/rest-base/', $receipt->formal_url);
    }

    public function test_poll_command_rechecks_synced_wordpress_updates(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle("## 更新后的官网文章\n\n正文内容。");
        [$distribution] = $this->makeWordPressDistribution($article);
        $distribution->update(['action' => 'update', 'remote_url' => null]);
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );
        Http::fake(function ($request) use ($article, $renderedHtml) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/')) {
                return Http::response([
                    'status' => 'publish',
                    'link' => 'https://official.example.com/news/updated/',
                    'title' => ['raw' => (string) $article->title],
                    'excerpt' => ['raw' => (string) ($article->excerpt ?? '')],
                    'content' => ['raw' => $renderedHtml],
                ]);
            }

            return Http::response('<html><body>ok</body></html>', 200);
        });

        $this->artisan('geoflow:poll-website-publications')->assertExitCode(0);

        $this->assertDatabaseHas('website_publication_receipts', [
            'article_id' => (int) $article->id,
            'formal_url' => 'https://official.example.com/news/updated/',
        ]);
    }

    public function test_receipt_is_skipped_while_remote_post_is_still_draft(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle('## 草稿内容');
        [$distribution] = $this->makeWordPressDistribution($article);

        Http::fake(fn () => Http::response(['status' => 'draft'], 200));

        $this->assertNull(app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution));
        $this->assertDatabaseCount('website_publication_receipts', 0);
    }

    public function test_receipt_is_skipped_when_remote_content_differs(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle('## 原始内容');
        [$distribution] = $this->makeWordPressDistribution($article);

        Http::fake(function ($request) use ($article) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/')) {
                return Http::response([
                    'status' => 'publish',
                    'link' => 'https://official.example.com/news/test/',
                    'title' => ['raw' => (string) $article->title],
                    'excerpt' => ['raw' => (string) ($article->excerpt ?? '')],
                    'content' => ['raw' => '<h2>被人工改过的内容</h2>'],
                ]);
            }

            return Http::response('<html><body>ok</body></html>', 200);
        });

        $this->assertNull(app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution));
        $this->assertDatabaseCount('website_publication_receipts', 0);
    }

    public function test_readback_follows_same_origin_canonical_redirect_and_persists_final_url(): void
    {
        $this->makeAdmin();
        $article = $this->makeArticle('## 旧链接文章');
        [$distribution] = $this->makeWordPressDistribution($article);
        $distribution->update(['remote_url' => 'https://official.example.com/news/legacy/']);
        $renderedHtml = ArticleHtmlPresenter::markdownToHtml(
            ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title)
        );

        Http::fake(function ($request) use ($article, $renderedHtml) {
            if (str_contains($request->url(), '/wp-json/wp/v2/posts/')) {
                return Http::response([
                    'status' => 'publish',
                    'link' => 'https://official.example.com/news/legacy/',
                    'title' => ['raw' => (string) $article->title],
                    'excerpt' => ['raw' => (string) ($article->excerpt ?? '')],
                    'content' => ['raw' => $renderedHtml],
                ]);
            }
            if (str_contains($request->url(), '/news/legacy/')) {
                return Http::response('', 301, ['Location' => 'https://official.example.com/news/canonical/']);
            }

            return Http::response('<html><body>ok</body></html>', 200);
        });

        $receipt = app(WebsitePublicationReadbackService::class)->attemptReceipt($distribution);

        $this->assertNotNull($receipt);
        $this->assertSame('https://official.example.com/news/canonical/', $receipt->formal_url);
    }

    private function makeAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('readback_admin_'),
            'password' => 'secret-123',
            'email' => uniqid('readback-admin-').'@example.com',
            'display_name' => 'Readback Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function makeArticle(string $content): Article
    {
        $categoryId = \App\Models\Category::query()->create([
            'name' => '回读测试分类',
            'slug' => 'readback-cat-'.uniqid(),
        ])->id;
        $authorId = \App\Models\Author::query()->create([
            'name' => '回读测试作者',
        ])->id;

        return Article::query()->create([
            'title' => '回读测试文章',
            'slug' => 'readback-test-'.uniqid(),
            'excerpt' => '回读测试摘要。',
            'content' => $content,
            'category_id' => $categoryId,
            'author_id' => $authorId,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 0,
            'view_count' => 0,
        ]);
    }

    /** @return array{0: ArticleDistribution, 1: DistributionChannel} */
    private function makeWordPressDistribution(Article $article): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WP 测试渠道',
            'domain' => 'official.example.com',
            'endpoint_url' => 'https://official.example.com',
            'channel_type' => 'wordpress_rest',
            'status' => 'active',
            'channel_config' => ['wordpress_username' => 'tester'],
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'wp_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app-password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '875',
            'remote_url' => 'https://official.example.com/news/test/',
            'idempotency_key' => 'readback-test-'.uniqid(),
        ]);

        return [$distribution, $channel];
    }
}
