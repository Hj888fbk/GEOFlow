<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WordPressMediaSyncService
{
    /** @var list<array{id:int,source_url:string,filename:string}> */
    private array $lastUploadedMedia = [];

    public function __construct(private readonly WordPressRestRequestFactory $requestFactory) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function rewriteContentImages(DistributionChannel $channel, array $payload, string $contentHtml): string
    {
        $this->lastUploadedMedia = [];

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
                continue;
            }

            $binary = base64_decode($contentBase64, true);
            if (! is_string($binary) || $binary === '') {
                continue;
            }

            $filename = $this->safeFilename((string) ($image['filename'] ?? ''));
            $uploaded = $this->uploadImage($channel, $binary, $filename, (string) ($image['mime_type'] ?? 'application/octet-stream'));
            if ($uploaded !== null) {
                $contentHtml = str_replace($sourceUrl, $uploaded['source_url'], $contentHtml);
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
     * @return array{id:int,source_url:string}|null
     */
    private function uploadImage(DistributionChannel $channel, string $binary, string $filename, string $mimeType): ?array
    {
        $mimeType = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $response = $this->requestFactory->request($channel)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ])
            ->withBody($binary, $mimeType)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/media');
        $this->throwIfFailed($response, 'WordPress 媒体上传');
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $sourceUrl = (string) ($json['source_url'] ?? '');
        if ($sourceUrl === '') {
            return null;
        }

        return [
            'id' => (int) ($json['id'] ?? 0),
            'source_url' => $sourceUrl,
        ];
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
