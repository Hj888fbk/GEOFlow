<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationAccount extends Model
{
    public const PLATFORM_ZHIHU = 'zhihu';

    public const PLATFORM_XIAOHONGSHU = 'xiaohongshu';

    public const PLATFORM_WEIBO = 'weibo';

    public const PLATFORM_WECHAT = 'wechat';

    public const PLATFORM_DOUYIN = 'douyin';

    public const PLATFORM_BILIBILI = 'bilibili';

    public const PLATFORM_REDDIT = 'reddit';

    public const PLATFORM_X = 'x';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_CUSTOM = 'custom';

    public const PLATFORM_WORDPRESS = 'wordpress';

    public const PLATFORM_BAIDU_AICAIGOU = 'baidu_aicaigou';

    public const PLATFORM_1688 = '1688';

    public const PLATFORM_SOHU = 'sohu';

    public const PLATFORM_BAIJIAHAO = 'baijiahao';

    public const PLATFORMS = [
        self::PLATFORM_ZHIHU,
        self::PLATFORM_XIAOHONGSHU,
        self::PLATFORM_WEIBO,
        self::PLATFORM_WECHAT,
        self::PLATFORM_DOUYIN,
        self::PLATFORM_BILIBILI,
        self::PLATFORM_REDDIT,
        self::PLATFORM_X,
        self::PLATFORM_LINKEDIN,
        self::PLATFORM_WORDPRESS,
        self::PLATFORM_BAIDU_AICAIGOU,
        self::PLATFORM_1688,
        self::PLATFORM_SOHU,
        self::PLATFORM_BAIJIAHAO,
        self::PLATFORM_CUSTOM,
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'persona_id',
        'platform',
        'custom_platform',
        'account_name',
        'profile_url',
        'notes',
        'is_active',
        'created_by_admin_id',
        'platform_adapter_id',
        'distribution_channel_id',
        'connection_mode',
        'subject_name',
        'brand_voice',
        'person_voice',
        'content_types',
        'authorization_status',
        'login_status',
        'external_account_hash',
        'capability_snapshot',
        'adapter_version',
        'publishing_rules',
        'last_verified_at',
        'authorization_expires_at',
        'disabled_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $hidden = ['external_account_hash'];

    protected function casts(): array
    {
        return [
            'persona_id' => 'integer',
            'is_active' => 'boolean',
            'created_by_admin_id' => 'integer',
            'platform_adapter_id' => 'integer',
            'distribution_channel_id' => 'integer',
            'content_types' => 'array',
            'capability_snapshot' => 'array',
            'publishing_rules' => 'array',
            'last_verified_at' => 'datetime',
            'authorization_expires_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationPersona::class, 'persona_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(PlatformAdapter::class, 'platform_adapter_id');
    }

    public function distributionChannel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function channelVariants(): HasMany
    {
        return $this->hasMany(ChannelVariant::class);
    }

    public function isEnabled(): bool
    {
        return $this->is_active && $this->disabled_at === null;
    }

    public function platformLabelKey(): string
    {
        return 'admin.manual_publications.platform.'.$this->platform;
    }
}
