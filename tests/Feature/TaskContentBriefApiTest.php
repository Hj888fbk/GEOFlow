<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskContentBriefApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_api_can_create_update_and_clear_the_optional_content_brief(): void
    {
        [$token, $model, $prompt, $titles] = $this->dependencies();
        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->withHeaders($headers)->postJson('/api/v1/tasks', [
            'name' => '恒佳 content_brief API 测试任务',
            'title_library_id' => $titles->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
            'status' => 'paused',
            'category_mode' => 'smart',
            'draft_limit' => 3,
            'article_limit' => 3,
            'content_brief' => [
                'product_key' => 'KXT 橡胶软接头',
                'page_role' => 'procurement_selection',
                'audience' => '工业项目采购与技术人员',
                'decision_stage' => '选型比较',
                'buyer_questions' => "口径怎么选？\n需要哪些工况？\n口径怎么选？",
                'procurement_direction' => '核对介质、温度、压力、连接和位移。',
                'desired_action' => '提交完整工况后技术确认。',
                'image_keywords' => ['KXT', '橡胶软接头', 'KXT'],
            ],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.content_brief.product_key', 'KXT 橡胶软接头')
            ->assertJsonPath('data.content_brief.page_role', 'procurement_selection')
            ->assertJsonPath('data.content_brief.structure_profile', 'procurement_selection')
            ->assertJsonPath('data.content_brief.buyer_questions.0', '口径怎么选？')
            ->assertJsonPath('data.content_brief.buyer_questions.1', '需要哪些工况？')
            ->assertJsonCount(2, 'data.content_brief.buyer_questions')
            ->assertJsonCount(2, 'data.content_brief.image_keywords');
        $taskId = (int) $created->json('data.id');

        $this->withHeaders($headers)->patchJson('/api/v1/tasks/'.$taskId, [
            'content_brief' => [
                'product_key' => 'JGD 橡胶软接头',
                'page_role' => 'technical_qa',
                'structure_profile' => 'technical_qa',
                'buyer_questions' => ['负压工况是否适用？'],
                'image_keywords' => 'JGD；安装现场',
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', '恒佳 content_brief API 测试任务')
            ->assertJsonPath('data.content_brief.product_key', 'JGD 橡胶软接头')
            ->assertJsonPath('data.content_brief.page_role', 'technical_qa')
            ->assertJsonPath('data.content_brief.image_keywords.1', '安装现场');

        $stored = Task::query()->findOrFail($taskId);
        self::assertIsArray($stored->content_brief);
        self::assertSame('technical_qa', $stored->content_brief['structure_profile']);

        $this->withHeaders($headers)->patchJson('/api/v1/tasks/'.$taskId, [
            'content_brief' => null,
        ])->assertOk()
            ->assertJsonPath('data.content_brief', []);

        self::assertNull(Task::query()->findOrFail($taskId)->content_brief);
    }

    public function test_task_api_rejects_unknown_content_roles_without_writing_partial_data(): void
    {
        [$token, $model, $prompt, $titles] = $this->dependencies('content_brief_invalid_admin');

        $this->withToken($token)->postJson('/api/v1/tasks', [
            'name' => '无效页面职责任务',
            'title_library_id' => $titles->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
            'status' => 'paused',
            'category_mode' => 'smart',
            'draft_limit' => 1,
            'article_limit' => 1,
            'content_brief' => [
                'page_role' => 'invented_role',
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.content_brief', '页面职责不在支持范围内');

        self::assertSame(0, Task::query()->where('name', '无效页面职责任务')->count());
    }

    /** @return array{string,AiModel,Prompt,TitleLibrary} */
    private function dependencies(string $username = 'content_brief_api_admin'): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'test-password',
            'email' => $username.'@example.test',
            'display_name' => 'Content brief API Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $token = $admin->createToken('content-brief-api-test', ['tasks:read', 'tasks:write'])->plainTextToken;
        $model = AiModel::query()->create([
            'name' => 'Content Brief Chat Model '.$username,
            'model_id' => 'content-brief-chat-'.$username,
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Content Brief Prompt '.$username,
            'type' => 'content',
            'content' => '请根据 {{title}} 和 {{knowledge}} 生成正文。',
            'variables' => '',
        ]);
        $titles = TitleLibrary::query()->create([
            'name' => 'Content Brief Titles '.$username,
            'description' => '',
            'title_count' => 0,
        ]);

        return [$token, $model, $prompt, $titles];
    }
}
