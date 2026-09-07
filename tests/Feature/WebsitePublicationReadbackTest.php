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
