<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmptyTaskTrashCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_explicit_confirmation_before_emptying_task_trash(): void
    {
        $trashed = Task::query()->create(['name' => 'Recent trashed task', 'status' => 'paused']);
        $trashed->delete();

        $this->artisan('geoflow:empty-task-trash')
            ->expectsOutput('Task trash contains 1 tasks.')
            ->expectsOutput('This action is permanent. Re-run with --yes to empty the task trash.')
            ->assertFailed();

        $this->assertNotNull(Task::onlyTrashed()->find($trashed->id));
        $this->assertDatabaseHas('task_trash_entries', ['task_id' => $trashed->id]);
    }

    public function test_confirmed_command_deletes_all_trashed_tasks_and_preserves_active_tasks(): void
    {
        $first = Task::query()->create(['name' => 'First trashed task', 'status' => 'paused']);
        $second = Task::query()->create(['name' => 'Second trashed task', 'status' => 'paused']);
        $active = Task::query()->create(['name' => 'Active retained task', 'status' => 'paused']);
        $first->delete();
        $second->delete();

        $this->artisan('geoflow:empty-task-trash', ['--yes' => true])
            ->expectsOutput('Task trash contains 2 tasks.')
            ->expectsOutput('Permanently deleted 2 tasks from trash; 0 remain.')
            ->assertSuccessful();

        $this->assertNull(Task::withTrashed()->find($first->id));
        $this->assertNull(Task::withTrashed()->find($second->id));
        $this->assertNotNull(Task::query()->find($active->id));
        $this->assertDatabaseCount('task_trash_entries', 0);
    }

    public function test_confirmed_command_is_successful_when_task_trash_is_already_empty(): void
    {
        $active = Task::query()->create(['name' => 'Active retained task', 'status' => 'paused']);

        $this->artisan('geoflow:empty-task-trash', ['--yes' => true])
            ->expectsOutput('Task trash contains 0 tasks.')
            ->assertSuccessful();

        $this->assertNotNull(Task::query()->find($active->id));
    }
}
