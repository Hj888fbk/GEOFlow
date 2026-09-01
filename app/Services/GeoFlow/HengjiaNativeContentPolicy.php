<?php

namespace App\Services\GeoFlow;

use App\Models\Task;

final class HengjiaNativeContentPolicy
{
    public function enabledFor(Task $task): bool
    {
        if (! (bool) config('hengjia-content.native_flow.enabled', false)) {
            return false;
        }

        $taskIds = collect(config('hengjia-content.native_flow.test_task_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return $taskIds !== [] && in_array((int) $task->getKey(), $taskIds, true);
    }

    public function generationRules(Task $task): string
    {
        if (! $this->enabledFor($task)) {
            return '';
        }

        $rules = config('hengjia-content.native_flow.generation_rules', []);
        if (! is_array($rules)) {
            return '';
        }

        $lines = [];
        foreach ($rules as $rule) {
            $rule = trim(is_string($rule) || is_numeric($rule) ? (string) $rule : '');
            if ($rule === '') {
                continue;
            }

            $lines[] = (count($lines) + 1).'. '.$rule;
        }

        return implode("\n", $lines);
    }
}
