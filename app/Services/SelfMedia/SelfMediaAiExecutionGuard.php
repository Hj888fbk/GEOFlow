<?php

namespace App\Services\SelfMedia;

use App\Models\AiModel;
use App\Models\Task;
use App\Services\GeoFlow\AiExecutionAccessGuard;
use App\Services\GeoFlow\AiExecutionContextFactory;

final readonly class SelfMediaAiExecutionGuard
{
    public function __construct(
        private AiExecutionContextFactory $contextFactory,
        private AiExecutionAccessGuard $accessGuard,
    ) {}

    /**
     * @return array{model_access_admin_id:?int,model_access_admin_role:?string,ai_config_access_version:?int,requested_ai_model_id:?int,requested_ai_model_snapshot:?array<string,mixed>,resolver_policy_version:?int}
     */
    public function snapshotForTask(?int $taskId): array
    {
        if ($taskId === null || $taskId <= 0) {
            return $this->emptySnapshot();
        }

        $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
        if (! $task instanceof Task) {
            return $this->emptySnapshot();
        }

        $identity = $this->contextFactory->taskRunIdentity($task);
        $snapshot = [
            'model_access_admin_id' => $identity['model_access_admin_id'],
            'model_access_admin_role' => $identity['model_access_admin_role'],
            'ai_config_access_version' => $identity['ai_config_access_version'],
            'requested_ai_model_id' => $identity['requested_ai_model_id'],
            'requested_ai_model_snapshot' => null,
            'resolver_policy_version' => $identity['resolver_policy_version'],
        ];

        if (! $this->identityComplete($snapshot) || $snapshot['requested_ai_model_id'] === null) {
            return $snapshot;
        }

        $admin = $this->accessGuard->assertPersistedAdminSnapshot($snapshot, (int) $task->getKey());
        $model = $this->accessGuard->assertModelForPersistedAdminSnapshot(
            $snapshot,
            $snapshot['requested_ai_model_id'],
            (int) $task->getKey(),
            $admin,
        );
        $snapshot['requested_ai_model_snapshot'] = $this->safeModelSnapshot($model);

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function identityComplete(array $snapshot): bool
    {
        return (int) ($snapshot['model_access_admin_id'] ?? 0) > 0
            && in_array((string) ($snapshot['model_access_admin_role'] ?? ''), ['admin', 'super_admin'], true)
            && (int) ($snapshot['ai_config_access_version'] ?? 0) > 0
            && (int) ($snapshot['resolver_policy_version'] ?? 0) > 0;
    }

    /** @return array<string,mixed> */
    private function safeModelSnapshot(AiModel $model): array
    {
        return [
            'id' => (int) $model->getKey(),
            'owner_admin_id' => (int) $model->owner_admin_id,
            'name' => (string) $model->name,
            'version' => (string) $model->version,
            'model_type' => (string) $model->model_type,
            'access_scope' => (string) $model->access_scope,
        ];
    }

    /** @return array{model_access_admin_id:null,model_access_admin_role:null,ai_config_access_version:null,requested_ai_model_id:null,requested_ai_model_snapshot:null,resolver_policy_version:null} */
    private function emptySnapshot(): array
    {
        return [
            'model_access_admin_id' => null,
            'model_access_admin_role' => null,
            'ai_config_access_version' => null,
            'requested_ai_model_id' => null,
            'requested_ai_model_snapshot' => null,
            'resolver_policy_version' => null,
        ];
    }
}
