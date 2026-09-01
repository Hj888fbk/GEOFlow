<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelContract extends Model
{
    protected $attributes = ['status' => 'candidate'];

    protected $fillable = [
        'platform_adapter_id',
        'contract_version',
        'content_type',
        'contract',
        'status',
        'created_by_admin_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_adapter_id' => 'integer',
            'contract' => 'array',
            'created_by_admin_id' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(PlatformAdapter::class, 'platform_adapter_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
