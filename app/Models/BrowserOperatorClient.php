<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

final class BrowserOperatorClient extends Model
{
    public const TYPE_EXTENSION = 'extension';

    public const TYPE_DESKTOP = 'desktop';

    public const TYPES = [self::TYPE_EXTENSION, self::TYPE_DESKTOP];

    protected $fillable = [
        'personal_access_token_id',
        'client_type',
        'client_name',
        'client_version',
        'capabilities',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function accountSessions(): HasMany
    {
        return $this->hasMany(ManualPublicationAccountSession::class);
    }
}
