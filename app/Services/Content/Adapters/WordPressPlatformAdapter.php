<?php

namespace App\Services\Content\Adapters;

use App\Contracts\Content\PlatformAdapter as PlatformAdapterContract;
use App\Models\ChannelVariant;
use App\Models\DistributionChannel;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;
use App\Services\Content\WordPressContentBridge;

final class WordPressPlatformAdapter implements PlatformAdapterContract
{
    public function __construct(private readonly WordPressContentBridge $bridge) {}

    public function capabilities(): array
    {
        return [
            'adapter_key' => PlatformAdapter::WORDPRESS,
            'execution_mode' => PlatformAdapter::EXECUTION_API,
            'content_types' => ['post'],
            'requested_future_content_types' => ['page', 'product'],
            'unsupported_reason' => '当前既有 WordPress 发布器只完整支持文章；页面和产品需恒佳官网插件能力后再启用。',
            'supports_preview' => true,
            'supports_auto_submit' => true,
            'supports_readback' => true,
            'supports_rollback' => true,
            'delegates_to_existing_distribution' => true,
            'credential_store' => 'distribution_channel_secrets',
            'field_contract' => [
                'title' => ['required' => true],
                'slug' => ['required' => true],
                'excerpt' => ['required' => false],
                'content_markdown' => ['required' => true],
                'meta_description' => ['required' => false],
                'faq' => ['required' => false],
                'schema_nodes' => ['required' => false],
            ],
        ];
    }

    public function preflight(ManualPublicationAccount $account, array $context = []): array
    {
        $blockers = [];
        if (! $account->isEnabled()) {
            $blockers[] = ['code' => 'account_disabled', 'message' => 'WordPress账号已停用。'];
        }
        if ($account->adapter?->key !== PlatformAdapter::WORDPRESS) {
            $blockers[] = ['code' => 'adapter_mismatch', 'message' => '账号未绑定 WordPress 适配器。'];
        }
        if (! $account->distributionChannel instanceof DistributionChannel) {
            $blockers[] = ['code' => 'missing_distribution_channel', 'message' => '账号未绑定现有 WordPress 自动分发渠道。'];
        } elseif (! $account->distributionChannel->isWordPressRest()) {
            $blockers[] = ['code' => 'distribution_channel_type_mismatch', 'message' => '账号绑定的渠道不是 WordPress REST 类型。'];
        } elseif ((string) $account->distributionChannel->status !== DistributionChannel::STATUS_ACTIVE) {
            $blockers[] = ['code' => 'distribution_channel_inactive', 'message' => 'WordPress 分发渠道未启用。'];
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'context' => [
                'adapter_key' => PlatformAdapter::WORDPRESS,
                'distribution_channel_id' => $account->distribution_channel_id,
                'remote_write' => false,
            ],
        ];
    }

    public function buildPayload(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        return $this->bridge->previewPayload($variant->master, $variant->content_type);
    }

    public function fillDraft(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        $preflight = $this->preflight($account);

        return [
            'status' => $preflight['ok'] ? 'preview_ready' : 'blocked',
            'operation' => 'preview_only',
            'remote_write' => false,
            'payload' => $preflight['ok'] ? $this->buildPayload($variant, $account) : [],
            'blockers' => $preflight['blockers'],
        ];
    }

    public function readback(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        return $this->bridge->readback($variant);
    }

    public function normalizeReceipt(array $receipt, ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        return [
            'remote_id' => trim((string) ($receipt['remote_id'] ?? '')) ?: null,
            'remote_url' => trim((string) ($receipt['remote_url'] ?? '')) ?: null,
            'account_hash' => hash('sha256', PlatformAdapter::WORDPRESS.'|'.(string) $account->external_account_hash),
            'adapter_key' => PlatformAdapter::WORDPRESS,
            'adapter_version' => (string) ($account->adapter_version ?? $variant->adapter_version),
            'payload_hash' => $variant->payload_hash,
            'field_differences' => array_values((array) ($receipt['field_differences'] ?? [])),
            'readback_at' => now()->toAtomString(),
        ];
    }
}
