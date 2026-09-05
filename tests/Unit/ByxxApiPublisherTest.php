<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\ByxxApiPublisher;
use App\Services\GeoFlow\DistributionHttpException;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ByxxApiPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_uses_form_auth_and_maps_quota_without_exposing_password(): void
    {
        Http::fake([
            'https://member.byxx.com/api_getSpace.php' => Http::response([
                'code' => 200,
                'total' => 1000,
                'used' => 120,
                'free' => 880,
            ]),
        ]);
        [$channel] = $this->makeDistribution();

        $result = app(ByxxApiPublisher::class)->health($channel);

        $this->assertSame([
            'ok' => true,
            'channel_type' => DistributionChannel::TYPE_BYXX_API,
            'code' => 200,
            'total' => 1000,
            'used' => 120,
            'free' => 880,
        ], $result);
        $this->assertArrayNotHasKey('apikey', $result);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://member.byxx.com/api_getSpace.php'
            && $request->method() === 'POST'
            && $request['member_id'] === '11766482'
            && $request['apikey'] === 'login-password');
    }

    public function test_publish_sends_clean_plain_text_and_retains_remote_information_id(): void
    {
        Http::fake([
            'https://member.byxx.com/api_goods.php' => Http::response([
                'code' => 200,
                'message' => 'success',
                'id' => 7654321,
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        $result = app(ByxxApiPublisher::class)->publish($distribution, [
            'article' => [
                'title' => '橡胶软接头选型核对清单',
                'content' => "# 标题\n正文",
                'content_html' => '<h2>选型条件</h2><p>先核对介质与压力。</p><img src="https://example.com/a.jpg">',
            ],
        ]);

        $this->assertSame('7654321', $result['remote_id']);
        $this->assertSame('', $result['remote_url']);
        $this->assertSame('https://www.byxx.com/35237718', $result['remote_meta']['byxx']['shop_url']);
        $this->assertTrue($result['remote_meta']['byxx']['remote_lookup_required']);
        Http::assertSent(function ($request): bool {
            $text = (string) $request['text'];

            return $request->url() === 'https://member.byxx.com/api_goods.php'
                && $request['member_id'] === '11766482'
                && $request['apikey'] === 'login-password'
                && $request['title'] === '橡胶软接头选型核对清单'
                && $request['service'] === 2
                && str_contains($text, '选型条件')
                && str_contains($text, '先核对介质与压力。')
                && ! str_contains($text, '<h2>')
                && ! str_contains($text, '<img');
        });
    }

    public function test_publish_maps_duplicate_title_business_code_without_echoing_remote_message(): void
    {
        Http::fake([
            'https://member.byxx.com/api_goods.php' => Http::response([
                'code' => 409,
                'message' => 'duplicate password=should-not-appear',
            ]),
        ]);
        [, $distribution] = $this->makeDistribution();

        try {
            app(ByxxApiPublisher::class)->publish($distribution, [
                'article' => [
                    'title' => '重复标题',
                    'content_html' => '<p>正文</p>',
                ],
            ]);
            $this->fail('Expected duplicate-title failure.');
        } catch (DistributionHttpException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertStringContainsString('信息标题重复', $exception->getMessage());
            $this->assertStringNotContainsString('should-not-appear', $exception->getMessage());
        }
    }

    public function test_missing_secret_blocks_health_before_any_request(): void
    {
        Http::preventStrayRequests();
        [$channel] = $this->makeDistribution(withSecret: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('缺少有效API密码');

        app(ByxxApiPublisher::class)->health($channel);
    }

    public function test_update_delete_and_site_settings_reflect_remote_capabilities(): void
    {
        [$channel, $distribution] = $this->makeDistribution();
        $publisher = app(ByxxApiPublisher::class);

        $settings = $publisher->syncSiteSettings($channel);
        $this->assertTrue($settings['skipped']);
        $this->assertSame('byxx_api_does_not_support_site_settings', $settings['reason']);

        try {
            $publisher->update($distribution, []);
            $this->fail('Expected unsupported update failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('不支持远程更新', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不支持远程删除');
        $publisher->delete($distribution);
    }

    /** @return array{0:DistributionChannel,1:ArticleDistribution} */
    private function makeDistribution(bool $withSecret = true): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => '恒佳百业网',
            'domain' => 'www.byxx.com',
            'endpoint_url' => 'https://member.byxx.com',
            'channel_type' => DistributionChannel::TYPE_BYXX_API,
            'channel_config' => [
                'byxx_member_id' => '11766482',
                'byxx_shop_id' => '35237718',
                'byxx_brand_id' => 0,
                'byxx_price' => 0,
                'byxx_service' => 2,
                'byxx_timeout_seconds' => 30,
            ],
            'status' => 'active',
        ]);
        if ($withSecret) {
            DistributionChannelSecret::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'key_id' => 'byxx_test',
                'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('login-password'),
                'status' => 'active',
                'scopes' => ['byxx.api'],
            ]);
        }

        $category = Category::query()->create(['name' => '选型', 'slug' => 'selection']);
        $author = Author::query()->create(['name' => '杨磊']);
        $article = Article::query()->create([
            'title' => '橡胶软接头选型核对清单',
            'slug' => 'rubber-joint-selection-checklist',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'byxx-test-key',
        ]);

        return [$channel, $distribution];
    }
}
