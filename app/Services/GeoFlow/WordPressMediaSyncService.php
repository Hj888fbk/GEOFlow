<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WordPressMediaSyncService
{
    /** @var list<array{id:int,source_url:string,filename:string}> */
    private array $lastUploadedMedia = [];

    /** @var array<string,array{id:int,source_url:string}> */
    private array $mediaMap = [];

    public function __construct(private readonly WordPressRestRequestFactory $requestFactory) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function rewriteContentImages(DistributionChannel $channel, array $payload, string $contentHtml, ?ArticleDistribution $distribution = null): string
    {
        $this->lastUploadedMedia = [];
        $this->mediaMap = $this->existingMediaMap($distribution);

        if ($channel->resolvedChannelConfig()['wordpress_image_strategy'] === 'keep_original') {
            return $contentHtml;
        }

        $assets = is_array($payload['assets'] ?? null) ? $payload['assets'] : [];
        $images = is_array($assets['images'] ?? null) ? $assets['images'] : [];
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $sourceUrl = (string) ($image['source_url'] ?? '');
            $contentBase64 = (string) ($image['content_base64'] ?? '');
            if ($sourceUrl === '' || $contentBase64 === '') {
                Log::warning('WordPress 分发图片缺少可上传内容，目标站可能展示裂图。', [
                    'distribution_channel_id' => (int) $channel->id,
                    'article_distribution_id' => $distribution?->id,
                    'article_id' => (int) data_get($payload, 'article.id', 0),
                    'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                    'skip_reason' => $image['skip_reason'] ?? ($contentBase64 === '' ? 'missing_content_base64' : 'missing_source_url'),
                ]);

                continue;
            }

            $binary = base64_decode($contentBase64, true);
            if (! is_string($binary) || $binary === '') {
                Log::warning('WordPress 分发图片内容无法解码，目标站可能展示裂图。', [
                    'distribution_channel_id' => (int) $channel->id,
                    'article_distribution_id' => $distribution?->id,
                    'article_id' => (int) data_get($payload, 'article.id', 0),
                    'source_url' => $sourceUrl,
                    'skip_reason' => 'invalid_content_base64',
                ]);

                continue;
            }

            // 内容级幂等：同一二进制（按源内容 sha256 识别）已上传过就直接复用
            // 远端媒体 URL，避免发布重试时在 WP 媒体库产生重复文件。
            $contentHash = hash('sha256', $binary);
            $mapped = $this->mediaMap[$contentHash] ?? null;
            if (is_array($mapped) && (int) ($mapped['id'] ?? 0) > 0 && (string) ($mapped['source_url'] ?? '') !== '') {
                $contentHtml = str_replace($sourceUrl, (string) $mapped['source_url'], $contentHtml);
                $this->lastUploadedMedia[] = [
                    'id' => (int) $mapped['id'],
                    'source_url' => (string) $mapped['source_url'],
                    'filename' => $this->safeFilename((string) ($image['filename'] ?? '')),
                ];

                continue;
            }

            $filename = $this->safeFilename((string) ($image['filename'] ?? ''));
            $uploaded = $this->uploadImage($channel, $binary, $filename, (string) ($image['mime_type'] ?? 'application/octet-stream'), $sourceUrl);
            if ($uploaded !== null) {
                $contentHtml = str_replace($sourceUrl, $uploaded['source_url'], $contentHtml);
                $this->mediaMap[$contentHash] = $uploaded;
                $this->lastUploadedMedia[] = [
                    'id' => $uploaded['id'],
                    'source_url' => $uploaded['source_url'],
                    'filename' => $filename,
                ];
            }
        }

        return $contentHtml;
    }

    /**
     * 返回上一轮 rewriteContentImages 上传到媒体库的文件（含 WP 媒体 ID），
     * 读取后清空。发布侧用它把第一张正文图设为文章特色图片（featured_media）。
     *
     * @return list<array{id:int,source_url:string,filename:string}>
     */
    public function takeLastUploadedMedia(): array
    {
        $uploaded = $this->lastUploadedMedia;
        $this->lastUploadedMedia = [];

        return $uploaded;
    }

    /**
     * 返回本轮 rewriteContentImages 后的媒体映射（源内容 sha256 → WP 媒体），
     * 读取后清空。发布侧把它并入 distribution 的 remote_meta 供重试复用。
     *
     * @return array<string,array{id:int,source_url:string}>
     */
    public function takeMediaMap(): array
    {
        $mediaMap = $this->mediaMap;
        $this->mediaMap = [];

        return $mediaMap;
    }

    /**
     * @return array{id:int,source_url:string}|null
     */
    private function uploadImage(DistributionChannel $channel, string $binary, string $filename, string $mimeType, string $sourceUrl = ''): ?array
    {
        $mimeType = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $response = $this->requestFactory->request($channel)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ])
            ->withBody($binary, $mimeType)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/media');
        if ($response->failed()) {
            Log::error('WordPress 媒体上传失败。', [
                'distribution_channel_id' => (int) $channel->id,
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                'filename' => $filename,
                'http_status' => $response->status(),
            ]);
        }
        $this->throwIfFailed($response, 'WordPress 媒体上传');
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $remoteUrl = (string) ($json['source_url'] ?? '');
        if ($remoteUrl === '') {
            return null;
        }

        return [
            'id' => (int) ($json['id'] ?? 0),
            'source_url' => $remoteUrl,
        ];
    }

    /**
     * @return array<string,array{id:int,source_url:string}>
     */
    private function existingMediaMap(?ArticleDistribution $distribution): array
    {
        $map = data_get($distribution?->remote_meta, 'wp_media_map');
        if (! is_array($map)) {
            return [];
        }

        $normalized = [];
        foreach ($map as $hash => $entry) {
            if (! is_string($hash) || ! is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            $sourceUrl = (string) ($entry['source_url'] ?? '');
            if ($id > 0 && $sourceUrl !== '') {
                $normalized[$hash] = ['id' => $id, 'source_url' => $sourceUrl];
            }
        }

        return $normalized;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(basename($filename));

        return $filename !== '' ? $filename : 'geoflow-image.jpg';
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException($operation.'失败：HTTP '.$response->status());
    }
}
