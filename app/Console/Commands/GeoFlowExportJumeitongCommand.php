<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 聚媒通导出命令（替代此前有 10 个 bug 的独立 Python 脚本）。
 *
 * 修复对照：
 *  - PY-1 图片路径：走 Laravel Storage（尊重 FILESYSTEM_DISK=local → storage/app/private/），
 *    并按 SelfMediaMediaSnapshotService::publicDiskPath 的前缀剥离规则做多候选兜底。
 *  - PY-2 正文图片：从正文 HTML 提取 <img> 内联保留，不再先剥后插。
 *  - PY-3 编码：复用 Laravel/PHP PDO 链路，无 psycopg2 GBK 编码问题。
 *  - PY-4 时间：统一 format('Y-m-d H:i')。
 *  - PY-5 null：content/title 全程空值兜底。
 *  - PY-6 连接：Laravel 单例连接，无频繁开关。
 *  - PY-7 图片数：不再硬限 3 张（--max-images 可控）。
 *  - PY-8 文件名：id 前缀 + 去特殊字符，天然不冲突。
 *  - PY-10 HTML 注释/脚本：清洗后再输出。
 *
 * 输出为 HTML-based .doc（UTF-8，图片 base64 内嵌）——零 composer 依赖，
 * Word / WPS / 聚媒通均可直接打开与导入。
 */
final class GeoFlowExportJumeitongCommand extends Command
{
    protected $signature = 'geoflow:export-articles-doc
        {--ids= : 逗号分隔的文章 ID，如 1,3,5}
        {--limit=10 : 未指定 ids 时导出最近 N 篇}
        {--out= : 输出目录（默认 storage/app/tmp/jumeitong-export）}
        {--max-images=9 : 单篇最多内嵌图片数}';

    protected $description = 'Export GEOFlow articles to self-contained .doc files for 聚媒通 batch publishing';

    public function handle(): int
    {
        $query = Article::query()
            ->with(['articleImages.image'])
            ->whereNotNull('content')
            ->orderByRaw('COALESCE(published_at, created_at) DESC');

        $idsOption = trim((string) $this->option('ids'));
        if ($idsOption !== '') {
            $ids = array_filter(array_map('intval', explode(',', $idsOption)));
            if ($ids === []) {
                $this->error('--ids 参数无效，示例：--ids=1,3,5');

                return self::FAILURE;
            }
            $articles = $query->whereKey($ids)->get();
        } else {
            $articles = $query->limit(max(1, (int) $this->option('limit')))->get();
        }

        if ($articles->isEmpty()) {
            $this->warn('没有符合条件的文章。');

            return self::SUCCESS;
        }

        $outDir = trim((string) $this->option('out')) ?: storage_path('app/tmp/jumeitong-export');
        if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            $this->error("无法创建输出目录：{$outDir}");

            return self::FAILURE;
        }

        $maxImages = max(0, (int) $this->option('max-images'));
        $this->info(sprintf('导出 %d 篇文章 → %s', $articles->count(), $outDir));

        $ok = 0;
        foreach ($articles as $article) {
            try {
                $path = $this->exportOne($article, $outDir, $maxImages);
                $ok++;
                $this->line(sprintf('  ✔ [%d] %s → %s', $article->getKey(), mb_substr((string) $article->title, 0, 30), basename($path)));
            } catch (Throwable $e) {
                $this->error(sprintf('  ✘ [%d] %s 导出失败：%s', $article->getKey(), (string) $article->title, $e->getMessage()));
            }
        }

