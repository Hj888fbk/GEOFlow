<?php

namespace App\Services\Content;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformAccountService
{
    public function __construct(
        private readonly PlatformAdapterManager $adapterManager,
        private readonly DistributionOrchestrator $distributionOrchestrator,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(array $data, Admin $admin): ManualPublicationAccount
    {
        $this->assertNoSensitiveFields($data);

        try {
            return DB::transaction(function () use ($data, $admin): ManualPublicationAccount {
                $adapter = $this->adapter((string) $data['adapter_key']);
                $payload = $this->payload($data, $adapter);
                $this->assertUniqueAccount($adapter, (string) $payload['external_account_hash']);

                return ManualPublicationAccount::query()->create($payload + [
                    'created_by_admin_id' => $admin->getKey(),
                ])->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowDuplicateAccount($exception);
        }
    }

    /** @param array<string,mixed> $data */
    public function update(ManualPublicationAccount $account, array $data): ManualPublicationAccount
    {
        $this->assertNoSensitiveFields($data);

        try {
            return DB::transaction(function () use ($account, $data): ManualPublicationAccount {
                $locked = ManualPublicationAccount::query()
                    ->with('adapter')
                    ->whereKey($account->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $adapter = $this->adapter((string) ($data['adapter_key'] ?? $locked->adapter?->key));
                $payload = $this->payload($data, $adapter, $locked);
                $this->assertUniqueAccount(
                    $adapter,
                    (string) $payload['external_account_hash'],
                    (int) $locked->getKey(),
                );
                $locked->fill($payload)->save();

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowDuplicateAccount($exception);
        }
    }

    public function disable(ManualPublicationAccount $account): ManualPublicationAccount
    {
        $account->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'authorization_status' => 'disabled',
        ])->save();

        return $account->refresh();
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function verifyConnection(ManualPublicationAccount $account, array $context = []): array
    {
        $account->loadMissing(['adapter', 'distributionChannel']);
        $adapter = $this->adapterManager->forModel($account->adapter);
        $preflight = $adapter->preflight($account, $context);
        $remote = null;
        $isManualExport = $account->connection_mode === PlatformAdapter::EXECUTION_MANUAL_EXPORT;

        if ($preflight['ok'] && $account->adapter->key === PlatformAdapter::WORDPRESS) {
            $channel = $account->distributionChannel;
            if (! $channel instanceof DistributionChannel) {
                $preflight['ok'] = false;
                $preflight['blockers'][] = ['code' => 'missing_distribution_channel', 'message' => '未绑定 WordPress 分发渠道。'];
            } else {
                try {
                    $remote = $this->distributionOrchestrator->healthCheck($channel);
                    if (($remote['ok'] ?? false) !== true) {
                        $preflight['ok'] = false;
                        $preflight['blockers'][] = ['code' => 'remote_health_failed', 'message' => 'WordPress 远端能力检查未通过。'];
                    }
                } catch (\Throwable $exception) {
                    $preflight['ok'] = false;
                    $preflight['blockers'][] = ['code' => 'remote_health_failed', 'message' => 'WordPress 远端能力检查失败。'];
                    $remote = ['ok' => false, 'error' => $exception::class];
                }
            }
        } elseif ($preflight['ok'] && ! $isManualExport) {
            $observed = trim((string) ($context['observed_account_name'] ?? ''));
            $localSession = ($context['browser_session_local'] ?? false) === true;
            if ($observed === '' || ! $localSession) {
                $preflight['ok'] = false;
                $preflight['blockers'][] = ['code' => 'browser_identity_not_observed', 'message' => '需要在本机浏览器中核对当前登录账号；Cookie 不上传服务器。'];
            }
        }

        $firstBlocker = $preflight['blockers'][0] ?? null;
        if (! $account->isEnabled()) {
            $authorizationStatus = 'disabled';
            $loginStatus = 'disabled';
        } elseif ($preflight['ok'] && $isManualExport) {
            $authorizationStatus = 'not_required';
            $loginStatus = 'not_required';
        } else {
            $authorizationStatus = $preflight['ok'] ? 'connected' : 'attention_required';
            $loginStatus = $preflight['ok'] ? 'verified' : 'not_verified';
        }
        $account->forceFill([
            'authorization_status' => $authorizationStatus,
            'login_status' => $loginStatus,
            'capability_snapshot' => [
                'adapter' => $adapter->capabilities(),
                'remote' => $remote,
                'connection_mode' => $account->connection_mode,
                'verification_mode' => $isManualExport ? 'manual_export_no_login' : 'connection_and_identity',
                'verified_at' => now()->toAtomString(),
            ],
            'adapter_version' => (string) $account->adapter->version,
            'last_verified_at' => now(),
            'last_error_code' => is_array($firstBlocker) ? (string) ($firstBlocker['code'] ?? '') : null,
            'last_error_message' => is_array($firstBlocker) ? (string) ($firstBlocker['message'] ?? '') : null,
        ])->save();

        return $preflight + ['remote' => $remote, 'account' => $this->present($account->refresh())];
    }

    public function refreshCapabilities(ManualPublicationAccount $account): ManualPublicationAccount
    {
        $account->loadMissing('adapter');
        $capabilities = $this->adapterManager->forModel($account->adapter)->capabilities();
        $account->forceFill([
            'capability_snapshot' => ['adapter' => $capabilities, 'refreshed_at' => now()->toAtomString()],
            'adapter_version' => (string) $account->adapter->version,
        ])->save();

        return $account->refresh();
    }

    /** @return array<string,mixed> */
    public function present(ManualPublicationAccount $account): array
    {
        $account->loadMissing(['adapter:id,key,name,version,execution_mode', 'persona:id,name', 'distributionChannel:id,name,domain,status']);

        return [
            'id' => (int) $account->getKey(),
            'adapter' => $account->adapter ? [
                'key' => $account->adapter->key,
                'name' => $account->adapter->name,
                'version' => $account->adapter->version,
                'execution_mode' => $account->adapter->execution_mode,
            ] : null,
            'persona' => $account->persona ? ['id' => $account->persona->id, 'name' => $account->persona->name] : null,
            'distribution_channel' => $account->distributionChannel ? [
                'id' => $account->distributionChannel->id,
                'name' => $account->distributionChannel->name,
                'domain' => $account->distributionChannel->domain,
                'status' => $account->distributionChannel->status,
            ] : null,
            'account_name' => $account->account_name,
            'subject_name' => $account->subject_name,
            'connection_mode' => $account->connection_mode,
            'content_types' => (array) $account->content_types,
            'authorization_status' => $account->authorization_status,
            'login_status' => $account->login_status,
            'capability_snapshot' => $account->capability_snapshot,
            'last_verified_at' => $account->last_verified_at?->toAtomString(),
            'is_active' => (bool) $account->is_active,
            'disabled_at' => $account->disabled_at?->toAtomString(),
            'last_error_code' => $account->last_error_code,
            'last_error_message' => $account->last_error_message,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function payload(array $data, PlatformAdapter $adapter, ?ManualPublicationAccount $existing = null): array
    {
        $accountName = trim((string) ($data['account_name'] ?? $existing?->account_name ?? ''));
        if ($accountName === '') {
            throw ValidationException::withMessages(['account_name' => '平台账号名称不能为空。']);
        }
        $distributionChannelId = isset($data['distribution_channel_id'])
            ? (int) $data['distribution_channel_id']
            : $existing?->distribution_channel_id;
        if ($adapter->key === PlatformAdapter::WORDPRESS && ! $distributionChannelId) {
            throw new \DomainException('WordPress账号必须绑定现有加密分发渠道。');
        }
        $distributionChannel = $distributionChannelId
            ? DistributionChannel::query()->whereKey($distributionChannelId)->first()
            : null;
        if ($distributionChannelId !== null && ! $distributionChannel instanceof DistributionChannel) {
            throw new \DomainException('所选分发渠道不存在。');
        }
        if ($adapter->key === PlatformAdapter::WORDPRESS
            && (! $distributionChannel?->isWordPressRest() || $distributionChannel->status !== DistributionChannel::STATUS_ACTIVE)) {
            throw new \DomainException('WordPress账号必须绑定已启用的 WordPress REST 分发渠道。');
        }

        $connectionMode = (string) ($data['connection_mode'] ?? $existing?->connection_mode ?? $adapter->execution_mode);
        if (! in_array($connectionMode, (array) $adapter->connection_modes, true)) {
            throw new \DomainException('所选接入方式不在该平台适配器的批准能力范围内。');
        }

        $contentTypes = array_values(array_unique(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) ($data['content_types'] ?? $existing?->content_types ?? $adapter->supported_content_types),
        )));
        $contentTypes = array_values(array_filter($contentTypes));
        if ($contentTypes === [] || array_diff($contentTypes, (array) $adapter->supported_content_types) !== []) {
            throw new \DomainException('内容类型不在该平台适配器的批准能力范围内。');
        }

        $isActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : (bool) ($existing?->is_active ?? true);
        $resetConnectionState = ! $existing
            || $connectionMode !== (string) $existing->connection_mode
            || (! $existing->is_active && $isActive);
        $connectionState = [];
        if (! $isActive) {
            $connectionState = ['authorization_status' => 'disabled', 'login_status' => 'disabled'];
        } elseif ($resetConnectionState) {
            $connectionState = $connectionMode === PlatformAdapter::EXECUTION_MANUAL_EXPORT
                ? ['authorization_status' => 'not_required', 'login_status' => 'not_required']
                : ['authorization_status' => 'not_connected', 'login_status' => 'not_verified'];
        }

        return [
            'persona_id' => (int) ($data['persona_id'] ?? $existing?->persona_id),
            'platform_adapter_id' => $adapter->getKey(),
            'distribution_channel_id' => $distributionChannelId,
            'platform' => $this->legacyPlatform($adapter->key),
            'custom_platform' => $adapter->key === PlatformAdapter::CUSTOM_MANUAL
                ? trim((string) ($data['custom_platform'] ?? $existing?->custom_platform ?? '')) ?: '自定义平台'
                : null,
            'account_name' => $accountName,
            'profile_url' => trim((string) ($data['profile_url'] ?? $existing?->profile_url ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? $existing?->notes ?? '')) ?: null,
            'is_active' => $isActive,
            'connection_mode' => $connectionMode,
            'subject_name' => trim((string) ($data['subject_name'] ?? $existing?->subject_name ?? '')) ?: null,
            'brand_voice' => trim((string) ($data['brand_voice'] ?? $existing?->brand_voice ?? '')) ?: null,
            'person_voice' => trim((string) ($data['person_voice'] ?? $existing?->person_voice ?? '')) ?: null,
            'content_types' => $contentTypes,
            'publishing_rules' => (array) ($data['publishing_rules'] ?? $existing?->publishing_rules ?? []),
            'external_account_hash' => hash('sha256', $adapter->key.'|'.$this->normalizeAccount($accountName)),
            'adapter_version' => $adapter->version,
            'disabled_at' => $isActive ? null : ($existing?->disabled_at ?? now()),
        ] + $connectionState;
    }

    private function adapter(string $key): PlatformAdapter
    {
        return PlatformAdapter::query()->where('key', $key)->where('status', 'active')->firstOrFail();
    }

    private function normalizeAccount(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($name)), 'UTF-8');
    }

    private function legacyPlatform(string $key): string
    {
        return match ($key) {
            PlatformAdapter::WORDPRESS => ManualPublicationAccount::PLATFORM_WORDPRESS,
            PlatformAdapter::BAIDU_AICAIGOU => ManualPublicationAccount::PLATFORM_BAIDU_AICAIGOU,
            PlatformAdapter::ALIBABA_1688 => ManualPublicationAccount::PLATFORM_1688,
            PlatformAdapter::SOHU => ManualPublicationAccount::PLATFORM_SOHU,
            PlatformAdapter::BAIJIAHAO => ManualPublicationAccount::PLATFORM_BAIJIAHAO,
            default => ManualPublicationAccount::PLATFORM_CUSTOM,
        };
    }

    /** @param array<string,mixed> $data */
    private function assertNoSensitiveFields(array $data, string $prefix = ''): void
    {
        $sensitiveKeys = [
            'cookie', 'cookies', 'browser_session', 'session', 'session_token',
            'api_key', 'api_secret', 'access_token', 'refresh_token', 'token',
            'password', 'credential', 'credentials', 'secret',
        ];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $key));
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array(trim($normalizedKey, '_'), $sensitiveKeys, true)) {
                throw new \DomainException('平台账号配置不得保存 Cookie、会话或 API 凭据：'.$path);
            }
            if (is_array($value)) {
                $this->assertNoSensitiveFields($value, $path);
            }
        }
    }

    private function assertUniqueAccount(
        PlatformAdapter $adapter,
        string $externalAccountHash,
        ?int $ignoreAccountId = null,
    ): void {
        $query = ManualPublicationAccount::query()
            ->where('platform_adapter_id', $adapter->getKey())
            ->where('external_account_hash', $externalAccountHash);
        if ($ignoreAccountId !== null) {
            $query->whereKeyNot($ignoreAccountId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'account_name' => '该平台账号已存在；请更新现有账号，不要重复添加。',
            ]);
        }
    }

    private function rethrowDuplicateAccount(QueryException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage();
        $isDuplicate = in_array($sqlState, ['23000', '23505'], true)
            && (str_contains($message, 'manual_publication_accounts_adapter_account_unique')
                || (str_contains($message, 'platform_adapter_id') && str_contains($message, 'external_account_hash')));
        if ($isDuplicate) {
            throw ValidationException::withMessages([
                'account_name' => '该平台账号已存在；请更新现有账号，不要重复添加。',
            ]);
        }

        throw $exception;
    }
}
