<?php

namespace App\Services\SelfMedia;

use App\Models\ManualPublicationAccount;
use App\Models\SelfMediaPolicy;
use DomainException;

final class SelfMediaPlatformRouter
{
    public const INTENT_ENTERPRISE_NEWS = 'enterprise_news';

    public const INTENT_PRODUCT_EDUCATION = 'product_education';

    public const INTENT_SELECTION_DECISION = 'selection_decision';

    public const INTENT_ENGINEERING_DIGITAL = 'engineering_digital';

    public const INTENT_SHORT_UPDATE = 'short_update';

    public const INTENTS = [
        self::INTENT_ENTERPRISE_NEWS,
        self::INTENT_PRODUCT_EDUCATION,
        self::INTENT_SELECTION_DECISION,
        self::INTENT_ENGINEERING_DIGITAL,
        self::INTENT_SHORT_UPDATE,
    ];

    /** @return array{platforms:list<string>,version:string,reason:string} */
    public function route(string $intent, ?array $override = null, string $routingVersion = SelfMediaPolicy::ROUTING_VERSION): array
    {
        if (! in_array($intent, self::INTENTS, true)) {
            throw new DomainException('未知的自媒体分发意图。');
        }

        if ($override !== null) {
            $platforms = $this->normalizePlatforms($override);
            if ($platforms === []) {
                throw new DomainException('手动平台列表不能为空。');
            }

            return [
                'platforms' => $platforms,
                'version' => $routingVersion,
                'reason' => 'manual_override:'.$intent,
            ];
        }

        $legacyPlatforms = match ($intent) {
            self::INTENT_ENTERPRISE_NEWS => [
                ManualPublicationAccount::PLATFORM_QQ_PENGUIN,
                ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                ManualPublicationAccount::PLATFORM_NETEASE_MEDIA,
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                ManualPublicationAccount::PLATFORM_DAYU,
                ManualPublicationAccount::PLATFORM_WEIBO,
            ],
            self::INTENT_PRODUCT_EDUCATION => [
                ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                ManualPublicationAccount::PLATFORM_DAYU,
            ],
            self::INTENT_SELECTION_DECISION => [
                ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
            ],
            self::INTENT_ENGINEERING_DIGITAL => [
                ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                ManualPublicationAccount::PLATFORM_CSDN,
                ManualPublicationAccount::PLATFORM_NETEASE_MEDIA,
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
            ],
            self::INTENT_SHORT_UPDATE => [ManualPublicationAccount::PLATFORM_WEIBO],
        };

        $platforms = $routingVersion === SelfMediaPolicy::ROUTING_VERSION
            ? match ($intent) {
                self::INTENT_ENTERPRISE_NEWS => [
                    ManualPublicationAccount::PLATFORM_QQ_PENGUIN,
                    ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                    ManualPublicationAccount::PLATFORM_NETEASE_MEDIA,
                    ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                    ManualPublicationAccount::PLATFORM_DAYU,
                    ManualPublicationAccount::PLATFORM_TOUTIAO,
                ],
                self::INTENT_PRODUCT_EDUCATION => [
                    ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                    ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                    ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                    ManualPublicationAccount::PLATFORM_DAYU,
                    ManualPublicationAccount::PLATFORM_JIANSHU,
                ],
                self::INTENT_SELECTION_DECISION => [
                    ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                    ManualPublicationAccount::PLATFORM_BAIJIAHAO,
                    ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                    ManualPublicationAccount::PLATFORM_JIANSHU,
                ],
                self::INTENT_ENGINEERING_DIGITAL => [
                    ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN,
                    ManualPublicationAccount::PLATFORM_CSDN,
                    ManualPublicationAccount::PLATFORM_NETEASE_MEDIA,
                    ManualPublicationAccount::PLATFORM_SOHU_MEDIA,
                    ManualPublicationAccount::PLATFORM_JIANSHU,
                ],
                self::INTENT_SHORT_UPDATE => [ManualPublicationAccount::PLATFORM_TOUTIAO],
            }
        : $legacyPlatforms;

        return [
            'platforms' => $platforms,
            'version' => $routingVersion,
            'reason' => 'deterministic_rule:'.$intent,
        ];
    }

    /** @return list<string> */
    public function normalizePlatforms(array $platforms): array
    {
        $allowed = array_flip(ManualPublicationAccount::SELF_MEDIA_PLATFORMS);
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = trim((string) $platform);
            if (! isset($allowed[$platform])) {
                throw new DomainException('不支持的自媒体平台：'.$platform);
            }
            $normalized[$platform] = true;
        }

        return array_keys($normalized);
    }
}
