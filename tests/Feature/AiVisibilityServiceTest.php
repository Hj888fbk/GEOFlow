<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiVisibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_doubao_search_custom_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_search_1',
                'Result' => [
                    'WebResults' => [
                        [
                            'Title' => 'GEOFlow 介绍',
                            'SiteName' => 'GEOFlow',
                            'Url' => 'https://example.com/geoflow',
                            'Snippet' => 'GEOFlow 帮助内容进入 AI 回答。',
                            'Summary' => 'GEOFlow 的 AI 可见性能力。',
                            'Content' => '完整搜索结果内容',
                            'RankScore' => 0.91,
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = $this->createSearchProvider();

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow AI 可见性', [
            'count' => 2,
        ]);

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM, $run->provider_type);
        $this->assertSame('GEOFlow AI 可见性', $run->keyword);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(1, (int) $provider->fresh()->used_today);
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'title' => 'GEOFlow 介绍',
            'domain' => 'example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.feedcoopapi.com/search_api/web_search'
            && $request->hasHeader('Authorization', 'Bearer test-search-key')
            && $request['Query'] === 'GEOFlow AI 可见性'
            && $request['Count'] === 2
            && ($request['Filter']['NeedContent'] ?? null) === true
            && ! array_key_exists('Sites', $request['Filter'])
            && ! array_key_exists('BlockHosts', $request['Filter']));
    }

    public function test_it_uses_source_provider_metadata_as_default_search_options(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_search_metadata',
                'Result' => [
                    'WebResults' => [],
                ],
            ]),
        ]);

        $provider = $this->createSearchProvider([
            'metadata_json' => [
                'count' => 4,
                'search_type' => 'web',
                'need_summary' => false,
                'need_content' => false,
                'need_url' => true,
                'content_formats' => 'Text',
                'auth_info_level' => 'high',
                'sites' => ['geoflow.example'],
                'block_hosts' => ['blocked.example'],
            ],
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.feedcoopapi.com/search_api/web_search'
            && $request['Query'] === 'GEOFlow'
            && $request['Count'] === 4
            && ($request['NeedSummary'] ?? null) === false
            && ($request['AuthInfoLevel'] ?? null) === 'high'
            && ($request['Filter']['NeedContent'] ?? null) === false
            && ($request['Filter']['NeedUrl'] ?? null) === true
            && ($request['Filter']['ContentFormats'] ?? null) === 'Text'
            && ($request['Filter']['Sites'] ?? null) === ['geoflow.example']
            && ($request['Filter']['BlockHosts'] ?? null) === ['blocked.example']);
    }

    public function test_it_persists_doubao_ark_responses_answer_and_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp_ark_1',
                'output' => [
                    [
                        'type' => 'web_search_call',
                        'id' => 'ws_ark_1',
                        'status' => 'completed',
                    ],
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => '豆包返回的可见性回答。',
                                'annotations' => [
                                    [
                                        'title' => 'GEOFlow source',
                                        'url' => 'https://example.com/source',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 21,
                    'output_tokens' => 34,
                ],
            ]),
        ]);

        $model = $this->createAiModel([
            'name' => '火山方舟测试模型',
            'model_id' => 'doubao-seed-2-0-lite-260428',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);

        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        $run = app(AiVisibilityService::class)->runDoubaoArkResponses(
            SystemAiIdentity::visibilityCollection(),
            $model,
            'GEOFlow',
            '请搜索 GEOFlow',
        );

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('豆包返回的可见性回答。', $run->answer_text);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'title' => 'GEOFlow source',
            'domain' => 'example.com',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses'
            && $request->hasHeader('Authorization', 'Bearer test-model-key')
            && $request['model'] === 'doubao-seed-2-0-lite-260428'
            && ($request['tools'][0]['type'] ?? null) === 'web_search'
            && ($request['input'][0]['content'][0]['text'] ?? null) === '请搜索 GEOFlow');
    }

    public function test_it_marks_doubao_search_custom_run_failed_with_provider_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'error' => ['message' => 'bad api key'],
            ], 401),
        ]);

        $provider = $this->createSearchProvider();

        try {
            app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');
            $this->fail('Expected Doubao Search Custom failure to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_provider_auth_failed', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_provider_auth_failed', (string) $run->error_message);
        $this->assertSame(0, (int) $provider->fresh()->used_today);
    }

    public function test_it_marks_doubao_search_custom_run_failed_for_embedded_provider_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'ResponseMetadata' => [
                    'RequestId' => 'request_invalid_parameter',
                    'Error' => [
                        'CodeN' => 10400,
                        'Code' => '10400',
                        'Message' => 'Invalid Parameter. Please check the parameter type.',
                    ],
                ],
                'Result' => null,
            ]),
        ]);

        $provider = $this->createSearchProvider();

        try {
            app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');
            $this->fail('Expected embedded provider error to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('10400', $exception->getMessage());
            $this->assertStringContainsString('Invalid Parameter', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('10400', (string) $run->error_message);
        $this->assertSame(0, (int) $provider->fresh()->used_today);
    }

    public function test_it_stops_deepseek_analysis_when_search_returns_no_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_empty_search',
                'Result' => ['WebResults' => []],
            ]),
        ]);
        MarkdownContentWriterAgent::fake()->preventStrayPrompts();

        $provider = $this->createSearchProvider();
        $model = $this->createAiModel();

        try {
            app(AiVisibilityService::class)->runDoubaoSearchThenDeepSeekAnalysis($provider, $model, 'GEOFlow');
            $this->fail('Expected empty search results to stop analysis.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('未返回可用于分析的信源', $exception->getMessage());
        }

        $this->assertDatabaseCount('ai_visibility_runs', 1);
        $this->assertDatabaseHas('ai_visibility_runs', [
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'status' => AiVisibilityRun::STATUS_FAILED,
        ]);
        $this->assertSame(1, (int) $provider->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->used_today);
    }

    public function test_it_does_not_call_doubao_search_custom_when_provider_limit_is_exhausted(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $provider = $this->createSearchProvider([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);

        try {
            app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');
            $this->fail('Expected exhausted source provider to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_source_provider_quota_exhausted', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_source_provider_quota_exhausted', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_it_resets_yesterdays_provider_usage_before_calling_search(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'log_new_day',
                'Result' => ['WebResults' => []],
            ]),
        ]);

        $provider = $this->createSearchProvider([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->subDay()->toDateString(),
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $provider->refresh();
        $this->assertSame(now()->toDateString(), $provider->usage_date?->toDateString());
        $this->assertSame(1, (int) $provider->used_today);
    }

    public function test_it_does_not_call_ark_responses_when_model_is_inactive(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $model = $this->createAiModel([
            'status' => 'inactive',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'model_id' => 'doubao-seed-2-0-lite-260428',
        ]);

        try {
            app(AiVisibilityService::class)->runDoubaoArkResponses(
                SystemAiIdentity::visibilityCollection(),
                $model,
                'GEOFlow',
            );
            $this->fail('Expected inactive model to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_config_access_revoked', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_it_persists_deepseek_analysis_usage_from_ai_sdk_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.deepseek.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_analysis_1',
                'model' => 'deepseek-v4-flash',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '分析完成',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 80,
                    'completion_tokens_details' => [
                        'reasoning_tokens' => 0,
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel(['max_tokens' => 6144]);

        $this->bindModel(AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY, $model);

        $run = app(AiVisibilityService::class)->runDeepSeekAnalysis(
            SystemAiIdentity::visibilityCollection(),
            $model,
            'GEOFlow',
            '请分析 GEOFlow 的 AI 可见性',
        );

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS, $run->provider_type);
        $this->assertSame('分析完成', $run->answer_text);
        $this->assertSame([
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
        ], $run->usage_json);
        $this->assertSame(1, (int) $model->fresh()->used_today);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepseek.com/v1/chat/completions'
            && ($request['model'] ?? null) === 'deepseek-v4-flash'
            && ($request['max_completion_tokens'] ?? null) === 6144
            && ! array_key_exists('max_tokens', (array) $request->data())
            && ($request['thinking']['type'] ?? null) === 'disabled');
    }

    public function test_it_reports_safe_token_diagnostics_when_deepseek_returns_only_reasoning(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.deepseek.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_analysis_reasoning_only',
                'model' => 'deepseek-v4-flash',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'reasoning_content' => '内部推理内容不应进入错误消息',
                    ],
                    'finish_reason' => 'length',
                ]],
                'usage' => [
                    'prompt_tokens' => 3500,
                    'completion_tokens' => 4096,
                    'completion_tokens_details' => [
                        'reasoning_tokens' => 4096,
                    ],
                ],
            ]),
        ]);

        $model = $this->createAiModel();

        try {
            app(AiVisibilityService::class)->runDeepSeekAnalysis($model, 'GEOFlow', '请分析 GEOFlow 的 AI 可见性');
            $this->fail('Expected reasoning-only DeepSeek response to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('输出令牌预算已用尽', $exception->getMessage());
            $this->assertStringContainsString('finish_reason=length', $exception->getMessage());
            $this->assertStringContainsString('completion_tokens=4096', $exception->getMessage());
            $this->assertStringContainsString('reasoning_tokens=4096', $exception->getMessage());
            $this->assertStringNotContainsString('内部推理内容', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->firstOrFail();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('输出令牌预算已用尽', (string) $run->error_message);
        $this->assertSame(0, (int) $model->fresh()->used_today);

        Http::assertSent(fn ($request): bool => ($request['max_completion_tokens'] ?? null) === 4096
            && ! array_key_exists('max_tokens', (array) $request->data())
            && ($request['thinking']['type'] ?? null) === 'disabled');
    }

    public function test_search_omits_empty_site_filters_saved_in_provider_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'Result' => ['WebResults' => []],
            ]),
        ]);
        $provider = $this->createSearchProvider([
            'metadata_json' => [
                'sites' => [],
                'block_hosts' => [],
                'need_content' => false,
                'need_url' => false,
            ],
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $filter = $request->data()['Filter'];

            return ! array_key_exists('Sites', $filter)
                && ! array_key_exists('BlockHosts', $filter)
                && $filter['NeedContent'] === false
                && $filter['NeedUrl'] === false
                && $filter['ContentFormats'] === 'Markdown';
        });
    }

    public function test_search_preserves_non_empty_site_filters_saved_in_provider_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'Result' => ['WebResults' => []],
            ]),
        ]);
        $provider = $this->createSearchProvider([
            'metadata_json' => [
                'sites' => ['example.com'],
                'block_hosts' => ['blocked.example.com'],
            ],
        ]);

        $run = app(AiVisibilityService::class)->runDoubaoSearchCustom($provider, 'GEOFlow');

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $filter = $request->data()['Filter'];

            return $filter['Sites'] === ['example.com']
                && $filter['BlockHosts'] === ['blocked.example.com'];
        });
    }

    private function createSearchProvider(array $overrides = []): AiSourceProvider
    {
        return AiSourceProvider::query()->create(array_merge([
            'name' => 'Doubao Search Custom',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-search-key'),
            'status' => 'active',
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
        ], $overrides));
    }

    private function createAiModel(array $overrides = []): AiModel
    {
        $owner = Admin::query()->create([
            'username' => 'visibility-owner-'.uniqid(),
            'display_name' => 'Visibility Owner',
            'password' => 'secret',
            'role' => 'super_admin',
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);
        $model = new AiModel(array_merge([
            'name' => 'DeepSeek V4 Flash',
            'version' => 'v4',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-model-key'),
            'model_id' => 'deepseek-v4-flash',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ])->save();

        return $model;
    }

    private function bindModel(string $settingKey, AiModel $model): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $settingKey],
            ['setting_value' => (string) $model->id],
        );
    }
}
