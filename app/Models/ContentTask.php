<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentTask extends Model
{
    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_READY = 'ready';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_CANDIDATE,
        self::STATUS_BLOCKED,
        self::STATUS_READY,
        self::STATUS_GENERATING,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_CHANGES_REQUIRED = 'changes_required';

    protected $attributes = [
        'product_key' => 'rubber-joint',
        'priority' => 3,
        'status' => self::STATUS_CANDIDATE,
        'review_status' => self::REVIEW_PENDING,
    ];

    protected $fillable = [
        'candidate_key',
        'task_id',
        'product_key',
        'title',
        'audience',
        'intent',
        'page_role',
        'primary_keyword',
        'secondary_keywords',
        'target_channels',
        'priority',
        'due_on',
        'status',
        'review_status',
        'blockers',
        'input_hash',
        'assigned_admin_id',
        'reviewed_by_admin_id',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'task_id' => 'integer',
            'target_channels' => 'array',
            'priority' => 'integer',
            'due_on' => 'date',
            'blockers' => 'array',
            'assigned_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function existingTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function evidenceClaims(): HasMany
    {
        return $this->hasMany(EvidenceClaim::class);
    }

    public function masters(): HasMany
    {
        return $this->hasMany(ContentMaster::class);
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(ContentGenerationRun::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->whereDate('due_on', now()->toDateString());
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED || (array) $this->blockers !== [];
    }
}
