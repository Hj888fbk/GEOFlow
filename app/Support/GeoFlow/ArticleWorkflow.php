<?php

namespace App\Support\GeoFlow;

use App\Models\Article;
use Illuminate\Support\Str;

final class ArticleWorkflow
{
    public const PUBLISHABLE_REVIEW_STATUSES = ['approved', 'auto_approved'];

    public static function isPublishableReviewStatus(mixed $status): bool
    {
        return in_array((string) $status, self::PUBLISHABLE_REVIEW_STATUSES, true);
    }

    public static function normalizeState(string $status, string $reviewStatus, ?string $publishedAt = null): array
    {
        $allowedStatuses = ['draft', 'published', 'private'];
        $allowedReviewStatuses = ['pending', 'approved', 'rejected', 'auto_approved'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        if (! in_array($reviewStatus, $allowedReviewStatuses, true)) {
            $reviewStatus = 'pending';
        }

        if (in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $status = 'draft';
        }

        if ($status === 'published' && in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $reviewStatus = 'approved';
        }

        if ($status !== 'published' && $reviewStatus === 'auto_approved') {
            $status = 'published';
        }

        if ($status === 'published' && $reviewStatus === 'pending') {
            $reviewStatus = 'approved';
        }

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: date('Y-m-d H:i:s');
        } else {
            $publishedAt = null;
        }

        return [
            'status' => $status,
            'review_status' => $reviewStatus,
            'published_at' => $publishedAt,
        ];
    }

    public static function generateUniqueSlug(string $title, ?int $excludeArticleId = null): string
    {
        // 文章 URL 需要可读且稳定，尤其是中文标题不能再退化成无意义的
        // 8 位随机字符串。Laravel 的 transliterate 使用 ICU 生成拼音，
        // 对型号中的字母/数字也会保留；只有完全没有可用字符时才回退
        // 到随机 slug。
        $slug = Str::slug(Str::transliterate(trim($title), '', false));
        $slug = mb_substr($slug, 0, 100, 'UTF-8');
        if ($slug === '') {
            $slug = self::randomSlug(8);
        }

        while (true) {
            try {
                $q = Article::withTrashed()->where('slug', $slug);
                if ($excludeArticleId !== null) {
                    $q->where('id', '!=', $excludeArticleId);
                }

                if (! $q->exists()) {
                    return $slug;
                }

                $suffix = self::randomSlug(4);
                $base = mb_substr($slug, 0, 94, 'UTF-8');
                $slug = $base.'-'.$suffix;
            } catch (\Throwable) {
                return self::randomSlug(8);
            }
        }
    }

    private static function randomSlug(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $slug;
    }
}
