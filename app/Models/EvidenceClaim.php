<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceClaim extends Model
{
    public const TYPE_COMPANY = 'company';

    public const TYPE_QUALIFICATION = 'qualification';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_INSPECTION = 'inspection';

    public const TYPE_CASE = 'case';

    public const TYPE_IMAGE_RIGHTS = 'image_rights';

    public const TYPES = [
        self::TYPE_COMPANY,
        self::TYPE_QUALIFICATION,
        self::TYPE_PRODUCT,
        self::TYPE_PRODUCTION,
        self::TYPE_INSPECTION,
        self::TYPE_CASE,
        self::TYPE_IMAGE_RIGHTS,
    ];

    protected $fillable = [
        'claim_id',
        'content_task_id',
        'content_source_file_id',
        'source_id',
        'claim_type',
        'subject',
        'predicate',
        'claim_value',
        'unit',
        'scope',
        'evidence_status',
        'public_permission',
        'structured_payload',
        'official_lookup_url',
        'valid_from',
        'valid_until',
        'reviewed_by_admin_id',
        'reviewed_at',
        'conflict_notes',
    ];

    protected function casts(): array
    {
        return [
            'content_task_id' => 'integer',
            'content_source_file_id' => 'integer',
            'structured_payload' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'reviewed_by_admin_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentTask::class, 'content_task_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ContentSourceFile::class, 'content_source_file_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function isPublishableFact(): bool
    {
        return in_array($this->evidence_status, [
            ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
            ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
        ], true)
            && $this->public_permission === ContentSourceFile::PERMISSION_PUBLISHABLE
            && $this->reviewed_at !== null
            && ($this->valid_until === null || ! $this->valid_until->isBefore(today()));
    }

    public function hasCompleteQualificationPayload(): bool
    {
        if ($this->claim_type !== self::TYPE_QUALIFICATION) {
            return true;
        }

        $payload = (array) $this->structured_payload;
        foreach ([
            'qualification_name', 'certificate_number', 'issuer', 'certification_scope',
            'applicable_products', 'issued_at', 'valid_until', 'official_lookup_url',
            'file_sha256', 'public_permission', 'reviewer',
        ] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
