<?php

namespace App\Services\Content;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ChannelVariant;
use App\Models\ContentMaster;
use App\Models\ManualPublication;
use App\Models\PlatformAdapter;
use App\Services\GeoFlow\ManualPublicationService;
use Illuminate\Support\Facades\DB;

final class ChannelPublicationService
{
    public function __construct(
        private readonly PlatformAdapterManager $adapterManager,
        private readonly ManualPublicationService $manualPublicationService,
        private readonly WordPressContentBridge $wordpressBridge,
        private readonly HengjiaContentPackageValidator $validator,
    ) {}

    public function approve(ChannelVariant $variant, Admin $reviewer): ChannelVariant
    {
        return DB::transaction(function () use ($variant, $reviewer): ChannelVariant {
            $locked = ChannelVariant::query()
                ->with(['master', 'adapter'])
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertVariantIntegrity($locked);
            if ($locked->status === ChannelVariant::STATUS_APPROVED) {
                return $locked;
            }
            if ($locked->status !== ChannelVariant::STATUS_DRAFT
                || $locked->master?->status !== ContentMaster::STATUS_PROMOTED
                || (array) $locked->blockers !== []) {
                throw new \DomainException('渠道版本或内容母版仍有阻断，不能批准。');
            }
            $locked->forceFill([
                'status' => ChannelVariant::STATUS_APPROVED,
                'receipt' => array_replace((array) $locked->receipt, [
                    'approved_by_admin_id' => (int) $reviewer->getKey(),
                    'approved_at' => now()->toAtomString(),
                ]),
            ])->save();

            return $locked->refresh();
        });
    }

    public function prepare(ChannelVariant $variant, Admin $operator): ChannelVariant
    {
        [$prepared, $error] = DB::transaction(function () use ($variant, $operator): array {
            $locked = ChannelVariant::query()
                ->with(['master.article', 'adapter', 'account.persona', 'distributionChannel'])
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertVariantIntegrity($locked);
            if (in_array((string) $locked->status, [ChannelVariant::STATUS_QUEUED, ChannelVariant::STATUS_AWAITING_MANUAL_CONFIRMATION], true)) {
                return [$locked, null];
            }
            if ($locked->status !== ChannelVariant::STATUS_APPROVED) {
                throw new \DomainException('渠道版本必须先逐渠道批准。');
            }
            if (! $locked->account) {
                throw new \DomainException('渠道版本未绑定账号。');
            }

            if ($locked->adapter->key === PlatformAdapter::WORDPRESS) {
                $this->wordpressBridge->queueApprovedVariant($locked, $operator);

                return [$locked->refresh(), null];
            }

            $article = $locked->master?->article;
            if (! $article instanceof Article || ! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
                throw new \DomainException('现有 Article 尚未完成审核，不能生成外部平台发布工单。');
            }
            $adapter = $this->adapterManager->forModel($locked->adapter);
            if ($locked->account->connection_mode === PlatformAdapter::EXECUTION_MANUAL_EXPORT) {
                $preflight = $adapter->preflight($locked->account);
                $draft = [
                    'status' => $preflight['ok'] ? 'draft_ready' : 'blocked',
                    'operation' => 'manual_export_only',
                    'payload' => $preflight['ok'] ? $adapter->buildPayload($locked, $locked->account) : [],
                    'blockers' => $preflight['blockers'],
                ];
            } else {
                $draft = $adapter->fillDraft($locked, $locked->account);
            }
            if (($draft['status'] ?? '') !== 'draft_ready') {
                $locked->forceFill([
                    'status' => ChannelVariant::STATUS_BLOCKED,
                    'blockers' => (array) ($draft['blockers'] ?? []),
                ])->save();

                return [$locked, '渠道预检未通过，已安全停止。'];
            }

            $publication = ManualPublication::query()
                ->where('channel_variant_id', $locked->getKey())
                ->first();
            if (! $publication) {
                $content = $this->contentForManualPublication((array) $draft['payload']);
                $publication = $this->manualPublicationService->create([
                    'type' => ManualPublication::TYPE_POST,
                    'article_id' => $article->getKey(),
                    'persona_id' => $locked->account->persona_id,
                    'account_id' => $locked->account->getKey(),
                    'assigned_admin_id' => $operator->getKey(),
                    'platform' => $locked->account->platform,
                    'custom_platform' => $locked->account->custom_platform,
                    'target_url' => $locked->account->profile_url,
                    'target_context' => $locked->account->connection_mode === PlatformAdapter::EXECUTION_MANUAL_EXPORT
                        ? '由恒佳内容中台生成的人工导出稿；运营人员核对后自行发布并登记回执。'
                        : '由恒佳内容中台生成的安全草稿；运营人员核对后手动点击最终发布。',
                    'content' => $content,
                    'scheduled_at' => $locked->scheduled_at,
                    'status' => ManualPublication::STATUS_READY,
                ], $operator);
                $publication->forceFill([
                    'channel_variant_id' => $locked->getKey(),
                    'platform_adapter_id' => $locked->platform_adapter_id,
                ])->save();
            }
            $locked->forceFill([
                'status' => ChannelVariant::STATUS_AWAITING_MANUAL_CONFIRMATION,
                'receipt' => array_replace((array) $locked->receipt, [
                    'manual_publication_id' => (int) $publication->getKey(),
                    'prepared_at' => now()->toAtomString(),
                    'operation' => (string) ($draft['operation'] ?? 'fill_draft_only'),
                    'final_publish_clicked' => false,
                    'requires_human_final_confirmation' => true,
                ]),
            ])->save();

            return [$locked->refresh(), null];
        });

        if (is_string($error)) {
            throw new \DomainException($error);
        }

        return $prepared;
    }

