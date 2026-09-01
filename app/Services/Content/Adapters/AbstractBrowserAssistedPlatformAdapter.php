<?php

namespace App\Services\Content\Adapters;

use App\Contracts\Content\PlatformAdapter as PlatformAdapterContract;
use App\Models\ChannelVariant;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;

abstract class AbstractBrowserAssistedPlatformAdapter implements PlatformAdapterContract
{
    abstract protected function key(): string;

    /** @return array<string,mixed> */
    abstract protected function fieldContract(): array;

    /** @return list<string> */
    protected function contentTypes(): array
    {
        return ['article'];
    }

    public function capabilities(): array
    {
        return [
            'adapter_key' => $this->key(),
            'execution_mode' => PlatformAdapter::EXECUTION_BROWSER_ASSISTED,
            'content_types' => $this->contentTypes(),
            'field_contract' => $this->fieldContract(),
            'supports_fill_draft' => true,
            'supports_manual_export' => true,
            'supports_auto_submit' => false,
            'requires_human_final_confirmation' => true,
            'browser_session_storage' => 'local_only',
            'stop_conditions' => ['captcha', 'account_mismatch', 'login_expired', 'page_structure_drift'],
        ];
    }

    public function preflight(ManualPublicationAccount $account, array $context = []): array
    {
        $blockers = [];
        if (! $account->isEnabled()) {
            $blockers[] = $this->blocker('account_disabled', '发布账号已停用。');
        }
        if ($account->adapter?->key !== $this->key()) {
            $blockers[] = $this->blocker('adapter_mismatch', '账号绑定的适配器与目标平台不一致。');
        }
        foreach (['captcha', 'login_expired', 'page_structure_drift'] as $stop) {
            if (($context[$stop] ?? false) === true) {
                $blockers[] = $this->blocker($stop, '平台预检触发安全停止：'.$stop.'。');
            }
        }
        $observed = trim((string) ($context['observed_account_name'] ?? ''));
        if ($observed !== '' && $this->normalizeAccount($observed) !== $this->normalizeAccount((string) $account->account_name)) {
            $blockers[] = $this->blocker('account_mismatch', '当前浏览器登录账号与任务目标账号不一致。');
        }
        $requiresVerifiedLogin = ($context['require_verified_login'] ?? false) === true
            && $account->connection_mode !== PlatformAdapter::EXECUTION_MANUAL_EXPORT;
        if ($requiresVerifiedLogin && $account->login_status !== 'verified') {
            $blockers[] = $this->blocker('login_not_verified', '尚未核验当前浏览器登录账号。');
        }
        if ($requiresVerifiedLogin && $account->authorization_status !== 'connected') {
            $blockers[] = $this->blocker('authorization_not_connected', '平台账号授权状态不可用于草稿填充。');
        }
        if ($requiresVerifiedLogin
            && $account->authorization_expires_at !== null
            && $account->authorization_expires_at->isPast()) {
            $blockers[] = $this->blocker('authorization_expired', '平台账号授权已过期。');
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

    public function buildPayload(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        $package = (array) ($variant->master?->package ?? []);
        $seo = (array) ($package['seo'] ?? []);

        return $this->mapPackage($package, [
            'title' => (string) ($seo['title'] ?? $variant->master?->title ?? ''),
            'h1' => (string) ($seo['h1'] ?? $variant->master?->h1 ?? ''),
            'summary' => (string) ($seo['summary'] ?? $variant->master?->summary ?? ''),
            'meta_description' => (string) ($seo['meta_description'] ?? $variant->master?->meta_description ?? ''),
            'body_markdown' => $this->bodyMarkdown($package),
            'parameters' => array_values((array) ($package['parameters'] ?? [])),
            'faq' => array_values((array) ($package['faq'] ?? [])),
            'media' => array_values((array) ($package['media'] ?? [])),
            'source_ids' => collect((array) ($package['sources'] ?? []))->pluck('source_id')->filter()->values()->all(),
            'account' => [
                'id' => $account->getKey(),
                'name' => $account->account_name,
                'subject_name' => $account->subject_name,
                'brand_voice' => $account->brand_voice,
                'person_voice' => $account->person_voice,
            ],
        ]);
    }

    public function fillDraft(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        $preflight = $this->preflight($account, ['require_verified_login' => true]);

        return [
            'status' => $preflight['ok'] ? 'draft_ready' : 'blocked',
            'operation' => 'fill_draft_only',
            'final_publish_clicked' => false,
            'requires_human_final_confirmation' => true,
            'payload' => $preflight['ok'] ? $this->buildPayload($variant, $account) : [],
            'blockers' => $preflight['blockers'],
        ];
    }

    public function readback(ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        return [
            'status' => $variant->remote_url ? 'confirmed_by_operator' : 'awaiting_operator_receipt',
            'remote_id' => $variant->remote_id,
            'remote_url' => $variant->remote_url,
            'readback_mode' => 'manual_receipt',
        ];
    }

    public function normalizeReceipt(array $receipt, ChannelVariant $variant, ManualPublicationAccount $account): array
    {
        return [
            'remote_id' => trim((string) ($receipt['remote_id'] ?? '')) ?: null,
            'remote_url' => trim((string) ($receipt['remote_url'] ?? '')) ?: null,
            'account_hash' => hash('sha256', $this->key().'|'.(string) $account->external_account_hash),
            'adapter_key' => $this->key(),
            'adapter_version' => (string) ($account->adapter_version ?? $variant->adapter_version),
            'payload_hash' => $variant->payload_hash,
            'confirmed_at' => now()->toAtomString(),
            'field_differences' => array_values((array) ($receipt['field_differences'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $base @return array<string,mixed> */
    protected function mapPackage(array $package, array $base): array
    {
        return $base;
    }

    /** @param array<string,mixed> $package */
    protected function bodyMarkdown(array $package): string
    {
        return collect((array) ($package['body_sections'] ?? []))
            ->filter(fn (mixed $section): bool => is_array($section))
            ->map(fn (array $section): string => '## '.trim((string) ($section['heading'] ?? ''))."\n\n".trim((string) ($section['body'] ?? '')))
            ->filter()
            ->implode("\n\n");
    }

    /** @return array{code:string,message:string} */
    protected function blocker(string $code, string $message): array
    {
        return compact('code', 'message');
    }

    protected function normalizeAccount(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($name)), 'UTF-8');
    }
}
