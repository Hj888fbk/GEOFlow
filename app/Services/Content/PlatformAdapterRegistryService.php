<?php

namespace App\Services\Content;

use App\Models\ChannelContract;
use App\Models\PlatformAdapter;
use Illuminate\Support\Collection;

class PlatformAdapterRegistryService
{
    public function __construct(private readonly PlatformAdapterManager $manager) {}

    /** @return Collection<int,PlatformAdapter> */
    public function sync(?int $adminId = null): Collection
    {
        return collect($this->definitions())->map(function (array $definition) use ($adminId): PlatformAdapter {
            $implementation = $this->manager->forKey($definition['key']);
            $capabilities = $implementation->capabilities();
            $adapter = PlatformAdapter::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'version' => $definition['version'],
                    'execution_mode' => $capabilities['execution_mode'],
                    'implementation_class' => $implementation::class,
                    'supported_content_types' => $capabilities['content_types'],
                    'connection_modes' => $definition['connection_modes'],
                    'capabilities' => $capabilities,
                    'field_contract' => (array) ($capabilities['field_contract'] ?? []),
                    'status' => 'active',
                    'is_builtin' => true,
                    'last_verified_at' => now(),
                ],
            );

            foreach ((array) $capabilities['content_types'] as $contentType) {
                ChannelContract::query()->updateOrCreate(
                    [
                        'platform_adapter_id' => $adapter->getKey(),
                        'contract_version' => $definition['version'],
                        'content_type' => (string) $contentType,
                    ],
                    [
                        'contract' => [
                            'execution_mode' => $capabilities['execution_mode'],
                            'field_contract' => (array) ($capabilities['field_contract'] ?? []),
                            'stop_conditions' => (array) ($capabilities['stop_conditions'] ?? []),
                            'requires_human_final_confirmation' => (bool) ($capabilities['requires_human_final_confirmation'] ?? false),
                        ],
                        'status' => 'active',
                        'created_by_admin_id' => $adminId,
                        'verified_at' => now(),
                    ],
                );
            }

            return $adapter->refresh();
        })->values();
    }

    /** @return list<array{key:string,name:string,version:string,connection_modes:list<string>}> */
    public function definitions(): array
    {
        return [
            ['key' => PlatformAdapter::WORDPRESS, 'name' => '恒佳 WordPress 官网', 'version' => '1.0.0', 'connection_modes' => ['authenticated_api']],
            ['key' => PlatformAdapter::BAIDU_AICAIGOU, 'name' => '百度爱采购', 'version' => '1.0.0', 'connection_modes' => ['browser_assisted', 'manual_export']],
            ['key' => PlatformAdapter::ALIBABA_1688, 'name' => '1688', 'version' => '1.0.0', 'connection_modes' => ['browser_assisted', 'manual_export']],
            ['key' => PlatformAdapter::SOHU, 'name' => '搜狐号', 'version' => '1.0.0', 'connection_modes' => ['browser_assisted', 'manual_export']],
            ['key' => PlatformAdapter::BAIJIAHAO, 'name' => '百家号', 'version' => '1.0.0', 'connection_modes' => ['browser_assisted', 'manual_export']],
            ['key' => PlatformAdapter::CUSTOM_MANUAL, 'name' => '自定义人工平台', 'version' => '1.0.0', 'connection_modes' => ['manual_export']],
        ];
    }
}
