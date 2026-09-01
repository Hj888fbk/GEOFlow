<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChannelVariant;
use App\Models\ContentMaster;
use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\Prompt;
use App\Models\PromptRecipeVersion;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HengjiaContentCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reads_native_geoflow_tasks_prompts_runs_and_evidence_gaps(): void
    {
        $admin = $this->admin('super_admin');
        $prompt = Prompt::query()->create([
            'name' => '恒佳采购选型生产提示词',
            'type' => 'content',
            'content' => '围绕采购问题生成可核验内容。',
            'variables' => '',
        ]);
        $task = Task::query()->create([
            'name' => 'KXT 橡胶软接头采购选型',
            'status' => 'active',
            'prompt_id' => $prompt->getKey(),
            'content_brief' => [
                'product_key' => 'KXT 橡胶软接头',
                'page_role' => 'procurement_guide',
                'image_keywords' => ['KXT', '橡胶软接头'],
            ],
        ]);
        TaskRun::query()->create([
            'task_id' => $task->getKey(),
            'status' => 'failed',
            'error_message' => '没有找到与产品、标题关键词和图片标签匹配的图片。',
            'meta' => [
                'requires_input' => true,
                'reason_code' => 'matching_image_missing',
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hengjia-content.today'))
            ->assertOk()
            ->assertSee('恒佳原生内容驾驶舱')
            ->assertSee('KXT 橡胶软接头采购选型')
            ->assertSee('恒佳采购选型生产提示词')
            ->assertSee('matching_image_missing')
            ->assertSee('不再维护第二套内容项目')
            ->assertSee(route('admin.tasks.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.ai-prompts'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.manual-publications.index'), false);
    }

    public function test_legacy_workspace_get_routes_redirect_to_native_geoflow_routes(): void
    {
        $admin = $this->admin('super_admin');
        $routes = [
            'admin.hengjia-content.tasks' => 'admin.tasks.index',
            'admin.hengjia-content.evidence' => 'admin.knowledge-bases.index',
            'admin.hengjia-content.studio' => 'admin.articles.index',
            'admin.hengjia-content.previews' => 'admin.articles.index',
            'admin.hengjia-content.publishing' => 'admin.manual-publications.index',
            'admin.hengjia-content.governance' => 'admin.ai-prompts',
        ];

        foreach ($routes as $legacyRoute => $nativeRoute) {
            $this->actingAs($admin, 'admin')
                ->get(route($legacyRoute))
                ->assertRedirect(route($nativeRoute))
                ->assertSessionHas('message');
        }
    }

    public function test_accounts_compatibility_route_uses_native_permissions(): void
    {
        $standardAdmin = $this->admin('admin');
        $this->actingAs($standardAdmin, 'admin')
            ->get(route('admin.hengjia-content.accounts'))
            ->assertRedirect(route('admin.manual-publications.index'));
        $this->get(route('admin.hengjia-content.today'))
            ->assertOk()
            ->assertSee(route('admin.manual-publications.index'), false)
            ->assertDontSee(route('admin.manual-publications.settings.index'), false);

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->get(route('admin.hengjia-content.accounts'))
            ->assertRedirect(route('admin.manual-publications.settings.index'));
    }

    public function test_all_legacy_write_routes_are_retired_without_parallel_table_mutation(): void
    {
        $admin = $this->admin('super_admin');
        $before = $this->legacyCounts();
        $routes = [
            ['admin.hengjia-content.plan-daily', []],
            ['admin.hengjia-content.tasks.store', []],
            ['admin.hengjia-content.evidence.store', []],
            ['admin.hengjia-content.sources.sync', []],
            ['admin.hengjia-content.sources.approve', ['sourceFile' => 999]],
            ['admin.hengjia-content.tasks.generate', ['contentTask' => 999]],
            ['admin.hengjia-content.masters.approve', ['contentMaster' => 999]],
            ['admin.hengjia-content.masters.promote', ['contentMaster' => 999]],
            ['admin.hengjia-content.masters.rollback', ['contentMaster' => 999]],
            ['admin.hengjia-content.masters.variants', ['contentMaster' => 999]],
            ['admin.hengjia-content.variants.approve', ['channelVariant' => 999]],
            ['admin.hengjia-content.variants.prepare', ['channelVariant' => 999]],
            ['admin.hengjia-content.variants.readback', ['channelVariant' => 999]],
            ['admin.hengjia-content.accounts.store', []],
            ['admin.hengjia-content.accounts.update', ['platformAccount' => 999]],
            ['admin.hengjia-content.accounts.disable', ['platformAccount' => 999]],
            ['admin.hengjia-content.accounts.verify', ['platformAccount' => 999]],
            ['admin.hengjia-content.accounts.capabilities.refresh', ['platformAccount' => 999]],
        ];

        foreach ($routes as [$routeName, $parameters]) {
            $this->actingAs($admin, 'admin')
                ->post(route($routeName, $parameters), ['sentinel' => 'must-not-write'])
                ->assertRedirect()
                ->assertSessionHasErrors();
            self::assertSame($before, $this->legacyCounts(), $routeName.' must remain read-only');
        }
    }

    public function test_legacy_cli_commands_are_retired_without_parallel_table_mutation(): void
    {
        $before = $this->legacyCounts();

        $this->artisan('hengjia:content-plan-daily', ['--date' => '2026-09-01', '--limit' => 3])
            ->expectsOutput('该兼容命令已停止写入平行 ContentTask；请在 GEOFlow 原生任务页创建或排期 Task。')
            ->assertExitCode(Command::FAILURE);
        self::assertSame($before, $this->legacyCounts());

        $this->artisan('hengjia:content-sync-sources')
            ->expectsOutput('该兼容命令已停止写入平行 ContentSourceFile；请通过 GEOFlow 原生知识库导入和审核资料。')
            ->assertExitCode(Command::FAILURE);
        self::assertSame($before, $this->legacyCounts());
    }

    /** @return array<string,int> */
    private function legacyCounts(): array
    {
        return [
            'content_tasks' => ContentTask::query()->count(),
            'content_source_files' => ContentSourceFile::query()->count(),
            'content_masters' => ContentMaster::query()->count(),
            'prompt_recipe_versions' => PromptRecipeVersion::query()->count(),
            'channel_variants' => ChannelVariant::query()->count(),
        ];
    }

    private function admin(string $role): Admin
    {
        $suffix = Str::lower(Str::random(8));

        return Admin::query()->create([
            'username' => $role.'_'.$suffix,
            'password' => 'test-password',
            'email' => $role.'_'.$suffix.'@example.test',
            'display_name' => $role === 'super_admin' ? '恒佳超级管理员' : '恒佳运营人员',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
