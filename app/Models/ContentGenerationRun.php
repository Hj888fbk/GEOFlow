<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentGenerationRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $attributes = ['status' => self::STATUS_RUNNING];

    protected $fillable = [
        'run_key',
        'content_task_id',
        'content_master_id',
        'prompt_recipe_version_id',
        'created_by_admin_id',
        'status',
        'input_hash',
        'output_hash',
        'provider',
        'model',
        'input_snapshot',
        'validation_result',
        'token_usage',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'content_task_id' => 'integer',
            'content_master_id' => 'integer',
            'prompt_recipe_version_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'input_snapshot' => 'array',
            'validation_result' => 'array',
            'token_usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentTask::class, 'content_task_id');
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(ContentMaster::class, 'content_master_id');
    }

    public function promptRecipeVersion(): BelongsTo
    {
        return $this->belongsTo(PromptRecipeVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return $this->status === self::STATUS_RUNNING
            && in_array($status, [self::STATUS_COMPLETED, self::STATUS_BLOCKED, self::STATUS_FAILED], true);
    }
}
