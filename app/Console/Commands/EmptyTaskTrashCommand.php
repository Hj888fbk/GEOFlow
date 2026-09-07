<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class EmptyTaskTrashCommand extends Command
{
    protected $signature = 'geoflow:empty-task-trash {--yes : Confirm permanent deletion of every task in trash}';

    protected $description = 'Permanently delete every task currently in the task trash';

    public function handle(): int
    {
        $total = Task::onlyTrashed()->count();
        $this->info(sprintf('Task trash contains %d tasks.', $total));

        if ($total === 0) {
            return self::SUCCESS;
        }

        if (! $this->option('yes')) {
            $this->error('This action is permanent. Re-run with --yes to empty the task trash.');

            return self::FAILURE;
        }

        $deleted = 0;

        DB::table('task_trash_entries')
            ->orderBy('id')
            ->chunkById(200, function ($entries) use (&$deleted): void {
                foreach ($entries as $entry) {
                    DB::transaction(function () use ($entry, &$deleted): void {
                        $task = Task::onlyTrashed()
                            ->whereKey((int) $entry->task_id)
                            ->lockForUpdate()
                            ->first();

                        if (! $task) {
                            return;
                        }

                        $trashEntryExists = DB::table('task_trash_entries')
                            ->where('task_id', (int) $task->id)
                            ->lockForUpdate()
                            ->exists();

                        if (! $trashEntryExists) {
                            return;
                        }

                        $task->forceDelete();
                        $deleted++;
                    });
                }
            });

        $remaining = Task::onlyTrashed()->count();
        $this->info(sprintf('Permanently deleted %d tasks from trash; %d remain.', $deleted, $remaining));

        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }
}
