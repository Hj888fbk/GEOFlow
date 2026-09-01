<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentSourceFile extends Model
{
    public const STATUS_PUBLIC_RECORD_VERIFIED = 'public_record_verified';

    public const STATUS_INTERNAL_CONFIRMED = 'internal_confirmed_public';

    public const STATUS_COMPETITOR_SELF_CLAIM = 'competitor_self_claim';

    public const STATUS_THIRD_PARTY_CLAIM = 'third_party_claim';

    public const STATUS_AI_OBSERVATION = 'ai_observation';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_NOT_FOUND = 'not_found';

    public const EVIDENCE_STATUSES = [
        self::STATUS_PUBLIC_RECORD_VERIFIED,
        self::STATUS_INTERNAL_CONFIRMED,
        self::STATUS_COMPETITOR_SELF_CLAIM,
        self::STATUS_THIRD_PARTY_CLAIM,
        self::STATUS_AI_OBSERVATION,
        self::STATUS_CONFLICT,
        self::STATUS_NOT_FOUND,
    ];

    public const PERMISSION_INTERNAL = 'internal_only';

    public const PERMISSION_PUBLISHABLE = 'publishable';

    public const PERMISSION_RESTRICTED = 'restricted';

    public const PERMISSION_PROHIBITED = 'prohibited';

    public const PUBLIC_PERMISSIONS = [
        self::PERMISSION_INTERNAL,
        self::PERMISSION_PUBLISHABLE,
        self::PERMISSION_RESTRICTED,
        self::PERMISSION_PROHIBITED,
    ];

    protected $attributes = [
        'file_size' => 0,
        'public_permission' => self::PERMISSION_INTERNAL,
        'is_approved' => false,
    ];

    protected $fillable = [
        'source_root_key',
        'relative_path',
        'path_hash',
        'sha256',
        'file_size',
        'mime_type',
        'source_version',
        'source_date',
        'evidence_status',
        'public_permission',
        'is_approved',
        'metadata',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'source_date' => 'date',
            'is_approved' => 'boolean',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function evidenceClaims(): HasMany
    {
        return $this->hasMany(EvidenceClaim::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeApprovedForPublication(Builder $query): Builder
    {
        return $query
            ->where('is_approved', true)
            ->where('public_permission', self::PERMISSION_PUBLISHABLE)
            ->whereIn('evidence_status', [
                self::STATUS_PUBLIC_RECORD_VERIFIED,
                self::STATUS_INTERNAL_CONFIRMED,
            ]);
    }
}
