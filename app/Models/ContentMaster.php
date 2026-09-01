<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentMaster extends Model
{
    public const SCHEMA_VERSION = 'hengjia-content-package/v1';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_BLOCKED,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_PROMOTED,
        self::STATUS_ROLLED_BACK,
    ];

    protected $attributes = [
        'schema_version' => self::SCHEMA_VERSION,
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'content_task_id',
        'article_id',
        'prompt_recipe_version_id',
        'schema_version',
        'title',
        'h1',
        'slug',
        'summary',
        'meta_description',
        'package',
        'article_snapshot',
        'status',
        'blockers',
        'package_hash',
        'provider',
        'model',
        'generated_at',
        'reviewed_by_admin_id',
        'approved_at',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'content_task_id' => 'integer',
            'article_id' => 'integer',
            'prompt_recipe_version_id' => 'integer',
            'package' => 'array',
            'article_snapshot' => 'array',
            'blockers' => 'array',
            'generated_at' => 'datetime',
            'reviewed_by_admin_id' => 'integer',
            'approved_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentTask::class, 'content_task_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function promptRecipeVersion(): BelongsTo
    {
        return $this->belongsTo(PromptRecipeVersion::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ChannelVariant::class);
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(ContentGenerationRun::class);
    }

    /** @return list<string> */
    public static function allowedNextStatuses(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => [self::STATUS_BLOCKED, self::STATUS_IN_REVIEW],
            self::STATUS_BLOCKED => [self::STATUS_IN_REVIEW, self::STATUS_APPROVED],
            self::STATUS_IN_REVIEW => [self::STATUS_BLOCKED, self::STATUS_APPROVED],
            self::STATUS_APPROVED => [self::STATUS_PROMOTED],
            self::STATUS_PROMOTED => [self::STATUS_ROLLED_BACK],
            default => [],
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedNextStatuses((string) $this->status), true);
    }
}
