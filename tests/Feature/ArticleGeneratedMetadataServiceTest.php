<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\ArticleGeneratedMetadataService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArticleGeneratedMetadataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_prompts_generate_keywords_and_description_while_preserving_focus_keyword(): void
    {
        Http::fakeSequence()
            ->push($this->completion('恒佳，恒佳橡胶软接头，可曲挠橡胶接头'))
            ->push($this->completion('描述：恒佳依据产品资料说明橡胶软接头的作用、选型条件与使用边界，具体参数以项目和订单书面确认为准。'));

        $admin = Admin::query()->create([
            'username' => 'metadata-worker',
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Metadata Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'metadata-test-model',
            'model_type' => 'chat',
            'api_url' => 'https://metadata.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task = Task::query()->create([
            'name' => 'Metadata task',
            'auto_keywords' => 1,
            'auto_description' => 1,
        ]);
        $task->forceFill([
            'ai_model_id' => $model->id,
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => (string) Str::uuid(),
        ])->save();
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run);
        Prompt::query()->create([
            'name' => 'Keyword prompt',
            'type' => 'keyword',
            'content' => '标题：{{title}} 主关键词：{{keyword}} 正文：{{content}}',
        ]);
        Prompt::query()->create([
            'name' => 'Description prompt',
            'type' => 'description',
            'content' => '请根据 {{title}}、{{keyword}} 和 {{content}} 生成描述。',
        ]);

        $result = app(ArticleGeneratedMetadataService::class)->generate(
            $task,
            $context,
            $model,
            '橡胶软接头能解决什么问题',
            '橡胶软接头作用',
            '恒佳产品资料用于说明选型条件。',
            '回退描述',
        );

        $this->assertSame('橡胶软接头作用，恒佳，恒佳橡胶软接头，可曲挠橡胶接头', $result['keywords']);
        $this->assertSame(
            '恒佳依据产品资料说明橡胶软接头的作用、选型条件与使用边界，具体参数以项目和订单书面确认为准。',
            $result['meta_description'],
        );
        $this->assertSame('generated', $result['keyword_status']);
        $this->assertSame('generated', $result['description_status']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $decoded = json_decode($request->body(), true);
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($body)
                && str_contains($body, '橡胶软接头作用')
                && str_contains($body, '恒佳产品资料用于说明选型条件');
        });
    }

    public function test_keyword_normalization_removes_model_punctuation_and_preserves_all_terms(): void
    {
        Http::fakeSequence()
            ->push($this->completion('关键词：法兰，恒佳，恒佳鸭嘴?，橡胶鸭嘴？、鸭嘴止回阀，XH41-F法兰式橡胶鸭嘴阀'))
            ->push($this->completion('描述'));

        $admin = Admin::query()->create([
            'username' => 'metadata-normalize-admin',
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Metadata Normalize Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'metadata-normalize-model',
            'model_type' => 'chat',
            'api_url' => 'https://metadata-normalize.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task = Task::query()->create([
            'name' => 'Metadata normalize task',
            'auto_keywords' => 1,
            'auto_description' => 0,
        ]);
        $task->forceFill([
            'ai_model_id' => $model->id,
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => (string) Str::uuid(),
        ])->save();
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run);
        Prompt::query()->create([
            'name' => 'Keyword normalize prompt',
            'type' => 'keyword',
            'content' => '标题：{{title}} 主关键词：{{keyword}} 正文：{{content}}',
        ]);

        $result = app(ArticleGeneratedMetadataService::class)->generate(
            $task,
            $context,
            $model,
            '法兰式橡胶鸭嘴阀',
            '法兰',
            '产品资料',
            '回退描述',
        );

        $this->assertSame('法兰，恒佳，恒佳鸭嘴，橡胶鸭嘴，鸭嘴止回阀，XH41-F法兰式橡胶鸭嘴阀', $result['keywords']);
    }

    /** @return array<string,mixed> */
    private function completion(string $content): array
    {
        return [
            'model' => 'metadata-test-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }
}
