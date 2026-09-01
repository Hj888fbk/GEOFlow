<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelVariant extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_AWAITING_MANUAL_CONFIRMATION = 'awaiting_manual_confirmation';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_BLOCKED,
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected $fillable = [
        'content_master_id',
        'platform_adapter_id',
        'manual_publication_account_id',
        'distribution_channel_id',
        'variant_key',
        'channel_key',
        'content_type',
        'payload',
        'status',
        'blockers',
        'payload_hash',
        'adapter_version',
        'scheduled_at',
        'remote_id',
        'remote_url',
        'receipt',
        'last_readback_at',
    ];

    protected function casts(): array
    {
        return [
            'content_master_id' => 'integer',
            'platform_adapter_id' => 'integer',
            'manual_publication_account_id' => 'integer',
            'distribution_channel_id' => 'integer',
            'payload' => 'array',
            'blockers' => 'array',
            'scheduled_at' => 'datetime',
            'receipt' => 'array',
            'last_readback_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(ContentMaster::class, 'content_master_id');
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(PlatformAdapter::class, 'platform_adapter_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationAccount::class, 'manual_publication_account_id');
    }

    public function distributionChannel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function manualPublications(): HasMany
    {
        return $this->hasMany(ManualPublication::class);
    }

    public function articleDistributions(): HasMany
    {
        return $this->hasMany(ArticleDistribution::class);
    }

    /** @return list<string> */
    public static function allowedNextStatuses(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => [self::STATUS_BLOCKED, self::STATUS_APPROVED],
            self::STATUS_BLOCKED => [self::STATUS_DRAFT],
            self::STATUS_APPROVED => [self::STATUS_BLOCKED, self::STATUS_QUEUED, self::STATUS_AWAITING_MANUAL_CONFIRMATION],
            self::STATUS_QUEUED, self::STATUS_AWAITING_MANUAL_CONFIRMATION => [
                self::STATUS_PUBLISHED,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
                self::STATUS_OUTCOME_UNKNOWN,
            ],
            self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_OUTCOME_UNKNOWN => [
                self::STATUS_AWAITING_MANUAL_CONFIRMATION,
            ],
            default => [],
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedNextStatuses((string) $this->status), true);
    }
}
