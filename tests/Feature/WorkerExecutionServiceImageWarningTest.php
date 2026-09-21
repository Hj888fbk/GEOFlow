<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceImageWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_image_library_is_logged_when_task_requires_images(): void
    {
        Log::spy();
        $library = ImageLibrary::query()->create(['name' => '空图片库']);
        $task = Task::query()->create([
            'name' => 'Task with empty image library',
            'image_library_id' => $library->id,
            'image_count' => 2,
        ]);

        $result = $this->insertTaskImages($task, '正文内容', 123);

        $this->assertSame('正文内容', $result['content']);
        $this->assertSame([], $result['images']);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, '图库中没有可选图片')
                && (int) ($context['task_id'] ?? 0) === (int) $task->id
                && ($context['task_run_id'] ?? null) === 123
                && (int) ($context['image_library_id'] ?? 0) === (int) $library->id
                && (int) ($context['image_count'] ?? 0) === 2,
        );
    }

    public function test_no_warning_when_task_has_no_image_requirement(): void
    {
        Log::spy();
        $task = Task::query()->create(['name' => 'Task without images']);

        $result = $this->insertTaskImages($task, '正文内容');

        $this->assertSame('正文内容', $result['content']);
        $this->assertSame([], $result['images']);
        Log::shouldNotHaveReceived('warning');
    }

    /** @return array{content:string,images:list<Image>} */
    private function insertTaskImages(Task $task, string $content, ?int $taskRunId = null): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'insertTaskImagesIntoContent');
        $method->setAccessible(true);

        return $method->invoke($service, $task, $content, '标题', '关键词', $taskRunId);
    }
}
