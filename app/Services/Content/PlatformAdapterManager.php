<?php

namespace App\Services\Content;

use App\Contracts\Content\PlatformAdapter as PlatformAdapterContract;
use App\Models\PlatformAdapter;
use App\Services\Content\Adapters\Alibaba1688PlatformAdapter;
use App\Services\Content\Adapters\BaiduAicaigouPlatformAdapter;
use App\Services\Content\Adapters\BaijiahaoPlatformAdapter;
use App\Services\Content\Adapters\CustomManualPlatformAdapter;
use App\Services\Content\Adapters\SohuPlatformAdapter;
use App\Services\Content\Adapters\WordPressPlatformAdapter;

class PlatformAdapterManager
{
    public function __construct(
        private readonly WordPressPlatformAdapter $wordpress,
        private readonly BaiduAicaigouPlatformAdapter $aicaigou,
        private readonly Alibaba1688PlatformAdapter $alibaba1688,
        private readonly SohuPlatformAdapter $sohu,
        private readonly BaijiahaoPlatformAdapter $baijiahao,
        private readonly CustomManualPlatformAdapter $custom,
    ) {}

    public function forKey(string $key): PlatformAdapterContract
    {
        return match ($key) {
            PlatformAdapter::WORDPRESS => $this->wordpress,
            PlatformAdapter::BAIDU_AICAIGOU => $this->aicaigou,
            PlatformAdapter::ALIBABA_1688 => $this->alibaba1688,
            PlatformAdapter::SOHU => $this->sohu,
            PlatformAdapter::BAIJIAHAO => $this->baijiahao,
            PlatformAdapter::CUSTOM_MANUAL => $this->custom,
            default => throw new \DomainException('Unsupported platform adapter: '.$key),
        };
    }

    public function forModel(PlatformAdapter $adapter): PlatformAdapterContract
    {
        if ($adapter->status !== 'active') {
            throw new \DomainException('Platform adapter is not active: '.$adapter->key);
        }

        $implementation = $this->forKey((string) $adapter->key);
        if ($adapter->implementation_class !== null
            && $adapter->implementation_class !== $implementation::class) {
            throw new \DomainException('Registered adapter implementation does not match the approved implementation.');
        }

        return $implementation;
    }
}
