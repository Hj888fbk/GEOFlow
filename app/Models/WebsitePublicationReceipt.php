<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WebsitePublicationReceipt extends Model
{
    protected $fillable = [
        'article_id',
        'responsible_project_id',
        'formal_url',
        'http_status',
        'source_hash',
        'readback_hash',
        'readback_succeeded',
        'verified_at',
        'receipt_payload',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'http_status' => 'integer',
            'readback_succeeded' => 'boolean',
            'verified_at' => 'datetime',
            'receipt_payload' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ManualPublicationBatch::class);
    }

    public function isVerifiedFor(string $sourceHash): bool
    {
        return $this->readback_succeeded
            && $this->http_status === 200
            && hash_equals((string) $this->source_hash, $sourceHash)
            && hash_equals((string) $this->readback_hash, $sourceHash)
            && trim((string) $this->formal_url) !== '';
    }
}
