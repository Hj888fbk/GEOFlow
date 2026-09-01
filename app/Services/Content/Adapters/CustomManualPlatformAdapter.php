<?php

namespace App\Services\Content\Adapters;

use App\Models\ChannelVariant;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;

final class CustomManualPlatformAdapter extends AbstractBrowserAssistedPlatformAdapter
{
    protected function key(): string
    {
        return 'custom-manual';
    }

    protected function fieldContract(): array
    {
        return [
            'title' => ['required' => true],
            'summary' => ['required' => false],
            'content_markdown' => ['required' => true],
            'export_format' => ['enum' => ['json', 'markdown', 'csv'], 'required' => true],
        ];
    }

    public function capabilities(): array
    {
        return [
            'adapter_key' => $this->key(),
            'execution_mode' => PlatformAdapter::EXECUTION_MANUAL_EXPORT,
            'content_types' => $this->contentTypes(),
            'field_contract' => $this->fieldContract(),
            'supports_fill_draft' => false,
            'supports_manual_export' => true,
            'supports_auto_submit' => false,
            'requires_human_final_confirmation' => true,
            'browser_session_storage' => 'not_applicable',
            'stop_conditions' => [],
        ];
    }

    public function preflight(ManualPublicationAccount $account, array $context = []): array
    {
        $blockers = [];
        if (! $account->isEnabled()) {
            $blockers[] = $this->blocker('account_disabled', '发布账号已停用。');
        }
        if ($account->adapter?->key !== $this->key()) {
            $blockers[] = $this->blocker('adapter_mismatch', '账号绑定的适配器与自定义平台不一致。');
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'context' => [
                'adapter_key' => $this->key(),
                'account_id' => $account->getKey(),
                'requires_human_final_confirmation' => true,
            ],
        ];
    }

    public function fillDraft(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        $preflight = $this->preflight($account);

        return [
            'status' => $preflight['ok'] ? 'draft_ready' : 'blocked',
            'operation' => 'manual_export_only',
            'final_publish_clicked' => false,
            'requires_human_final_confirmation' => true,
            'payload' => $preflight['ok'] ? $this->buildPayload($variant, $account) : [],
            'blockers' => $preflight['blockers'],
        ];
    }

    protected function mapPackage(array $package, array $base): array
    {
        return [
            'title' => $base['title'],
            'summary' => $base['summary'],
            'content_markdown' => $base['body_markdown'],
            'parameters' => $base['parameters'],
            'faq' => $base['faq'],
            'media' => $base['media'],
            'source_ids' => $base['source_ids'],
            'export_format' => 'json',
            'account' => $base['account'],
        ];
    }
}
