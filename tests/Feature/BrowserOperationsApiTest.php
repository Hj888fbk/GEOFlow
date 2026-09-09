<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Models\SelfMediaMediaSnapshot;
use App\Models\Task;
use App\Models\WebsitePublicationReceipt;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrowserOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_login_scopes_exclude_browser_execution_permissions(): void
    {
        $scopes = app(ApiTokenService::class)->getCliLoginScopes();

        $this->assertContains('materials:read', $scopes);
        $this->assertNotContains('browser-operations:read', $scopes);
        $this->assertNotContains('browser-operations:execute', $scopes);
    }

    public function test_admin_can_approve_device_and_extension_receives_one_time_browser_token(): void
    {
        $admin = $this->admin();
        $headers = $this->browserHeaders();

        $authorization = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', [
                'client_name' => 'Operations Chrome',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval',
            ]]);

        $deviceCode = (string) $authorization->json('data.device_code');
        $userCode = (string) $authorization->json('data.user_code');

        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'authorization_pending');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.manual-publications.browser-connect.show', ['user_code' => $userCode]))
            ->assertOk()
            ->assertSee($userCode);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.browser-connect.decision'), [
                'user_code' => $userCode,
                'decision' => 'approve',
            ])
            ->assertRedirect();

        $this->travel(5)->seconds();

        $tokenResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertOk()
            ->assertJsonPath('data.scopes.0', 'browser-operations:read')
            ->assertJsonPath('data.scopes.1', 'browser-operations:execute');

        $plainToken = (string) $tokenResponse->json('data.token');
        $this->assertNotSame('', $plainToken);

        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', ['device_code' => $deviceCode])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'expired_token');

        $this->withHeaders($headers + ['Authorization' => 'Bearer '.$plainToken])
            ->getJson('/api/v1/browser-operations/session')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $admin->id)
            ->assertJsonPath('data.protocol_version', 1);
    }

    public function test_device_authorization_can_be_denied_or_expire(): void
    {
        $admin = $this->admin();
        $headers = $this->browserHeaders();

        $denied = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', ['client_name' => 'Denied Chrome'])
            ->assertOk();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publications.browser-connect.decision'), [
                'user_code' => $denied->json('data.user_code'),
                'decision' => 'deny',
            ])
            ->assertRedirect();
        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', [
                'device_code' => $denied->json('data.device_code'),
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'access_denied');

        $expired = $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-authorizations', ['client_name' => 'Expired Chrome'])
            ->assertOk();
        $this->travel(11)->minutes();
        $this->withHeaders($headers)
            ->postJson('/api/v1/browser-operations/device-token', [
                'device_code' => $expired->json('data.device_code'),
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'expired_token');
    }

    public function test_cli_token_and_incompatible_protocol_cannot_use_browser_operations(): void
    {
        $admin = $this->admin();
        $cliToken = $admin->createToken(
            'CLI token',
            app(ApiTokenService::class)->getCliLoginScopes(),
        )->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($cliToken))
            ->getJson('/api/v1/manual-publications')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$cliToken,
            'X-GEOFlow-Browser-Protocol' => '99',
            'X-GEOFlow-Client-Version' => '0.1.0',
        ])->getJson('/api/v1/manual-publications')
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'upgrade_required');
    }

    public function test_browser_token_can_claim_heartbeat_and_complete_an_assigned_publication(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'GEOFlow 专家']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'account_name' => 'GEOFlow 知乎账号',
            'profile_url' => 'https://www.zhihu.com/people/geoflow',
        ]);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_POST,
            'persona_id' => $persona->id,
            'account_id' => $account->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'target_url' => 'https://www.zhihu.com/question/123456',
            'target_url_hash' => hash('sha256', 'https://www.zhihu.com/question/123456'),
            'content' => '这是需要填充到知乎回答编辑器的正文。',
            'content_fingerprint' => hash('sha256', 'browser-operation-content'),
            'identity_snapshot' => [],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 1,
                'target_action' => 'zhihu_answer',
                'title' => '',
                'body_plain' => '这是需要填充到知乎回答编辑器的正文。',
                'body_markdown' => '这是需要填充到知乎回答编辑器的正文。',
                'tags' => [],
                'canonical_url' => 'https://www.zhihu.com/question/123456',
                'disclosure' => null,
                'asset_ids' => [],
            ],
        ]);
        $firstToken = $admin->createToken('Chrome one', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;
        $secondToken = $admin->createToken('Chrome two', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($firstToken))
            ->getJson('/api/v1/manual-publications')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $publication->id)
            ->assertJsonPath('data.items.0.account.profile_url', 'https://www.zhihu.com/people/geoflow');

        $claimHeaders = $this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'claim-publication-12345678',
        ];
        $claim = $this->withHeaders($claimHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 2);

        $this->withHeaders($claimHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 2);

        $this->withHeaders($this->authenticatedHeaders($secondToken) + [
            'X-Idempotency-Key' => 'claim-publication-second-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'publication_claimed');

        $this->withHeaders($this->authenticatedHeaders($secondToken))
            ->getJson('/api/v1/manual-publications/'.$publication->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');

        $this->withHeaders($this->authenticatedHeaders($firstToken))
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.alive', true);

        $receipt = [
            'revision' => 2,
            'outcome' => 'completed',
            'completion_url' => 'https://www.zhihu.com/question/123456/answer/987654',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://www.zhihu.com',
            'observed_account_hash' => hash('sha256', 'https://www.zhihu.com/people/geoflow'),
            'started_at' => now()->subMinute()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];

        $wrongAccountReceipt = array_replace($receipt, [
            'observed_account_hash' => str_repeat('0', 64),
        ]);
        $this->withHeaders($this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-wrong-account-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $wrongAccountReceipt)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_mismatch');

        $wrongQuestionReceipt = array_replace($receipt, [
            'completion_url' => 'https://www.zhihu.com/question/999999/answer/987654',
        ]);
        $this->withHeaders($this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-wrong-question-123', // gitleaks:allow
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $wrongQuestionReceipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_completion_target');

        $receiptHeaders = $this->authenticatedHeaders($firstToken) + [
            'X-Idempotency-Key' => 'receipt-publication-123456',
        ];
        $this->withHeaders($receiptHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $receipt)
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED)
            ->assertJsonPath('data.publication.completion_url', $receipt['completion_url'])
            ->assertJsonPath('data.publication.revision', 3);

        $this->withHeaders($receiptHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', $receipt)
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED)
            ->assertJsonPath('data.publication.revision', 3);

        $publication->refresh();
        $this->assertSame(ManualPublication::STATUS_COMPLETED, $publication->status);
        $this->assertSame('0.1.0', $publication->execution_receipt['adapter_version']);
        $this->assertNull($publication->browser_claimed_by_token_id);
        $this->assertSame(2, (int) $claim->json('data.publication.revision'));
    }

    public function test_generic_work_order_can_run_without_a_saved_platform_account(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'Generic operator']);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_COMMENT,
            'persona_id' => $persona->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_CUSTOM,
            'custom_platform' => 'Community',
            'target_url' => 'https://community.example.com/thread/123',
            'target_context' => 'A community discussion',
            'target_url_hash' => hash('sha256', 'https://community.example.com/thread/123'),
            'content' => 'Generic browser-assisted reply.',
            'content_fingerprint' => hash('sha256', 'generic-browser-assisted-reply'),
            'identity_snapshot' => [],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 1,
                'target_action' => 'manual_comment',
                'title' => '',
                'body_plain' => 'Generic browser-assisted reply.',
                'body_markdown' => 'Generic browser-assisted reply.',
                'tags' => [],
                'canonical_url' => 'https://community.example.com/thread/123',
                'disclosure' => null,
                'asset_ids' => [],
            ],
        ]);
        $token = $admin->createToken('Generic Chrome', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;

        $this->withHeaders($this->authenticatedHeaders($token))
            ->getJson('/api/v1/manual-publications')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $publication->id)
            ->assertJsonPath('data.items.0.account', null);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'claim-generic-publication-1234',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS);

        $releaseHeaders = $this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'release-generic-publication-12',
        ];
        $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_READY)
            ->assertJsonPath('data.publication.revision', 3);
        $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_READY)
            ->assertJsonPath('data.publication.revision', 3);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'reclaim-generic-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 3])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.revision', 4);

        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-generic-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
            'revision' => 4,
            'outcome' => 'failed',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://community.example.com',
            'finished_at' => now()->toIso8601String(),
            'error_code' => 'operator_reported_failure',
        ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_FAILED);

        $cancelled = $publication->replicate();
        $cancelled->forceFill([
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
        ])->save();
        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'claim-cancelled-publication-12',
        ])->postJson('/api/v1/manual-publications/'.$cancelled->id.'/claim', ['revision' => 1])
            ->assertOk();
        $this->withHeaders($this->authenticatedHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-cancelled-publication',
        ])->postJson('/api/v1/manual-publications/'.$cancelled->id.'/receipt', [
            'revision' => 2,
            'outcome' => 'cancelled',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://community.example.com',
            'finished_at' => now()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_CANCELLED);
    }

    public function test_admin_only_sees_and_revokes_owned_browser_connections(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $superAdmin = $this->admin('super_admin');
        $ownBrowserResult = $admin->createToken('Chrome owned', [
            'browser-operations:read', 'browser-operations:execute',
        ]);
        $ownBrowser = $ownBrowserResult->accessToken;
        $admin->createToken('CLI token', ['catalog:read']);
        $otherBrowser = $other->createToken('Chrome other', [
            'browser-operations:read', 'browser-operations:execute',
        ])->accessToken;

        $this->actingAs($admin, 'admin')
            ->get(route('admin.account.browser-clients.index'))
            ->assertOk()
            ->assertSee('Chrome owned')
            ->assertDontSee('CLI token')
            ->assertDontSee('Chrome other');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.account.browser-clients.index'))
            ->assertOk()
            ->assertSee('Chrome owned')
            ->assertSee('Chrome other')
            ->assertDontSee('CLI token');

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.account.browser-clients.destroy', ['tokenId' => $otherBrowser->id]))
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.account.browser-clients.destroy', ['tokenId' => $ownBrowser->id]))
            ->assertRedirect(route('admin.account.browser-clients.index'));

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $ownBrowser->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherBrowser->id]);

        $this->withHeaders($this->authenticatedHeaders($ownBrowserResult->plainTextToken))
            ->getJson('/api/v1/browser-operations/session')
            ->assertUnauthorized();
    }

    public function test_v2_self_media_work_order_separates_draft_receipt_from_verified_publication_receipt(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => '恒佳发布身份']);
        $profileUrl = 'https://baijiahao.baidu.com/bjournal/profile/geoflow';
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'account_name' => '恒佳百家号',
            'profile_url' => $profileUrl,
            'account_uid' => '778899',
            'editor_url' => 'https://baijiahao.baidu.com/builder/rc/edit',
            'browser_adapter_enabled' => true,
        ]);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_POST,
            'persona_id' => $persona->id,
            'account_id' => $account->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'target_url' => $account->editor_url,
            'content' => '百家号正文',
            'content_fingerprint' => hash('sha256', '百家号正文'),
            'identity_snapshot' => ['account' => ['profile_url' => $profileUrl]],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'draft_filled_receipt' => ['observed_account_hash' => str_repeat('a', 64)],
            'publication_payload' => [
                'schema_version' => 2,
                'target_action' => 'baijiahao_article',
                'title' => '百家号标题',
                'body_plain' => '百家号正文',
                'body_markdown' => '百家号正文',
                'tags' => [],
            ],
        ]);
        $token = $admin->createToken('Self media Chrome', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;
        $headers = $this->authenticatedHeaders($token);

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'claim-v2-self-media-publication'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.publication.account_verified', false);
        $this->assertSame(str_repeat('a', 64), $publication->refresh()->draft_filled_receipt['observed_account_hash']);

        $accountHash = hash('sha256', 'uid:778899');
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'draft-v2-self-media-publication'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', [
                'revision' => 2,
                'adapter_version' => '0.2.0',
                'target_origin' => 'https://baijiahao.baidu.com',
                'observed_account_hash' => $accountHash,
                'filled_fields' => ['title', 'body'],
                'finished_at' => now()->toIso8601String(),
            ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_DRAFT_FILLED)
            ->assertJsonPath('data.publication.revision', 3)
            ->assertJsonPath('data.publication.account_verified', true);

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'release-v2-after-draft-filled'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 3])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'draft_already_filled');

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'complete-v2-without-readback'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                'revision' => 3,
                'outcome' => 'completed',
                'completion_url' => 'https://baijiahao.baidu.com/s?id=123456',
                'adapter_version' => '0.2.0',
                'target_origin' => 'https://baijiahao.baidu.com',
                'observed_account_hash' => $accountHash,
                'finished_at' => now()->toIso8601String(),
            ])->assertStatus(422)
            ->assertJsonPath('error.code', 'public_url_readback_required');

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'complete-v2-with-different-readback-url'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                'revision' => 3,
                'outcome' => 'completed',
                'completion_url' => 'https://baijiahao.baidu.com/s?id=123456',
                'adapter_version' => '0.2.0',
                'target_origin' => 'https://baijiahao.baidu.com',
                'finished_at' => now()->toIso8601String(),
                'public_url_readback_status' => 200,
                'public_url_readback_succeeded' => true,
                'public_url_readback_url' => 'https://baijiahao.baidu.com/s?id=999999',
            ])->assertStatus(422)
            ->assertJsonPath('error.code', 'public_url_readback_required');

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'complete-v2-with-readback'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                'revision' => 3,
                'outcome' => 'completed',
                'completion_url' => 'https://baijiahao.baidu.com/s?id=123456',
                'adapter_version' => '0.2.0',
                'target_origin' => 'https://baijiahao.baidu.com',
                'finished_at' => now()->toIso8601String(),
                'public_url_readback_status' => 200,
                'public_url_readback_succeeded' => true,
                'public_url_readback_url' => 'https://baijiahao.baidu.com/s?id=123456',
            ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED);

        $publication->refresh();
        $this->assertNotNull($publication->draft_filled_receipt);
        $this->assertSame(2, $publication->draft_filled_receipt['schema_version']);
        $this->assertSame(2, $publication->execution_receipt['schema_version']);
        $this->assertTrue($publication->execution_receipt['public_url_readback_succeeded']);
        $this->assertSame('https://baijiahao.baidu.com/s?id=123456', $publication->execution_receipt['public_url_readback_url']);
        $this->assertSame($accountHash, $publication->execution_receipt['observed_account_hash']);
        $this->assertNull($publication->browser_claimed_by_token_id);
    }

    public function test_one_browser_connection_cannot_claim_two_work_orders_concurrently(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'Single Chrome identity']);
        $makePublication = function (string $content) use ($admin, $persona): ManualPublication {
            return ManualPublication::query()->create([
                'type' => ManualPublication::TYPE_COMMENT,
                'persona_id' => $persona->id,
                'assigned_admin_id' => $admin->id,
                'platform' => ManualPublicationAccount::PLATFORM_CUSTOM,
                'custom_platform' => 'Community',
                'target_url' => 'https://community.example.com/editor',
                'target_context' => '讨论上下文',
                'content' => $content,
                'content_fingerprint' => hash('sha256', $content),
                'identity_snapshot' => [],
                'status' => ManualPublication::STATUS_READY,
                'status_changed_at' => now(),
                'revision' => 1,
                'publication_payload' => ['schema_version' => 1, 'target_action' => 'manual_comment', 'body_plain' => $content],
            ]);
        };
        $first = $makePublication('第一条工作单');
        $second = $makePublication('第二条工作单');
        $token = $admin->createToken('One Chrome', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;
        $headers = $this->authenticatedHeaders($token);

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'single-browser-first-claim'])
            ->postJson('/api/v1/manual-publications/'.$first->id.'/claim', ['revision' => 1])
            ->assertOk();
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'single-browser-second-claim'])
            ->postJson('/api/v1/manual-publications/'.$second->id.'/claim', ['revision' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'browser_concurrency_limit');
    }

    public function test_v3_work_order_requires_extension_030_and_remote_saved_receipt_must_match_fingerprint(): void
    {
        $admin = $this->admin();
        $persona = ManualPublicationPersona::query()->create(['name' => 'V3 identity']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'account_name' => 'V3 account',
            'account_uid' => '778899',
            'editor_url' => 'https://baijiahao.baidu.com/builder/rc/edit',
            'browser_adapter_enabled' => true,
        ]);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_POST,
            'persona_id' => $persona->id,
            'account_id' => $account->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'target_url' => $account->editor_url,
            'content' => '正文',
            'content_fingerprint' => hash('sha256', 'v3-content'),
            'identity_snapshot' => ['account' => ['account_uid' => '778899']],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 3,
                'target_action' => 'baijiahao_article',
                'required_extension_version' => '0.3.0',
                'draft_policy' => 'draft_only',
                'title' => '标题',
            'body_plain' => '正文',
                'media_manifest' => [
                    ['media_key' => 'm_aaaaaaaaaaaaaaaaaaaaaaaa', 'sha256' => str_repeat('a', 64), 'role' => 'body', 'required' => true, 'position' => 1],
                    ['media_key' => 'm_bbbbbbbbbbbbbbbbbbbbbbbb', 'sha256' => str_repeat('b', 64), 'role' => 'body', 'required' => true, 'position' => 2],
                ],
                'render_fingerprint' => [
                    'text_sha256' => hash('sha256', '正文'),
                    'heading_outline' => [],
                    'image_order' => ['m_aaaaaaaaaaaaaaaaaaaaaaaa', 'm_bbbbbbbbbbbbbbbbbbbbbbbb'],
                ],
            ],
            'document_schema_version' => 'portable-article-document/v1',
        ]);
        $token = $admin->createToken('V3 Chrome', ['browser-operations:read', 'browser-operations:execute'])->plainTextToken;
        $oldHeaders = $this->authenticatedHeaders($token);

        $this->withHeaders($oldHeaders)->getJson('/api/v1/manual-publications')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
        $this->withHeaders($oldHeaders + ['X-Idempotency-Key' => 'old-extension-v3-claim'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'extension_upgrade_required');

        $headers = $this->authenticatedHeaders($token, '0.3.0');
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'new-extension-v3-claim'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS);

        $receipt = [
            'revision' => 2,
            'adapter_version' => '0.3.0',
            'target_origin' => 'https://baijiahao.baidu.com',
            'observed_account_hash' => hash('sha256', 'uid:778899'),
            'filled_fields' => ['title', 'body'],
            'persistence' => 'remote_saved',
            'draft_id' => 'draft-123',
            'draft_url' => 'https://baijiahao.baidu.com/builder/rc/edit?article_id=draft-123',
            'rendered_text_hash' => str_repeat('0', 64),
            'heading_outline' => [],
            'expected_image_count' => 2,
            'observed_image_count' => 2,
            'media_upload_receipts' => [
                ['media_key' => 'm_aaaaaaaaaaaaaaaaaaaaaaaa', 'source_sha256' => str_repeat('a', 64), 'platform_url' => 'https://bcebos.com/a.jpg'],
                ['media_key' => 'm_bbbbbbbbbbbbbbbbbbbbbbbb', 'source_sha256' => str_repeat('b', 64), 'platform_url' => 'https://bcebos.com/b.jpg'],
            ],
            'finished_at' => now()->toIso8601String(),
        ];
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-mismatched-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $receipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'draft_text_mismatch');

        $receipt['rendered_text_hash'] = hash('sha256', '正文');
        $oldAdapterReceipt = array_replace($receipt, ['adapter_version' => '0.2.0']);
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-old-adapter-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $oldAdapterReceipt)
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'extension_upgrade_required');

        $missingDraftUrlReceipt = array_replace($receipt, ['draft_url' => null]);
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-missing-url-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $missingDraftUrlReceipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'draft_url_required');

        $wrongDraftUrlReceipt = array_replace($receipt, ['draft_url' => 'https://evil.example/draft-123']);
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-wrong-url-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $wrongDraftUrlReceipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_draft_url');

        $reversedMediaReceipt = array_replace($receipt, ['media_upload_receipts' => array_reverse($receipt['media_upload_receipts'])]);
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-reversed-media-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $reversedMediaReceipt)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'draft_media_receipt_mismatch');

        $this->withHeaders($headers + ['X-Idempotency-Key' => 'v3-matched-draft-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/draft-receipt', $receipt)
            ->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_DRAFT_FILLED);
        $this->assertSame('remote_saved', $publication->refresh()->draft_filled_receipt['persistence']);
    }

    public function test_protected_media_is_available_only_while_the_requesting_device_owns_the_claim(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $task = Task::query()->create(['name' => '受保护媒体测试任务', 'status' => 'active']);
        $category = Category::query()->create(['name' => '测试分类', 'slug' => uniqid('browser-media-category-')]);
        $author = Author::query()->create(['name' => '测试作者']);
        $article = Article::query()->create([
            'title' => '受保护媒体测试文章',
            'slug' => uniqid('browser-media-article-'),
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $sourceHash = hash('sha256', 'protected-media-source');
        $websiteReceipt = WebsitePublicationReceipt::query()->create([
            'article_id' => $article->id,
            'formal_url' => 'https://www.example.com/'.$article->slug,
            'http_status' => 200,
            'source_hash' => $sourceHash,
            'readback_hash' => $sourceHash,
            'readback_succeeded' => true,
            'verified_at' => now(),
        ]);
        $batch = ManualPublicationBatch::query()->create([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'website_publication_receipt_id' => $websiteReceipt->id,
            'created_by_admin_id' => $admin->id,
            'trigger' => ManualPublicationBatch::TRIGGER_MANUAL,
            'content_intent' => 'engineering_technical',
            'routing_version' => 'self-media-routing-v2',
            'target_platforms' => [ManualPublicationAccount::PLATFORM_BAIJIAHAO],
            'platform_combination_hash' => hash('sha256', 'baijiahao'),
            'source_url' => $websiteReceipt->formal_url,
            'website_readback' => ['http_status' => 200],
            'source_hash' => $sourceHash,
            'source_snapshot' => ['title' => $article->title, 'content' => $article->content],
            'fact_constraints' => [],
            'idempotency_hash' => hash('sha256', 'protected-media-batch'),
            'status' => ManualPublicationBatch::STATUS_PENDING_PLATFORM,
        ]);
        $persona = ManualPublicationPersona::query()->create(['name' => '受保护媒体身份']);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'account_name' => '受保护媒体账号',
            'account_uid' => '778899',
            'editor_url' => 'https://baijiahao.baidu.com/builder/rc/edit',
            'browser_adapter_enabled' => true,
        ]);
        $publication = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_POST,
            'manual_publication_batch_id' => $batch->id,
            'article_id' => $article->id,
            'persona_id' => $persona->id,
            'account_id' => $account->id,
            'assigned_admin_id' => $admin->id,
            'platform' => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            'target_url' => $account->editor_url,
            'content' => '正文',
            'content_fingerprint' => hash('sha256', 'protected-media-content'),
            'identity_snapshot' => ['account' => ['account_uid' => '778899']],
            'status' => ManualPublication::STATUS_READY,
            'status_changed_at' => now(),
            'revision' => 1,
            'publication_payload' => [
                'schema_version' => 3,
                'target_action' => 'baijiahao_article',
                'required_extension_version' => '0.3.0',
                'draft_policy' => 'draft_only',
                'body_plain' => '正文',
            ],
        ]);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($bytes);
        $sha256 = hash('sha256', $bytes);
        $mediaKey = 'media_12345678';
        $storagePath = 'self-media/test/'.$mediaKey.'.png';
        Storage::disk('local')->put($storagePath, $bytes);
        SelfMediaMediaSnapshot::query()->create([
            'manual_publication_batch_id' => $batch->id,
            'media_key' => $mediaKey,
            'position' => 1,
            'source_type' => 'body_markdown',
            'source_url' => 'https://www.example.com/image.png',
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'sha256' => $sha256,
            'mime_type' => 'image/png',
            'file_size' => strlen($bytes),
            'width' => 1,
            'height' => 1,
            'status' => 'ready',
        ]);
        $ownerToken = $admin->createToken('Media owner Chrome', ['browser-operations:read', 'browser-operations:execute']);
        $otherToken = $admin->createToken('Other Chrome', ['browser-operations:read', 'browser-operations:execute']);
        $ownerHeaders = $this->authenticatedHeaders($ownerToken->plainTextToken, '0.3.0');
        $otherHeaders = $this->authenticatedHeaders($otherToken->plainTextToken, '0.3.0');
        $mediaUrl = '/api/v1/manual-publications/'.$publication->id.'/media/'.$mediaKey;

        $this->withHeaders($ownerHeaders)->get($mediaUrl)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');

        $this->withHeaders($ownerHeaders + ['X-Idempotency-Key' => 'claim-protected-media-owner'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 1])
            ->assertOk();

        $this->withHeaders($otherHeaders)->get($mediaUrl)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');

        $this->withHeaders($ownerHeaders)->get($mediaUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-SHA256', $sha256)
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertStreamedContent($bytes);

        $this->withHeaders($ownerHeaders + ['X-Idempotency-Key' => 'release-protected-media-owner'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/release', ['revision' => 2])
            ->assertOk();
        $this->withHeaders($ownerHeaders)->get($mediaUrl)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');

        $this->withHeaders($ownerHeaders + ['X-Idempotency-Key' => 'reclaim-protected-media-owner'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => 3])
            ->assertOk();
        $this->withHeaders($ownerHeaders + ['X-Idempotency-Key' => 'disable-structural-adapter-owner'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/adapter-failure', [
                'revision' => 4,
                'adapter_version' => '0.3.0',
                'target_origin' => 'https://baijiahao.baidu.com',
                'finished_at' => now()->toIso8601String(),
                'error_code' => 'draft_heading_mismatch',
            ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_FAILED)
            ->assertJsonPath('data.publication.account.browser_adapter_enabled', false);
        $this->assertFalse($account->refresh()->browser_adapter_enabled);
        $this->assertTrue($publication->refresh()->execution_receipt['adapter_auto_disabled']);
        $this->withHeaders($ownerHeaders)->get($mediaUrl)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'claim_owned_by_another_client');
    }

    /** @return array<string,string> */
    private function browserHeaders(): array
    {
        return [
            'X-GEOFlow-Browser-Protocol' => '1',
            'X-GEOFlow-Client-Version' => '0.1.0',
        ];
    }

    /** @return array<string,string> */
    private function authenticatedHeaders(string $plainToken, string $version = '0.1.0'): array
    {
        return array_replace($this->browserHeaders(), [
            'X-GEOFlow-Client-Version' => $version,
            'Authorization' => 'Bearer '.$plainToken,
        ]);
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('browser_admin_'),
            'password' => 'secret-123',
            'email' => uniqid('browser-').'@example.com',
            'display_name' => 'Browser Operator',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
