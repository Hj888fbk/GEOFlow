<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminByxxDistributionChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_shows_baiye_fields(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.create'))
            ->assertOk()
            ->assertSee(__('admin.distribution.channel_type.byxx_api'))
            ->assertSee(__('admin.distribution.byxx.section_title'))
            ->assertSee('name="channel_type" value="byxx_api"', false)
            ->assertSee('name="byxx_member_id"', false)
            ->assertSee('name="byxx_shop_id"', false)
            ->assertSee('name="byxx_api_key"', false);
    }

    public function test_paused_baiye_channel_can_be_saved_without_password(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), $this->payload())
            ->assertRedirect();

        $channel = DistributionChannel::query()->where('name', '恒佳百业网')->firstOrFail();

        $this->assertSame(DistributionChannel::TYPE_BYXX_API, $channel->channelType());
        $this->assertSame(DistributionChannel::STATUS_PAUSED, (string) $channel->status);
        $this->assertSame('https://member.byxx.com', (string) $channel->endpoint_url);
        $this->assertSame('11766482', $channel->resolvedByxxConfig()['byxx_member_id']);
        $this->assertSame('35237718', $channel->resolvedByxxConfig()['byxx_shop_id']);
        $this->assertArrayNotHasKey('byxx_api_key', (array) $channel->channel_config);
        $this->assertFalse($channel->activeSecret()->exists());
    }

    public function test_active_baiye_channel_requires_password(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), $this->payload([
                'status' => DistributionChannel::STATUS_ACTIVE,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('byxx_api_key');

        $this->assertDatabaseMissing('distribution_channels', ['name' => '恒佳百业网']);
    }

    public function test_baiye_password_is_encrypted_and_excluded_from_channel_config(): void
    {
        $password = 'baiye-secret-password';

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), $this->payload([
                'status' => DistributionChannel::STATUS_ACTIVE,
                'byxx_api_key' => $password,
            ]))
            ->assertRedirect();

        $channel = DistributionChannel::query()->where('name', '恒佳百业网')->firstOrFail();
        $secret = $channel->activeSecret()->firstOrFail();

        $this->assertStringStartsWith('byxx_', (string) $secret->key_id);
        $this->assertSame(['byxx.api'], $secret->scopes);
        $this->assertNotSame($password, (string) $secret->secret_ciphertext);
        $this->assertSame($password, app(ApiKeyCrypto::class)->decrypt((string) $secret->secret_ciphertext));
        $this->assertStringNotContainsString($password, json_encode($channel->channel_config, JSON_UNESCAPED_UNICODE));
    }

    public function test_baiye_channel_rejects_non_baiye_endpoint(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.store'), $this->payload([
                'endpoint_url' => 'https://api.example.com',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('endpoint_url');
    }

    public function test_baiye_detail_and_edit_show_fixed_capabilities(): void
    {
        $channel = $this->channel();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.byxx.guide_title'))
            ->assertSee('POST /api_getSpace.php')
            ->assertSee('POST /api_goods.php')
            ->assertSee(__('admin.distribution.byxx.remote_limits_desc'))
            ->assertSee(__('admin.distribution.byxx.secret_missing_hint'))
            ->assertDontSee(__('admin.distribution.byxx.secret_hint'))
            ->assertDontSee(__('admin.distribution.button.download_package'))
            ->assertDontSee(__('admin.distribution.button.rotate_secret'))
            ->assertDontSee(__('admin.distribution.button.sync_settings'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee('name="channel_type" value="byxx_api"', false)
            ->assertSee('name="byxx_member_id"', false)
            ->assertSee('value="11766482"', false)
            ->assertSee(__('admin.distribution.byxx.api_key_update_help'))
            ->assertDontSee(__('admin.distribution.rewrite.title'));
    }

    public function test_paused_baiye_channel_without_password_cannot_be_activated(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.activate', ['channelId' => (int) $channel->id]))
            ->assertRedirect()
            ->assertSessionHasErrors('byxx_api_key');

        $this->assertSame(DistributionChannel::STATUS_PAUSED, (string) $channel->fresh()->status);
    }

    public function test_editing_baiye_password_revokes_old_secret(): void
    {
        $channel = $this->channel('old-password');
        $oldSecret = $channel->activeSecret()->firstOrFail();

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.distribution.update', ['channelId' => (int) $channel->id]), $this->payload([
                'status' => DistributionChannel::STATUS_ACTIVE,
                'byxx_api_key' => 'new-password',
            ]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]));

        $this->assertSame('revoked', (string) $oldSecret->fresh()->status);
        $newSecret = $channel->fresh()->activeSecret()->firstOrFail();
        $this->assertNotSame((int) $oldSecret->id, (int) $newSecret->id);
        $this->assertSame('new-password', app(ApiKeyCrypto::class)->decrypt((string) $newSecret->secret_ciphertext));
    }

    public function test_baiye_distribution_hides_unsupported_remote_mutations(): void
    {
        $channel = $this->channel('secret');
        $category = Category::query()->create(['name' => '科普', 'slug' => 'science']);
        $author = Author::query()->create(['name' => '杨磊']);
        $article = Article::query()->create([
            'title' => '橡胶软接头选型说明',
            'slug' => 'rubber-joint-selection',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '123456',
            'idempotency_key' => 'byxx-publish-1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.byxx.remote_mutation_not_supported'))
            ->assertDontSee(__('admin.distribution.button.edit_remote_article'))
            ->assertDontSee(__('admin.distribution.button.delete_remote_article'));
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '恒佳百业网',
            'domain' => 'www.byxx.com',
            'endpoint_url' => 'https://member.byxx.com',
            'channel_type' => DistributionChannel::TYPE_BYXX_API,
            'byxx_member_id' => '11766482',
            'byxx_shop_id' => '35237718',
            'byxx_site_id' => '',
            'byxx_class_id' => '',
            'byxx_brand_id' => 0,
            'byxx_price' => 0,
            'byxx_timeout_seconds' => 30,
            'status' => DistributionChannel::STATUS_PAUSED,
        ], $overrides);
    }

    private function channel(?string $password = null): DistributionChannel
    {
        $channel = DistributionChannel::query()->create([
            'name' => '恒佳百业网',
            'domain' => 'www.byxx.com',
            'endpoint_url' => 'https://member.byxx.com',
            'channel_type' => DistributionChannel::TYPE_BYXX_API,
            'channel_config' => [
                'byxx_member_id' => '11766482',
                'byxx_shop_id' => '35237718',
                'byxx_timeout_seconds' => 30,
            ],
            'status' => DistributionChannel::STATUS_PAUSED,
        ]);

        if ($password !== null) {
            DistributionChannelSecret::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'key_id' => 'byxx_test',
                'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt($password),
                'status' => 'active',
                'scopes' => ['byxx.api'],
            ]);
        }

        return $channel;
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'byxx_admin',
            'password' => 'secret-123',
            'email' => 'byxx-admin@example.com',
            'display_name' => 'Baiye Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
