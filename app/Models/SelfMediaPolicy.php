<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SelfMediaPolicy extends Model
{
    public const LEGACY_ROUTING_VERSION = 'self-media-routing-v1';

    public const ROUTING_VERSION = 'self-media-routing-v2';

    protected $fillable = [
        'task_id',
        'enabled',
        'content_intent',
        'platform_override',
        'routing_version',
        'daily_source_limit',
        'pending_batch_limit',
    ];

    protected $attributes = [
        'enabled' => false,
        'routing_version' => self::ROUTING_VERSION,
        'daily_source_limit' => 1,
        'pending_batch_limit' => 2,
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'enabled' => 'boolean',
            'platform_override' => 'array',
            'daily_source_limit' => 'integer',
            'pending_batch_limit' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
