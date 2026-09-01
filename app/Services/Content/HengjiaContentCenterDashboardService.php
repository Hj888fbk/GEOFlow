<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\ManualPublication;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TitleLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HengjiaContentCenterDashboardService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $tasks = Task::query()
            ->with([
                'titleLibrary:id,name',
                'prompt:id,name,type,variables',
                'author:id,name',
                'imageLibrary:id,name',
            ])
            ->withCount(['articles', 'taskRuns'])
            ->latest('updated_at')
            ->limit(12)
            ->get();
        $recentRuns = TaskRun::query()
            ->with('task:id,name')
            ->latest('id')
            ->limit(20)
            ->get();
        $gaps = $this->evidenceGaps($tasks, $recentRuns);
        $contentPrompts = Prompt::query()
            ->where('type', 'content')
            ->orderByDesc('id')
            ->get(['id', 'type', 'variables']);
        $productionPromptCount = $contentPrompts
            ->filter(static fn (Prompt $prompt): bool => $prompt->isAvailableForProductionTask())
            ->count();

        return [
            'stats' => [
                'active_tasks' => Task::query()->where('status', 'active')->count(),
                'paused_tasks' => Task::query()->where('status', 'paused')->count(),
                'pending_review_articles' => Article::query()->where('review_status', 'pending')->count(),
                'evidence_gaps' => count($gaps),
                'manual_attention' => ManualPublication::query()->whereIn('status', [
                    ManualPublication::STATUS_READY,
                    ManualPublication::STATUS_IN_PROGRESS,
                    ManualPublication::STATUS_FAILED,
                    ManualPublication::STATUS_OUTCOME_UNKNOWN,
                ])->count(),
                'production_prompts' => $productionPromptCount,
            ],
            'tasks' => $tasks,
            'evidence_gaps' => $gaps,
            'recent_runs' => $recentRuns->take(10)->values(),
            'pipeline' => [
                'tasks' => Task::query()->count(),
                'generated_articles' => Article::query()->count(),
                'pending_review' => Article::query()->where('review_status', 'pending')->count(),
                'approved' => Article::query()->where('review_status', 'approved')->count(),
                'published' => Article::query()->where('status', 'published')->count(),
                'remote_synced' => ArticleDistribution::query()->where('status', 'synced')->count(),
                'crawl_confirmed' => null,
                'indexed_confirmed' => null,
                'ai_mentioned' => null,
                'leads_attributed' => null,
            ],
            'assets' => [
                'title_libraries' => TitleLibrary::query()->count(),
                'knowledge_bases' => KnowledgeBase::query()->count(),
                'content_prompts' => $productionPromptCount,
                'authors' => Author::query()->count(),
                'image_libraries' => ImageLibrary::query()->count(),
                'images' => Image::query()->count(),
            ],
            'legacy_read_only' => [
                'content_tasks' => $this->legacyCount('content_tasks'),
                'content_masters' => $this->legacyCount('content_masters'),
                'prompt_recipe_versions' => $this->legacyCount('prompt_recipe_versions'),
                'channel_variants' => $this->legacyCount('channel_variants'),
            ],
        ];
    }

    /**
     * @param  Collection<int,Task>  $tasks
     * @param  Collection<int,TaskRun>  $runs
     * @return list<array<string,mixed>>
     */
    private function evidenceGaps(Collection $tasks, Collection $runs): array
    {
        $gaps = [];
        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $reasonCode = trim((string) ($meta['reason_code'] ?? ''));
            if (! (bool) ($meta['requires_input'] ?? false) && $reasonCode === '') {
                continue;
            }
            $taskId = (int) ($run->task_id ?? 0);
            $gaps['run:'.$run->id] = [
                'source' => 'task_run',
                'task_id' => $taskId,
                'task_name' => (string) ($run->task?->name ?? ('任务 #'.$taskId)),
                'reason_code' => $reasonCode !== '' ? $reasonCode : 'requires_input',
                'message' => trim((string) ($run->error_message ?? '')) ?: $this->gapMessage($reasonCode),
                'occurred_at' => optional($run->finished_at ?? $run->created_at)?->format('Y-m-d H:i'),
            ];
            if (count($gaps) >= 8) {
                return array_values($gaps);
            }
        }

        foreach ($tasks as $task) {
            $brief = is_array($task->content_brief) ? $task->content_brief : [];
            if ($brief === []) {
                continue;
            }
            if ((int) ($task->image_library_id ?? 0) > 0 && (int) ($task->image_count ?? 0) > 0) {
                continue;
            }
            $gaps['task:'.$task->id] = [
                'source' => 'task',
                'task_id' => (int) $task->id,
                'task_name' => (string) $task->name,
                'reason_code' => 'image_configuration_missing',
                'message' => '该任务已填写文章结构，但尚未配置图片库或图片数量。影子增强启用后会先阻断，不会随机错配图片。',
                'occurred_at' => optional($task->updated_at)?->format('Y-m-d H:i'),
            ];
            if (count($gaps) >= 8) {
                break;
            }
        }

        return array_values($gaps);
    }

    private function gapMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'matching_image_missing' => '没有找到与产品、标题关键词和图片标签匹配的图片。',
            'image_library_missing' => '任务尚未选择图片库。',
            'image_count_missing' => '任务尚未设置需要插入的图片数量。',
            'image_keywords_missing' => '任务缺少可用于选图的产品或图片关键词。',
            default => '该任务需要补充资料后才能继续。',
        };
    }

    private function legacyCount(string $table): int
    {
        return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
    }
}
