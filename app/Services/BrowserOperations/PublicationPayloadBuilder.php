<?php

namespace App\Services\BrowserOperations;

use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use Illuminate\Support\Arr;

final class PublicationPayloadBuilder
{
    /** @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function build(array $attributes): array
    {
        $type = (string) ($attributes['type'] ?? ManualPublication::TYPE_POST);
        $platform = (string) ($attributes['platform'] ?? '');
        $source = $this->arrayValue($attributes['source_snapshot'] ?? null);
        $assetIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) Arr::get($source, 'asset_ids', []),
        ), static fn (int $id): bool => $id > 0));

        $isBatchWorkOrder = ! empty($attributes['manual_publication_batch_id']);
        $targetAction = match ($platform) {
            ManualPublicationAccount::PLATFORM_QQ_PENGUIN => 'qq_penguin_article',
            ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN => 'zhihu_column_article',
            ManualPublicationAccount::PLATFORM_BAIJIAHAO => 'baijiahao_article',
            ManualPublicationAccount::PLATFORM_NETEASE_MEDIA => 'netease_media_article',
            ManualPublicationAccount::PLATFORM_SOHU_MEDIA => 'sohu_media_article',
            ManualPublicationAccount::PLATFORM_WEIBO => 'weibo_post',
            ManualPublicationAccount::PLATFORM_CSDN => 'csdn_article',
            ManualPublicationAccount::PLATFORM_DAYU => 'dayu_article',
            default => $platform === ManualPublicationAccount::PLATFORM_ZHIHU && $type === ManualPublication::TYPE_POST
                ? 'zhihu_answer'
                : 'manual_'.$type,
        };
        $payload = [
            'schema_version' => $isBatchWorkOrder ? 2 : 1,
            'target_action' => $targetAction,
            'title' => trim((string) ($attributes['platform_title'] ?? Arr::get($source, 'title', ''))),
            'body_plain' => (string) ($attributes['content'] ?? ''),
            'body_markdown' => (string) ($attributes['body_markdown'] ?? $attributes['content'] ?? ''),
            'tags' => array_values($this->arrayValue($attributes['tags'] ?? null)),
            'canonical_url' => Arr::get($source, 'formal_url', $attributes['target_url'] ?? null),
            'disclosure' => $attributes['disclosure_snapshot'] ?? null,
            'asset_ids' => $assetIds,
        ];

        if (! $isBatchWorkOrder) {
            return $payload;
        }

        $identity = $this->arrayValue($attributes['identity_snapshot'] ?? null);
        $profileUrl = trim((string) Arr::get($identity, 'account.profile_url', ''));
        $payload['summary'] = trim((string) ($attributes['platform_summary'] ?? ''));
        $payload['body_html'] = $attributes['body_html'] ?? null;
        $payload['media_manifest'] = array_values($this->arrayValue($attributes['media_manifest'] ?? null));
        $payload['source_hash'] = $attributes['source_hash'] ?? Arr::get($source, 'source_hash');
        $payload['account_verification'] = [
            'expected_profile_hash' => $profileUrl === '' ? null : hash('sha256', $this->normalizeProfileUrl($profileUrl)),
            'account_uid' => Arr::get($identity, 'account.account_uid'),
            'homepage_identifier' => Arr::get($identity, 'account.homepage_identifier'),
        ];

        return $payload;
    }

    private function normalizeProfileUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port).strtolower($path);
    }

    /** @return array<mixed> */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