    /** @return array<string,mixed> */
    public function readback(ChannelVariant $variant): array
    {
        $variant->loadMissing(['adapter', 'account']);
        if (! $variant->account) {
            throw new \DomainException('渠道版本未绑定账号，无法回读。');
        }

        $adapter = $this->adapterManager->forModel($variant->adapter);
        if ($variant->adapter->key === PlatformAdapter::WORDPRESS) {
            return $adapter->readback($variant, $variant->account);
        }

        $publication = ManualPublication::query()
            ->where('channel_variant_id', $variant->getKey())
            ->latest('id')
            ->first();
        if (! $publication) {
            return [
                'status' => 'not_prepared',
                'remote_id' => null,
                'remote_url' => null,
                'field_differences' => [],
            ];
        }

        $normalized = $adapter->normalizeReceipt([
            'remote_id' => data_get($publication->execution_receipt, 'remote_id'),
            'remote_url' => $publication->completion_url,
            'field_differences' => data_get($publication->execution_receipt, 'field_differences', []),
        ], $variant, $variant->account);
        $status = match ((string) $publication->status) {
            ManualPublication::STATUS_COMPLETED => ChannelVariant::STATUS_PUBLISHED,
            ManualPublication::STATUS_FAILED => ChannelVariant::STATUS_FAILED,
            ManualPublication::STATUS_CANCELLED => ChannelVariant::STATUS_CANCELLED,
            ManualPublication::STATUS_OUTCOME_UNKNOWN => ChannelVariant::STATUS_OUTCOME_UNKNOWN,
            default => ChannelVariant::STATUS_AWAITING_MANUAL_CONFIRMATION,
        };
        $result = $normalized + [
            'status' => $status,
            'manual_publication_id' => (int) $publication->getKey(),
            'manual_status' => (string) $publication->status,
            'result_note' => $publication->result_note,
        ];
        $variant->forceFill([
            'status' => $status,
            'remote_id' => $normalized['remote_id'] ?? null,
            'remote_url' => $normalized['remote_url'] ?? null,
            'receipt' => array_replace((array) $variant->receipt, $result),
            'last_readback_at' => now(),
        ])->save();

        return $result;
    }

    /** @param array<string,mixed> $payload */
    private function contentForManualPublication(array $payload): string
    {
        foreach (['content_markdown', 'description_markdown', 'detail_markdown', 'body_markdown'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) !== '') {
                return trim((string) $payload[$field]);
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function assertVariantIntegrity(ChannelVariant $variant): void
    {
        $variant->loadMissing(['master', 'adapter']);
        if (! $variant->master instanceof ContentMaster || ! $variant->adapter instanceof PlatformAdapter) {
            throw new \DomainException('渠道版本缺少内容母版或适配器。');
        }
        $payloadHash = hash('sha256', json_encode(
            (array) $variant->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        if ((string) $variant->payload_hash === '' || ! hash_equals((string) $variant->payload_hash, $payloadHash)) {
            throw new \DomainException('渠道版本载荷哈希校验失败。');
        }
        if ((string) $variant->adapter_version !== (string) $variant->adapter->version) {
            throw new \DomainException('渠道适配器版本已变化，请生成新的内容母版和渠道版本。');
        }
        $packageHash = $this->validator->hash((array) $variant->master->package);
        if ((string) $variant->master->package_hash === ''
            || ! hash_equals((string) $variant->master->package_hash, $packageHash)) {
            throw new \DomainException('内容母版哈希校验失败，已停止渠道执行。');
        }
    }
}
