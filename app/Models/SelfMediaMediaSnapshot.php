<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SelfMediaMediaSnapshot extends Model
{
    protected $fillable = [
        'manual_publication_batch_id', 'media_key', 'position', 'source_type', 'source_url',
        'alt_text', 'used_as_cover', 'storage_disk', 'storage_path', 'sha256', 'mime_type',
        'file_size', 'width', 'height', 'status',
    ];

    protected function casts(): array
    {
        return [
            'manual_publication_batch_id' => 'integer',
            'position' => 'integer',
            'used_as_cover' => 'boolean',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationBatch::class, 'manual_publication_batch_id');
    }
}