        $this->info(sprintf('完成：%d/%d 篇。下一步：全选 .doc 文件拖入聚媒通客户端。', $ok, $articles->count()));

        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function exportOne(Article $article, string $outDir, int $maxImages): string
    {
        $title = trim((string) $article->title) ?: ('untitled-'.$article->getKey());
        $contentHtml = (string) ($article->content ?? ''); // PY-5
        $images = $article->articleImages
            ->sortBy(fn ($ai) => (int) ($ai->position ?? 0))
            ->pluck('image')
            ->filter()
            ->take($maxImages) // PY-7
            ->values();

        $used = 0;
        $blocks = [];

        // 封面/配图区（数据库关联图片，base64 内嵌）
        foreach ($images as $image) {
            $dataUri = $this->imageDataUri((string) ($image->file_path ?? ''));
            if ($dataUri === null) {
                $this->line(sprintf('    · 跳过缺失图片：article #%d image #%s', $article->getKey(), (string) $image->getKey()));

                continue;
            }
            $blocks[] = '<p><img src="'.$dataUri.'" style="max-width:600px;" /></p>';
            $used++;
        }

        // 正文（保留正文内联图片 PY-2：解析每个 <img>，本地路径转 data URI，远程/绝对 URL 原样保留）
        $bodyHtml = $this->sanitize($contentHtml);
        $bodyHtml = preg_replace_callback(
            '/<img\b[^>]*src\s*=\s*("|\')([^"\']+)\1[^>]*>/i',
            function (array $m) use (&$used, $maxImages): string {
                $src = trim($m[2]);
                if (preg_match('#^(https?:)?//#i', $src) === 1 || str_starts_with($src, 'data:')) {
                    return $m[0]; // 远程图或已内嵌，原样保留
                }
                if ($used >= $maxImages) {
                    return ''; // 超出配额的本地图丢弃，避免文档爆炸
                }
                $dataUri = $this->imageDataUri($src);
                if ($dataUri === null) {
                    return '';
                }
                $used++;

                return (string) preg_replace('/src\s*=\s*("|\')[^"\']+\1/i', 'src="'.$dataUri.'"', $m[0]);
            },
            $bodyHtml,
        ) ?? $bodyHtml;
        if (trim(strip_tags($bodyHtml)) !== '') {
            array_unshift($blocks, $bodyHtml);
        }

        // PY-4/PY-5：时间统一格式化
        $meta = [];
        $keywords = trim((string) ($article->keywords ?? ''));
        $meta[] = '关键词：'.($keywords !== '' ? $keywords : '无');
        if ($article->published_at) {
            $meta[] = '发布时间：'.$article->published_at->format('Y-m-d H:i');
        } else {
            $meta[] = '创建时间：'.optional($article->created_at)->format('Y-m-d H:i');
        }

        $html = $this->wrapDocument($title, $meta, $blocks);

        // PY-8：id 前缀保证唯一，去除 Windows 非法字符
        $safeTitle = trim(preg_replace('/[\\\\\/\:\*\?\"\<\>\|\r\n\t]+/u', '_', $title) ?? 'untitled');
        $safeTitle = mb_substr($safeTitle, 0, 60);
        $filename = $article->getKey().'_'.$safeTitle.'.doc';
        file_put_contents($outDir.DIRECTORY_SEPARATOR.$filename, $html);

        return $outDir.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * PY-1：把 images.file_path / 正文 <img src> 解析为可读文件。
     * 候选顺序：绝对/HTTP → data URI；storage 前缀剥离（对齐 publicDiskPath）→
     * 依次尝试 FILESYSTEM_DISK、public disk、storage/app/public、storage/app/private、项目根。
     */
    private function imageDataUri(string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        if (str_starts_with($reference, 'data:')) {
            return $reference;
        }
        if (preg_match('#^https?://#i', $reference) === 1) {
            $bytes = @file_get_contents($reference, false, stream_context_create(['http' => ['timeout' => 10]]));

            return $bytes !== false ? $this->toDataUri($bytes) : null;
        }

        $normalized = ltrim(str_replace('\\', '/', $reference), '/');
        foreach (['storage/app/public/', 'public/storage/', 'storage/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        $candidates = [];
        foreach ([(string) config('filesystems.default'), 'public', 'local'] as $disk) {
            $candidates[] = fn () => Storage::disk($disk)->exists($normalized)
                ? Storage::disk($disk)->get($normalized)
                : null;
        }
        $base = base_path();
        $candidates[] = fn () => is_file($p = $base.'/storage/app/public/'.$normalized) ? (string) file_get_contents($p) : null;
        $candidates[] = fn () => is_file($p = $base.'/storage/app/private/'.$normalized) ? (string) file_get_contents($p) : null;
        $candidates[] = fn () => is_file($p = $base.'/public/'.$normalized) ? (string) file_get_contents($p) : null;
        $candidates[] = fn () => is_file($p = $base.'/'.$normalized) ? (string) file_get_contents($p) : null;

        foreach ($candidates as $try) {
            $bytes = $try();
            if (is_string($bytes) && $bytes !== '') {
                return $this->toDataUri($bytes);
            }
        }

        return null;
    }

    private function toDataUri(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? strtolower((string) $info['mime']) : '';
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** PY-10：去 script/style/HTML 注释与事件属性，保留结构标签 */
    private function sanitize(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        return trim($html);
    }

    /** @param list<string> $meta @param list<string> $blocks */
    private function wrapDocument(string $title, array $meta, array $blocks): string
    {
        $metaHtml = implode('<br />', array_map('htmlspecialchars', $meta));
        $body = implode("\n", $blocks);

        return '<!DOCTYPE html><html><head><meta charset="utf-8" /><title>'
            .htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            .'</title></head><body>'
            .'<h1 style="text-align:center;">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'
            .'<p style="color:#666;font-size:9pt;font-style:italic;">'.$metaHtml.'</p><hr />'
            .$body
            .'</body></html>';
    }
}
