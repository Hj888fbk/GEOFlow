<?php

namespace App\Services\SelfMedia;

use App\Models\Article;
use App\Models\ManualPublicationBatch;
use App\Models\SelfMediaMediaSnapshot;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\GeoFlow\ImageUrlNormalizer;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class SelfMediaMediaSnapshotService
{
    private const MAX_IMAGE_BYTES = 10_485_760;

    /** 自媒体正文图目标规格：宽≤1080、高≤1920、体积≤1MB，统一 JPEG。 */
    private const TARGET_MAX_WIDTH = 1080;

    private const TARGET_MAX_HEIGHT = 1920;

    private const TARGET_MAX_BYTES = 1_048_576;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private SafeOutboundHttpClient $safeHttp) {}

    public function purgeBatchFiles(int $batchId): void
    {
        if ($batchId > 0) {
            Storage::disk('local')->deleteDirectory('self-media/'.$batchId);
        }
    }

    /** @return list<array<string,mixed>> */
    public function freeze(ManualPublicationBatch $batch, Article $article): array
    {
        $article->loadMissing('articleImages.image');
        $references = $this->bodyReferences((string) $article->content, (string) $batch->source_url);
        $knownImages = [];
        foreach ($article->articleImages->sortBy('position')->values() as $relation) {
            $image = $relation->image;
            if ($image === null) {
                continue;
            }
            $url = $this->absoluteUrl(ImageUrlNormalizer::toPublicUrl((string) $image->file_path), (string) $batch->source_url);
            $knownImages[$this->canonicalUrl($url)] = [
                'source_type' => 'article_attachment',
                'source_url' => $url,
                'alt' => (string) ($image->original_name ?: $image->file_name ?: $image->filename ?: ''),
                'disk_path' => $this->publicDiskPath((string) $image->file_path),
                'image_id' => (int) $image->id,
                'article_image_id' => (int) $relation->id,
            ];
        }

        $ordered = [];
        foreach ($references as $reference) {
            $canonical = $this->canonicalUrl((string) $reference['source_url']);
            if (! isset($ordered[$canonical])) {
                $ordered[$canonical] = array_merge($knownImages[$canonical] ?? [], $reference);
            }
        }
        foreach ($knownImages as $canonical => $reference) {
            if (! isset($ordered[$canonical])) {
                $ordered[$canonical] = $reference;
            }
        }

        $manifest = [];
        $seenHashes = [];
        foreach (array_values($ordered) as $reference) {
            [$bytes, $sourceType] = $this->readBytes($reference);
            $bytes = $this->normalizeImage($bytes);
            $metadata = $this->inspect($bytes);
            if (isset($seenHashes[$metadata['sha256']])) {
                continue;
            }
            $seenHashes[$metadata['sha256']] = true;
            $position = count($manifest) + 1;
            $mediaKey = 'm_'.substr($metadata['sha256'], 0, 24);
            $extension = match ($metadata['mime_type']) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $storagePath = 'self-media/'.(string) $batch->id.'/'.$mediaKey.'.'.$extension;
            if (! Storage::disk('local')->put($storagePath, $bytes)) {
                throw new DomainException('正文图片无法写入 GEOFlow 受控存储。');
            }

            SelfMediaMediaSnapshot::query()->create([
                'manual_publication_batch_id' => (int) $batch->id,
                'media_key' => $mediaKey,
                'position' => $position,
                'source_type' => $sourceType,
                'source_url' => (string) ($reference['source_url'] ?? ''),
                'alt_text' => trim((string) ($reference['alt'] ?? '')) ?: null,
                'used_as_cover' => $position === 1,
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'sha256' => $metadata['sha256'],
                'mime_type' => $metadata['mime_type'],
                'file_size' => $metadata['file_size'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'status' => 'ready',
            ]);

            $manifest[] = [
                'media_key' => $mediaKey,
                'position' => $position,
                'role' => 'body',
                'is_cover' => $position === 1,
                'required' => true,
                'name' => trim((string) ($reference['alt'] ?? '')) ?: '正文图片 '.$position,
                'source_url' => (string) ($reference['source_url'] ?? ''),
                'source_reference' => (string) ($reference['source_reference'] ?? $reference['source_url'] ?? ''),
                'sha256' => $metadata['sha256'],
                'mime_type' => $metadata['mime_type'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'file_size' => $metadata['file_size'],
                'download_path' => '/api/v1/manual-publications/{work_order}/media/'.$mediaKey,
                'upload_required' => true,
            ];
        }

        return $manifest;
    }

    /** @return list<array{source_type:string,source_url:string,alt:string}> */
    private function bodyReferences(string $content, string $baseUrl): array
    {
        $found = [];
        if (preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', $content, $markdown, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($markdown[0] as $index => $whole) {
                $found[] = [
                    'offset' => (int) $whole[1],
                    'source_type' => 'body_markdown',
                    'source_url' => $this->absoluteUrl((string) $markdown[2][$index][0], $baseUrl),
                    'source_reference' => (string) $markdown[2][$index][0],
                    'alt' => (string) $markdown[1][$index][0],
                ];
            }
        }
        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/isu', $content, $html, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($html[0] as $index => $whole) {
                $tag = (string) $whole[0];
                preg_match('/\balt\s*=\s*(["\'])(.*?)\1/isu', $tag, $alt);
                $found[] = [
                    'offset' => (int) $whole[1],
                    'source_type' => 'body_html',
                    'source_url' => $this->absoluteUrl(html_entity_decode((string) $html[2][$index][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl),
                    'source_reference' => html_entity_decode((string) $html[2][$index][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'alt' => html_entity_decode((string) ($alt[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }
        usort($found, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return array_values(array_map(static function (array $item): array {
            unset($item['offset']);

            return $item;
        }, $found));
    }

    /** @param array<string,mixed> $reference @return array{string,string} */
    private function readBytes(array $reference): array
    {
        $diskPath = trim((string) ($reference['disk_path'] ?? ''));
        if ($diskPath !== '' && Storage::disk('public')->exists($diskPath)) {
            $bytes = Storage::disk('public')->get($diskPath);

            return [$bytes, (string) ($reference['source_type'] ?? 'article_attachment')];
        }
        $url = trim((string) ($reference['source_url'] ?? ''));
        if ($url === '') {
            throw new DomainException('正文图片地址为空，无法冻结媒体快照。');
        }
        $response = $this->safeHttp->get(
            Http::timeout(15)->connectTimeout(5)->withHeaders(['Accept' => 'image/*']),
            $url,
            self::MAX_IMAGE_BYTES,
            2,
        );
        if (! $response->successful()) {
            throw new DomainException('正文图片下载失败（HTTP '.$response->status().'）。');
        }

        return [$response->body(), (string) ($reference['source_type'] ?? 'external')];
    }

    /** @return array{sha256:string,mime_type:string,file_size:int,width:int,height:int} */
    private function inspect(string $bytes): array
    {
        $size = strlen($bytes);
        if ($size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new DomainException('正文图片文件大小不符合限制。');
        }
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! isset($info['mime']) || ! in_array(strtolower((string) $info['mime']), self::ALLOWED_MIME, true)) {
            throw new DomainException('正文图片内容或 MIME 类型无效。');
        }

        return [
            'sha256' => hash('sha256', $bytes),
            'mime_type' => strtolower((string) $info['mime']),
            'file_size' => $size,
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
        ];
    }

    /**
     * 把正文图规范化为自媒体友好规格：宽≤1080、高≤1920、体积≤1MB、统一 JPEG。
     * GIF 动图与 GD 不可用、解码失败时原样返回，保证链路不中断。
     */
    private function normalizeImage(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return $bytes;
        }
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($mime === 'image/gif') {
            return $bytes;
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($mime === 'image/jpeg'
            && $width <= self::TARGET_MAX_WIDTH
            && $height <= self::TARGET_MAX_HEIGHT
            && strlen($bytes) <= self::TARGET_MAX_BYTES) {
            return $bytes;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false || $width < 1 || $height < 1) {
            return $bytes;
        }
        $scale = min(1.0, self::TARGET_MAX_WIDTH / $width, self::TARGET_MAX_HEIGHT / $height);
        $canvas = $source;
        if ($scale < 1.0) {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            if ($canvas === false) {
                imagedestroy($source);

                return $bytes;
            }
            // PNG/WebP 透明底统一垫白，避免转 JPEG 后发黑。
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
        }

        $normalized = '';
        foreach ([80, 70, 60, 50, 40] as $quality) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $normalized = (string) ob_get_clean();
            if (strlen($normalized) <= self::TARGET_MAX_BYTES) {
                break;
            }
        }
        imagedestroy($canvas);

        return $normalized !== '' ? $normalized : $bytes;
    }

    private function publicDiskPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        foreach (['storage/app/public/', 'public/storage/', 'storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return (string) parse_url($baseUrl, PHP_URL_SCHEME).':'.$url;
        }
        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);

        return $origin.rtrim(str_replace('\\', '/', dirname($basePath)), '/').'/'.$url;
    }

    private function canonicalUrl(string $url): string
    {
        return Str::lower(trim($url));
    }
}
