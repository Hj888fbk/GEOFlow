<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\CatalogGeoFlowService;
use App\Services\GeoFlow\PromptBuilderService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HengjiaPromptBuilderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guided_prompt_is_saved_as_candidate_and_requires_explicit_activation_for_production_catalogs(): void
    {
        $admin = $this->admin('prompt_candidate_admin');
        Category::query()->create([
            'name' => '提示词工作流分类',
            'slug' => 'prompt-workflow-category',
            'sort_order' => 1,
        ]);
        $traditional = Prompt::query()->create([
            'name' => '传统生产提示词',
            'type' => 'content',
            'content' => '请围绕 {{title}} 生成正文。',
            'variables' => '',
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.ai-prompts.store'), [
            'name' => '恒佳橡胶软接头采购向导 v1',
            'type' => 'content',
            'builder_mode' => 'guided',
            'builder_config' => $this->builderConfig(),
        ]);

        $candidate = Prompt::query()->where('name', '恒佳橡胶软接头采购向导 v1')->sole();
        $response->assertRedirect(route('admin.ai-prompts.edit', ['promptId' => $candidate->id]));
        self::assertTrue($candidate->isGuidedContentPrompt());
        self::assertSame(Prompt::BUILDER_STATUS_CANDIDATE, $candidate->builderStatus());
        self::assertStringContainsString('同行官网、第三方页面和 AI 回答只能学习结构', $candidate->content);

        $catalogPromptIds = collect(app(CatalogGeoFlowService::class)->getCatalog()['prompts'])->pluck('id');
        self::assertTrue($catalogPromptIds->contains($traditional->id));
        self::assertFalse($catalogPromptIds->contains($candidate->id));
        $this->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('传统生产提示词')
            ->assertDontSee('恒佳橡胶软接头采购向导 v1');
        $this->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee('传统生产提示词')
            ->assertDontSee('恒佳橡胶软接头采购向导 v1');

        $this->post(route('admin.ai-prompts.activate', ['promptId' => $candidate->id]))
            ->assertRedirect(route('admin.ai-prompts'))
            ->assertSessionHas('message');

        self::assertSame(Prompt::BUILDER_STATUS_ACTIVE, $candidate->fresh()->builderStatus());
        self::assertTrue(collect(app(CatalogGeoFlowService::class)->getCatalog()['prompts'])->pluck('id')->contains($candidate->id));
        $this->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('恒佳橡胶软接头采购向导 v1');
        $this->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee('恒佳橡胶软接头采购向导 v1');
    }

    public function test_editing_an_active_guided_prompt_creates_a_new_candidate_without_rebinding_existing_tasks(): void
    {
        $admin = $this->admin('prompt_version_admin');
        $config = $this->builderConfig([
            'lineage_id' => 'd672ab70-1992-4cef-995a-4bb37cd57a21',
            'production_status' => Prompt::BUILDER_STATUS_ACTIVE,
        ]);
        $active = Prompt::query()->create([
            'name' => '恒佳采购向导 v1',
            'type' => 'content',
            'content' => app(PromptBuilderService::class)->compile($config),
            'variables' => Prompt::encodeBuilderConfig($config),
        ]);
        $task = Task::query()->create([
            'name' => '已绑定生产任务',
            'prompt_id' => $active->id,
            'status' => 'paused',
        ]);
        $originalContent = $active->content;

        $updatedConfig = $this->builderConfig([
            'procurement_direction' => '新版候选增加法兰标准、位移方向和检测报告核验。',
        ]);
        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-prompts.update', ['promptId' => $active->id]), [
                'name' => '恒佳采购向导 v2 候选',
                'type' => 'content',
                'builder_mode' => 'guided',
                'builder_config' => $updatedConfig,
            ])
            ->assertRedirect();

        $candidate = Prompt::query()->whereKeyNot($active->id)->where('name', '恒佳采购向导 v2 候选')->sole();
        self::assertSame($originalContent, $active->fresh()->content);
        self::assertSame(Prompt::BUILDER_STATUS_ACTIVE, $active->fresh()->builderStatus());
        self::assertSame($active->id, $task->fresh()->prompt_id);
        self::assertSame(Prompt::BUILDER_STATUS_CANDIDATE, $candidate->builderStatus());
        self::assertSame($active->builderConfig()['lineage_id'], $candidate->builderConfig()['lineage_id']);
        self::assertStringContainsString('新版候选增加法兰标准', $candidate->content);
    }

    public function test_ai_suggestions_are_returned_without_creating_or_activating_a_prompt(): void
    {
        MarkdownContentWriterAgent::fake([
            "1. 还需要确认法兰标准吗？\n2. 技术人员与采购人员关注点不同。\n3. 请补充检测报告与工况输入。",
        ])->preventStrayPrompts();
        $admin = $this->admin('prompt_suggestion_admin');
        $model = AiModel::query()->create([
            'name' => '提示词检查模型',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'prompt-suggestion-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $before = Prompt::query()->count();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-prompts.builder-suggest'), [
                'ai_model_id' => $model->id,
                'builder_config' => $this->builderConfig(),
            ])
            ->assertOk()
            ->assertJsonPath('suggestions', "1. 还需要确认法兰标准吗？\n2. 技术人员与采购人员关注点不同。\n3. 请补充检测报告与工况输入。")
            ->assertJsonPath('notice', '这些内容只是候选检查项；只有点击“采用建议”并保存候选后才会进入提示词配置。');

        self::assertSame($before, Prompt::query()->count());
        MarkdownContentWriterAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, '不生成文章')
                && str_contains($prompt->prompt, '不要断言参数、资质、排名、产能、价格或企业能力'),
        );
    }

    public function test_article_editor_rejects_a_guided_candidate_even_if_its_id_is_submitted_directly(): void
    {
        MarkdownContentWriterAgent::fake()->preventStrayPrompts();
        $admin = $this->admin('prompt_candidate_guard_admin');
        $config = $this->builderConfig([
            'lineage_id' => '65707ada-e256-45d3-836a-ab7a9674e4c1',
            'production_status' => Prompt::BUILDER_STATUS_CANDIDATE,
        ]);
        $candidate = Prompt::query()->create([
            'name' => '不可直接执行的候选提示词',
            'type' => 'content',
            'content' => app(PromptBuilderService::class)->compile($config),
            'variables' => Prompt::encodeBuilderConfig($config),
        ]);
        $knowledge = KnowledgeBase::query()->create([
            'name' => '候选拦截知识库',
            'content' => '已审核内容。',
            'review_status' => 'reviewed',
        ]);
        $model = AiModel::query()->create([
            'name' => '候选拦截模型',
            'model_id' => 'candidate-guard-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '候选提示词不能生成文章',
                'keyword' => '橡胶软接头',
                'knowledge_base_id' => $knowledge->id,
                'prompt_id' => $candidate->id,
                'ai_model_id' => $model->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt_id');

        MarkdownContentWriterAgent::assertNeverPrompted();
    }

    public function test_task_api_rejects_a_guided_candidate_even_if_its_id_is_submitted_directly(): void
    {
        $admin = $this->admin('prompt_candidate_task_api_admin');
        $token = $admin->createToken('candidate-task-api', ['tasks:write'])->plainTextToken;
        $config = $this->builderConfig([
            'lineage_id' => 'a7787a62-c04c-44f7-af0c-d197a09d6968',
            'production_status' => Prompt::BUILDER_STATUS_CANDIDATE,
        ]);
        $candidate = Prompt::query()->create([
            'name' => 'API 不可选择的候选提示词',
            'type' => 'content',
            'content' => app(PromptBuilderService::class)->compile($config),
            'variables' => Prompt::encodeBuilderConfig($config),
        ]);
        $model = AiModel::query()->create([
            'name' => '候选任务 API 模型',
            'model_id' => 'candidate-task-api-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $titles = TitleLibrary::query()->create(['name' => '候选任务 API 标题库']);

        $this->withToken($token)->postJson('/api/v1/tasks', [
            'name' => '不应创建的候选提示词任务',
            'title_library_id' => $titles->id,
            'prompt_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'status' => 'paused',
            'category_mode' => 'smart',
            'draft_limit' => 1,
            'article_limit' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.prompt_id', '选择的内容提示词不存在，或候选版本尚未启用');

        self::assertSame(0, Task::query()->where('name', '不应创建的候选提示词任务')->count());
    }

    public function test_task_api_update_rejects_a_guided_candidate_without_rebinding_the_task(): void
    {
        $admin = $this->admin('prompt_candidate_task_update_admin');
        $token = $admin->createToken('candidate-task-update-api', ['tasks:write'])->plainTextToken;
        $traditional = Prompt::query()->create([
            'name' => '任务原有生产提示词',
            'type' => 'content',
            'content' => '请围绕 {{title}} 生成正文。',
            'variables' => '',
        ]);
        $candidateConfig = $this->builderConfig([
            'lineage_id' => 'e5885ad7-c709-4e7d-8568-62cc19ac5fb4',
            'production_status' => Prompt::BUILDER_STATUS_CANDIDATE,
        ]);
        $candidate = Prompt::query()->create([
            'name' => 'API 更新不可绑定的候选提示词',
            'type' => 'content',
            'content' => app(PromptBuilderService::class)->compile($candidateConfig),
            'variables' => Prompt::encodeBuilderConfig($candidateConfig),
        ]);
        $task = Task::query()->create([
            'name' => '保留原提示词的任务',
            'prompt_id' => $traditional->id,
            'status' => 'paused',
        ]);

        $this->withToken($token)->patchJson('/api/v1/tasks/'.$task->id, [
            'prompt_id' => $candidate->id,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field_errors.prompt_id', '选择的内容提示词不存在，或候选版本尚未启用');

        self::assertSame($traditional->id, $task->fresh()->prompt_id);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function builderConfig(array $overrides = []): array
    {
        return array_replace([
            'procurement_problem' => '如何选择适合实际工况的橡胶软接头？',
            'product_line' => 'KXT/JGD 橡胶软接头',
            'target_user' => '工业采购、技术与设备维护人员',
            'audience_profile' => '需要核对参数边界、供应商证据并形成询价清单',
            'decision_stage' => 'selection',
            'buyer_questions' => ['需要提供哪些工况？', '哪些参数必须由厂家确认？'],
            'procurement_direction' => '介质、温度、压力、口径、连接、位移、检测和非标',
            'page_role' => 'procurement_selection',
            'desired_action' => '整理工况、图纸和连接要求后进入技术确认',
            'tone' => 'professional_restrained',
            'allowed_evidence' => '已审核且允许公开的恒佳知识库资料',
            'forbidden_claims' => '无来源资质、参数、寿命、产能、库存、交期、价格和排名',
            'target_channels' => '恒佳官网、B2B 与自媒体',
        ], $overrides);
    }

    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'test-password',
            'email' => $username.'@example.test',
            'display_name' => '恒佳提示词运营',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
