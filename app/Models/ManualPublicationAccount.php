<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationAccount extends Model
{
    public const PLATFORM_QQ_PENGUIN = 'qq_penguin';

    public const PLATFORM_ZHIHU_COLUMN = 'zhihu_column';

    public const PLATFORM_BAIJIAHAO = 'baijiahao';

    public const PLATFORM_NETEASE_MEDIA = 'netease_media';

    public const PLATFORM_SOHU_MEDIA = 'sohu_media';

    public const PLATFORM_ZHIHU = 'zhihu';

    public const PLATFORM_XIAOHONGSHU = 'xiaohongshu';

    public const PLATFORM_WEIBO = 'weibo';

    public const PLATFORM_CSDN = 'csdn';

    public const PLATFORM_DAYU = 'dayu';

    public const PLATFORM_TOUTIAO = 'toutiao';

    public const PLATFORM_JIANSHU = 'jianshu';

    public const SELF_MEDIA_PLATFORMS = [
        self::PLATFORM_QQ_PENGUIN,
        self::PLATFORM_ZHIHU_COLUMN,
        self::PLATFORM_ZHIHU,
        self::PLATFORM_XIAOHONGSHU,
        self::PLATFORM_WECHAT,
        self::PLATFORM_BAIJIAHAO,
        self::PLATFORM_NETEASE_MEDIA,
        self::PLATFORM_SOHU_MEDIA,
        self::PLATFORM_WEIBO,
        self::PLATFORM_CSDN,
        self::PLATFORM_DAYU,
        self::PLATFORM_TOUTIAO,
        self::PLATFORM_JIANSHU,
        self::PLATFORM_DOUYIN,
    ];

    /** 当前十平台高保真草稿同步范围；微博仅为 v1 历史路由兼容保留。 */
    public const DRAFT_SYNC_PLATFORMS = [
        self::PLATFORM_SOHU_MEDIA,
        self::PLATFORM_NETEASE_MEDIA,
        self::PLATFORM_TOUTIAO,
        self::PLATFORM_BAIJIAHAO,
        self::PLATFORM_DAYU,
        self::PLATFORM_QQ_PENGUIN,
        self::PLATFORM_ZHIHU_COLUMN,
        self::PLATFORM_JIANSHU,
        self::PLATFORM_CSDN,
        self::PLATFORM_DOUYIN,
    ];

    public const PLATFORM_WECHAT = 'wechat';

    public const PLATFORM_DOUYIN = 'douyin';

    public const PLATFORM_BILIBILI = 'bilibili';

    public const PLATFORM_REDDIT = 'reddit';

    public const PLATFORM_X = 'x';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_CUSTOM = 'custom';

    public const PLATFORMS = [
        self::PLATFORM_QQ_PENGUIN,
        self::PLATFORM_ZHIHU_COLUMN,
        self::PLATFORM_BAIJIAHAO,
        self::PLATFORM_NETEASE_MEDIA,
        self::PLATFORM_SOHU_MEDIA,
        self::PLATFORM_ZHIHU,
        self::PLATFORM_XIAOHONGSHU,
        self::PLATFORM_WEIBO,
        self::PLATFORM_CSDN,
        self::PLATFORM_DAYU,
        self::PLATFORM_TOUTIAO,
        self::PLATFORM_JIANSHU,
        self::PLATFORM_WECHAT,
        self::PLATFORM_DOUYIN,
        self::PLATFORM_BILIBILI,
        self::PLATFORM_REDDIT,
        self::PLATFORM_X,
        self::PLATFORM_LINKEDIN,
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
        'editor_url',
        'account_uid',
        'homepage_identifier',
        'browser_adapter_enabled',
        'notes',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'persona_id' => 'integer',
            'is_active' => 'boolean',
            'browser_adapter_enabled' => 'boolean',
            'created_by_admin_id' => 'integer',
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

    public function desktopSessions(): HasMany
    {
        return $this->hasMany(ManualPublicationAccountSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function platformLabelKey(): string
    {
        return 'admin.manual_publications.platform.'.$this->platform;
    }

    /** @return array<string,string> */
    public static function editorUrlPresets(): array
    {
        return [
            self::PLATFORM_BAIJIAHAO => 'https://baijiahao.baidu.com/builder/rc/edit',
            self::PLATFORM_SOHU_MEDIA => 'https://mp.sohu.com/mpfe/v4/contentManagement/news/addarticle',
            self::PLATFORM_ZHIHU_COLUMN => 'https://zhuanlan.zhihu.com/write',
            self::PLATFORM_CSDN => 'https://editor.csdn.net/md/',
            self::PLATFORM_TOUTIAO => 'https://mp.toutiao.com/profile_v4/graphic/publish',
            self::PLATFORM_NETEASE_MEDIA => 'https://mp.163.com/',
            self::PLATFORM_QQ_PENGUIN => 'https://om.qq.com/',
            self::PLATFORM_DAYU => 'https://mp.dayu.com/',
            self::PLATFORM_JIANSHU => 'https://www.jianshu.com/writer',
            self::PLATFORM_DOUYIN => 'https://creator.douyin.com/creator-micro/content/publish',
        ];
    }
}
