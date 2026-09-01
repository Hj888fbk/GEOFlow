<?php

namespace App\Services\Content;

use App\Models\ChannelVariant;
use App\Models\ContentMaster;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChannelVariantBuilder
{
    public function __construct(private readonly PlatformAdapterManager $adapterManager) {}

    /** @return Collection<int,ChannelVariant> */
    public function build(ContentMaster $master): Collection
    {
        $master->loadMissing('task');
        if (! in_array((string) $master->status, [
            ContentMaster::STATUS_IN_REVIEW,
            ContentMaster::STATUS_APPROVED,
            ContentMaster::STATUS_PROMOTED,
        ], true)) {
            throw new \DomainException('内容母版当前状态不能生成渠道版本。');
        }

        return collect((array) $master->task->target_channels)
            ->map(function (mixed $target) use ($master): ChannelVariant {
                $target = is_array($target) ? $target : ['channel_key' => (string) $target];
                $key = trim((string) ($target['channel_key'] ?? ''));
                $adapterModel = PlatformAdapter::query()->where('key', $key)->where('status', 'active')->firstOrFail();
                $account = $this->resolveAccount($adapterModel, $target['account_id'] ?? null);
                $blockers = array_values((array) $master->blockers);
                if (! $account instanceof ManualPublicationAccount) {
                    $blockers[] = ['code' => 'missing_target_account', 'message' => '请选择 '.$adapterModel->name.' 的目标账号。'];
                }

                $contentType = (string) ($target['content_type'] ?? $this->defaultContentType($adapterModel));
                $supportsContentType = in_array($contentType, (array) $adapterModel->supported_content_types, true);
                if (! $supportsContentType) {
                    $blockers[] = [
                        'code' => 'unsupported_content_type',
                        'message' => $adapterModel->name.' 当前适配器不支持 '.$contentType.'；未执行发布。',
                    ];
                }
                $variantKey = hash('sha256', implode('|', [
                    (string) $master->getKey(),
                    (string) $adapterModel->getKey(),
                    (string) ($account?->getKey() ?? 0),
                    $contentType,
                ]));
                $variant = new ChannelVariant([
                    'content_master_id' => $master->getKey(),
                    'platform_adapter_id' => $adapterModel->getKey(),
                    'manual_publication_account_id' => $account?->getKey(),
                    'distribution_channel_id' => $account?->distribution_channel_id,
                    'variant_key' => $variantKey,
                    'channel_key' => $key,
                    'content_type' => $contentType,
                    'status' => ChannelVariant::STATUS_DRAFT,
                    'adapter_version' => $adapterModel->version,
                    'scheduled_at' => $target['scheduled_at'] ?? null,
                ]);
                $variant->setRelation('master', $master);
                $variant->setRelation('adapter', $adapterModel);
                if ($account) {
                    $account->setRelation('adapter', $adapterModel);
                    $variant->setRelation('account', $account);
                    $preflight = $this->adapterManager->forModel($adapterModel)->preflight($account);
                    $blockers = array_merge($blockers, $preflight['blockers']);
                }

                $payload = $account && $supportsContentType
                    ? $this->adapterManager->forModel($adapterModel)->buildPayload($variant, $account)
                    : $this->fallbackPayload($master);
                $blockers = array_merge($blockers, $this->fieldLimitBlockers($payload, (array) $adapterModel->field_contract));
                $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $desired = array_merge($variant->getAttributes(), [
                    'payload' => $payload,
                    'payload_hash' => $payloadHash,
                    'blockers' => $blockers,
                    'status' => $blockers === [] ? ChannelVariant::STATUS_DRAFT : ChannelVariant::STATUS_BLOCKED,
                ]);
                $stored = DB::transaction(function () use ($variantKey, $desired, $payloadHash, $adapterModel): ChannelVariant {
                    $existing = ChannelVariant::query()
                        ->where('variant_key', $variantKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing instanceof ChannelVariant
                        && ! in_array((string) $existing->status, ChannelVariant::EDITABLE_STATUSES, true)) {
                        $sameExecutionContract = hash_equals((string) $existing->payload_hash, $payloadHash)
                            && (string) $existing->adapter_version === (string) $adapterModel->version
                            && (int) $existing->distribution_channel_id === (int) ($desired['distribution_channel_id'] ?? 0)
                            && $this->sameSchedule($existing->scheduled_at, $desired['scheduled_at'] ?? null);
                        if (! $sameExecutionContract) {
                            throw new \DomainException('渠道版本已经批准或执行，载荷或适配器合同发生变化；请生成新的内容母版。');
                        }

                        return $existing;
                    }
                    if ($existing instanceof ChannelVariant) {
                        $existing->forceFill($desired)->save();

                        return $existing;
                    }

                    return ChannelVariant::query()->create($desired);
                });

                return $stored->refresh();
            })->values();
    }

    private function resolveAccount(PlatformAdapter $adapter, mixed $accountId): ?ManualPublicationAccount
    {
        $query = ManualPublicationAccount::query()
            ->where('platform_adapter_id', $adapter->getKey())
            ->where('is_active', true)
            ->whereNull('disabled_at');
        if (is_numeric($accountId) && (int) $accountId > 0) {
            return $query->whereKey((int) $accountId)->first();
        }

        $accounts = $query->limit(2)->get();

        return $accounts->count() === 1 ? $accounts->first() : null;
    }

    private function defaultContentType(PlatformAdapter $adapter): string
    {
        return match ((string) $adapter->key) {
            PlatformAdapter::BAIDU_AICAIGOU, PlatformAdapter::ALIBABA_1688 => 'product',
            PlatformAdapter::WORDPRESS => 'post',
            default => 'article',
        };
    }

    /** @return array<string,mixed> */
    private function fallbackPayload(ContentMaster $master): array
    {
        return [
            'title' => $master->title,
            'summary' => $master->summary,
            'content_package_hash' => $master->package_hash,
            'status' => 'account_selection_required',
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $contract @return list<array{code:string,message:string}> */
    private function fieldLimitBlockers(array $payload, array $contract): array
    {
        $blockers = [];
        foreach ($contract as $field => $rules) {
            if (! is_array($rules)) {
                continue;
            }
            $value = $payload[$field] ?? null;
            if (($rules['required'] ?? false) === true && ($value === null || $value === '' || $value === [])) {
                $blockers[] = ['code' => 'required_channel_field:'.$field, 'message' => '渠道必填字段缺失：'.$field];
            }
            if (is_string($value) && isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
                $blockers[] = ['code' => 'channel_field_too_long:'.$field, 'message' => $field.' 超过渠道长度上限 '.(int) $rules['max'].'。'];
            }
            if (is_array($value) && isset($rules['max']) && count($value) > (int) $rules['max']) {
                $blockers[] = ['code' => 'channel_field_too_many:'.$field, 'message' => $field.' 超过渠道数量上限 '.(int) $rules['max'].'。'];
            }
        }

        return $blockers;
    }

    private function sameSchedule(mixed $current, mixed $desired): bool
    {
        if ($current === null && ($desired === null || $desired === '')) {
            return true;
        }
        if ($current === null || $desired === null || $desired === '') {
            return false;
        }

        return Carbon::parse($current)->equalTo(Carbon::parse($desired));
    }
}
