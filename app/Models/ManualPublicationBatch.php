<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ManualPublicationBatch extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTOMATIC = 'automatic';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_PENDING_PLATFORM = 'pending_platform';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_INVALIDATED = 'invalidated';

    public const PENDING_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_GENERATING,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_PENDING_PLATFORM,
        self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'article_id',
        'task_id',
        'website_publication_receipt_id',
        'created_by_admin_id',
        'model_access_admin_id',
        'model_access_admin_role',
        'ai_config_access_version',
        'requested_ai_model_id',
        'requested_ai_model_snapshot',
        'resolver_policy_version',
        'trigger',
        'content_intent',
        'routing_version',
        'routing_reason',
        'target_platforms',
        'platform_combination_hash',
        'source_url',
        'website_readback',
        'source_hash',
        'source_snapshot',
        'fact_constraints',
        'media_manifest',
        'idempotency_hash',
        'status',
        'generation_errors',
        'execution_lease_token',
        'lease_expires_at',
        'generation_attempt',
        'invalidated_at',
        'revision',
    ];

    protected $attributes = [
        'status' => self::STATUS_PLANNED,
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'task_id' => 'integer',
            'website_publication_receipt_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'requested_ai_model_snapshot' => 'array',
            'resolver_policy_version' => 'integer',
            'target_platforms' => 'array',
            'website_readback' => 'array',
            'source_snapshot' => 'array',
            'fact_constraints' => 'array',
            'media_manifest' => 'array',
            'generation_errors' => 'array',
            'lease_expires_at' => 'datetime',
            'generation_attempt' => 'integer',
            'invalidated_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function websiteReceipt(): BelongsTo
    {
        return $this->belongsTo(WebsitePublicationReceipt::class, 'website_publication_receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'manual_publication_batch_id');
    }

    /** @param list<string> $publicationStatuses */
    public static function statusFromPublications(array $publicationStatuses, string $currentStatus): string
    {
        if ($publicationStatuses === []) {
            return $currentStatus;
        }

        $terminalStatuses = [
            ManualPublication::STATUS_COMPLETED,
            ManualPublication::STATUS_FAILED,
            ManualPublication::STATUS_SKIPPED,
            ManualPublication::STATUS_CANCELLED,
        ];
        $allTerminal = array_diff($publicationStatuses, $terminalStatuses) === [];

        return match (true) {
            in_array(ManualPublication::STATUS_DRAFT_FILLED, $publicationStatuses, true),
            in_array(ManualPublication::STATUS_IN_PROGRESS, $publicationStatuses, true) => self::STATUS_ACTIVE,
            in_array(ManualPublication::STATUS_OUTCOME_UNKNOWN, $publicationStatuses, true) => self::STATUS_PENDING_VERIFICATION,
            in_array(ManualPublication::STATUS_READY, $publicationStatuses, true) => self::STATUS_PENDING_PLATFORM,
            array_diff($publicationStatuses, [ManualPublication::STATUS_COMPLETED]) === [] => self::STATUS_COMPLETED,
            array_diff($publicationStatuses, [ManualPublication::STATUS_CANCELLED]) === [] => self::STATUS_CANCELLED,
            $allTerminal => self::STATUS_FAILED,
            default => $currentStatus,
        };
    }
}
