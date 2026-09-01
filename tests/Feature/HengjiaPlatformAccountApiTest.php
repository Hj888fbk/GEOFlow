<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\PlatformAdapter;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HengjiaPlatformAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private PlatformAdapter $adapter;

    private ManualPublicationAccount $account;

    private string $bearerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'platform_api_reader',
            'password' => 'test-password',
            'email' => 'platform-api-reader@example.test',
            'display_name' => '平台账号只读测试员',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->bearerToken = $this->admin->createToken(
            'platform-account-read-test',
            ['platform-accounts:read'],
        )->plainTextToken;
        $persona = ManualPublicationPersona::query()->create([
            'name' => '恒佳工业品专家',
            'is_active' => true,
            'created_by_admin_id' => $this->admin->getKey(),
        ]);
        $this->adapter = PlatformAdapter::query()->create([
            'key' => PlatformAdapter::CUSTOM_MANUAL,
            'name' => '人工导出',
            'version' => '1.0.0',
            'execution_mode' => PlatformAdapter::EXECUTION_MANUAL_EXPORT,
            'implementation_class' => null,
            'supported_content_types' => ['article'],
            'connection_modes' => [PlatformAdapter::EXECUTION_MANUAL_EXPORT],
            'capabilities' => [],
            'field_contract' => [],
            'status' => 'active',
            'is_builtin' => true,
        ]);
        $this->account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->getKey(),
            'platform' => ManualPublicationAccount::PLATFORM_CUSTOM,
            'custom_platform' => '聚媒通',
            'account_name' => '恒佳聚媒通主账号',
            'profile_url' => 'https://example.test/hengjia',
            'is_active' => true,
            'created_by_admin_id' => $this->admin->getKey(),
            'platform_adapter_id' => $this->adapter->getKey(),
            'connection_mode' => PlatformAdapter::EXECUTION_MANUAL_EXPORT,
            'content_types' => ['article'],
            'authorization_status' => 'not_required',
            'login_status' => 'not_required',
            'capability_snapshot' => [],
            'publishing_rules' => [],
            'adapter_version' => '1.0.0',
        ]);
    }

    public function test_platform_catalog_remains_available_as_read_only_api(): void
    {
        $this->withToken($this->bearerToken)
            ->getJson('/api/v1/platforms')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', PlatformAdapter::CUSTOM_MANUAL)
            ->assertJsonPath('data.items.0.name', '人工导出');
    }

    public function test_platform_accounts_remain_available_as_read_only_api(): void
    {
        $this->withToken($this->bearerToken)
            ->getJson('/api/v1/platform-accounts')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $this->account->getKey())
            ->assertJsonPath('data.items.0.account_name', '恒佳聚媒通主账号');
    }

    public function test_parallel_platform_account_write_urls_are_not_routable(): void
    {
        $before = ManualPublicationAccount::query()->count();
        $headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Idempotency-Key' => 'retired-platform-account-write',
        ];
        $requests = [
            'POST /api/v1/platform-accounts' => fn () => $this->withHeaders($headers)->postJson('/api/v1/platform-accounts', ['account_name' => '不得写入']),
            'PATCH /api/v1/platform-accounts/{id}' => fn () => $this->withHeaders($headers)->patchJson('/api/v1/platform-accounts/'.$this->account->getKey(), ['account_name' => '不得改名']),
            'POST /api/v1/platform-accounts/{id}/disable' => fn () => $this->withHeaders($headers)->postJson('/api/v1/platform-accounts/'.$this->account->getKey().'/disable'),
            'POST /api/v1/platform-accounts/{id}/verify' => fn () => $this->withHeaders($headers)->postJson('/api/v1/platform-accounts/'.$this->account->getKey().'/verify'),
            'POST /api/v1/platform-accounts/{id}/capabilities/refresh' => fn () => $this->withHeaders($headers)->postJson('/api/v1/platform-accounts/'.$this->account->getKey().'/capabilities/refresh'),
        ];

        foreach ($requests as $label => $request) {
            $response = $request();
            self::assertContains(
                $response->getStatusCode(),
                [404, 405],
                $label.' returned '.$response->getStatusCode().': '.$response->getContent(),
            );
            self::assertSame($before, ManualPublicationAccount::query()->count());
        }

        self::assertSame('恒佳聚媒通主账号', $this->account->fresh()->account_name);
        self::assertTrue((bool) $this->account->fresh()->is_active);
    }

    public function test_cli_scope_catalog_exposes_read_but_not_parallel_write_scope(): void
    {
        $scopes = app(ApiTokenService::class)->getCliLoginScopes();

        self::assertContains('platform-accounts:read', $scopes);
        self::assertNotContains('platform-accounts:write', $scopes);
    }
}
