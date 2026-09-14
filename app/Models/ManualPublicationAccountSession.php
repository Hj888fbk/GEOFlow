<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ManualPublicationAccountSession extends Model
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ACTION_REQUIRED = 'action_required';

    public const STATUSES = [
        self::STATUS_UNKNOWN,
        self::STATUS_AUTHORIZED,
        self::STATUS_EXPIRED,
        self::STATUS_ACTION_REQUIRED,
    ];

    protected $fillable = [
        'browser_operator_client_id',
        'manual_publication_account_id',
        'status',
        'observed_account_hash',
        'last_error_code',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'browser_operator_client_id' => 'integer',
            'manual_publication_account_id' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(BrowserOperatorClient::class, 'browser_operator_client_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationAccount::class, 'manual_publication_account_id');
    }
}
