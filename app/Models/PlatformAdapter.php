<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformAdapter extends Model
{
    public const WORDPRESS = 'wordpress';

    public const BAIDU_AICAIGOU = 'baidu-aicaigou';

    public const ALIBABA_1688 = '1688';

    public const SOHU = 'sohu';

    public const BAIJIAHAO = 'baijiahao';

    public const CUSTOM_MANUAL = 'custom-manual';

    public const EXECUTION_API = 'authenticated_api';

    public const EXECUTION_BROWSER_ASSISTED = 'browser_assisted';

    public const EXECUTION_MANUAL_EXPORT = 'manual_export';

    protected $attributes = [
        'status' => 'active',
        'is_builtin' => true,
    ];

    protected $fillable = [
        'key',
        'name',
        'version',
        'execution_mode',
        'implementation_class',
        'supported_content_types',
        'connection_modes',
        'capabilities',
        'field_contract',
        'status',
        'is_builtin',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'supported_content_types' => 'array',
            'connection_modes' => 'array',
            'capabilities' => 'array',
            'field_contract' => 'array',
            'is_builtin' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(ChannelContract::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ManualPublicationAccount::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ChannelVariant::class);
    }
}
