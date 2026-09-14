<?php

namespace App\Support\Site;

use App\Models\Article;
use App\Support\GeoFlow\ImageUrlNormalizer;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * 文章正文 Markdown 渲染与摘要生成（对齐旧版前台展示习惯）。
 */
final class ArticleHtmlPresenter
{
    /**
     * 将 Markdown 转为 HTML（剥离不安全 HTML 输入）。
     */
    public static function markdownToHtml(string $markdown, ?string $imageAltPrefix = null): string
    {
        $markdown = self::normalizeMarkdownImages(
            self::stripOuterMarkdownFence(trim($markdown)),
            $imageAltPrefix,
        );
        if ($markdown === '') {
            return '';
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return self::decorateRenderedHtml($converter->convert($markdown)->getContent(), $imageAltPrefix);
    }

    public static function stripOuterMarkdownFence(string $markdown): string
    {
        if (preg_match('/\A```(?:markdown|md)[ \t]*\n([\s\S]*?)\n```[ \t]*\z/i', trim($markdown), $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $markdown;
    }

    /**
     * 从正文中去掉与标题一致的首行 H1，避免详情页重复大标题。
     */
    public static function stripLeadingTitleHeading(string $content, string $title): string
    {
        $content = (string) $content;
        $title = trim($title);
        if ($title === '') {
            return $content;
        }

        $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

        return (string) preg_replace($pattern, '', $content, 1);
    }

    /**
     * 列表卡片摘要：优先 excerpt，否则从正文抽纯文本片段。
     */
    public static function cardSummary(Article $article, int $limit = 120): string
    {
        $excerpt = trim((string) $article->excerpt);
        if ($excerpt !== '') {
            $excerpt = self::stripLeadingTitleHeading($excerpt, (string) $article->title);
            $excerpt = self::stripLeadingMarkdownHeading($excerpt);
            $excerpt = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $excerpt) ?? $excerpt;
            $plain = self::toPlainLine($excerpt);

            return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
        }

        $body = self::stripLeadingTitleHeading((string) $article->content, (string) $article->title);
        $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $body) ?? $body;
        $plain = self::toPlainLine($body);

        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
    }

    private static function stripLeadingMarkdownHeading(string $content): string
    {
        $withoutHeading = preg_replace(
            '/^\s*#{1,6}[ \t]+[^\r\n]+(?:\r?\n)+/u',
            '',
            $content,
            1
        ) ?? $content;

        return trim($withoutHeading) !== '' ? $withoutHeading : $content;
    }

    private static function toPlainLine(string $text): string
    {
        $text = preg_replace('/[#*_`>\[\]()]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function normalizeMarkdownImages(string $markdown, ?string $imageAltPrefix = null): string
    {
        $imageIndex = 0;

        return preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u',
            static function (array $matches) use (&$imageIndex, $imageAltPrefix): string {
                $imageIndex++;
                $alt = ImageUrlNormalizer::readableAlt((string) ($matches[1] ?? ''));
                if (self::isGenericImageAlt($alt)) {
                    $prefix = trim((string) $imageAltPrefix);
                    if ($prefix !== '') {
                        $alt = mb_substr($prefix.' 配图 '.$imageIndex, 0, 120);
                    }
                }
                $url = ImageUrlNormalizer::toPublicUrl((string) ($matches[2] ?? ''));
                $title = trim((string) ($matches[3] ?? ''));

                return '!['.$alt.']('.$url.($title !== '' ? ' '.$title : '').')';
            },
            $markdown
        ) ?? $markdown;
    }

    private static function decorateRenderedHtml(string $html, ?string $imageAltPrefix = null): string
    {
        $html = preg_replace('/<table>/u', '<div class="article-table-wrap"><table class="article-table">', $html) ?? $html;
        $html = preg_replace('/<\/table>/u', '</table></div>', $html) ?? $html;
        $html = preg_replace('/<p>\s*(<img\b[^>]*>)\s*<\/p>/u', '$1', $html) ?? $html;
        if (trim((string) $imageAltPrefix) !== '') {
            $imageIndex = 0;
            $html = preg_replace_callback(
                '/<img\b([^>]*)>/iu',
                static function (array $matches) use (&$imageIndex, $imageAltPrefix): string {
                    $imageIndex++;
                    $attributes = (string) ($matches[1] ?? '');
                    if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/isu', $attributes, $altMatch) === 1) {
                        $alt = html_entity_decode((string) ($altMatch[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if (! self::isGenericImageAlt($alt)) {
                            return $matches[0];
                        }

                        $replacement = mb_substr(trim((string) $imageAltPrefix).' 配图 '.$imageIndex, 0, 120);
                        $attributes = preg_replace(
                            '/\balt\s*=\s*(["\'])(.*?)\1/isu',
                            'alt="'.htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"',
                            $attributes,
                            1
                        ) ?? $attributes;
                    } else {
                        $replacement = mb_substr(trim((string) $imageAltPrefix).' 配图 '.$imageIndex, 0, 120);
                        $attributes = rtrim($attributes).' alt="'.htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
                    }

                    return '<img'.$attributes.'>';
                },
                $html
            ) ?? $html;
        }
        $html = preg_replace('/<img\b(?![^>]*\bloading=)/u', '<img loading="lazy"', $html) ?? $html;
        $html = preg_replace('/<img\b(?![^>]*\bdecoding=)/u', '<img decoding="async"', $html) ?? $html;
        $html = preg_replace_callback(
            '/<img\b([^>]*)>/iu',
            static function (array $matches): string {
                $attributes = rtrim((string) ($matches[1] ?? ''));
                if (str_ends_with($attributes, '/')) {
                    $attributes = rtrim(substr($attributes, 0, -1));
                }

                if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/isu', $attributes, $styleMatch) === 1) {
                    $style = trim((string) ($styleMatch[2] ?? ''));
                    $additions = [];
                    if (preg_match('/(?:^|;)\s*max-width\s*:/iu', $style) !== 1) {
                        $additions[] = 'max-width:100%';
                    }
                    if (preg_match('/(?:^|;)\s*height\s*:/iu', $style) !== 1) {
                        $additions[] = 'height:auto';
                    }
                    if ($additions === []) {
                        return $matches[0];
                    }

                    $style = rtrim($style, " ;\t\r\n");
                    $style = ($style !== '' ? $style.';' : '').implode(';', $additions);
                    $attributes = preg_replace(
                        '/\bstyle\s*=\s*(["\'])(.*?)\1/isu',
                        'style="'.htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"',
                        $attributes,
                        1
                    ) ?? $attributes;

                    return '<img'.$attributes.'>';
                }

                return '<img'.rtrim($attributes).' style="max-width:100%;height:auto">';
            },
            $html,
        ) ?? $html;

        return $html;
    }

    private static function isGenericImageAlt(string $alt): bool
    {
        $alt = trim($alt);

        return $alt === ''
            || preg_match('/^(?:image|img|photo|picture|图片|图像|配图|正文图片)(?:\s*\d+)?$/iu', $alt) === 1;
    }
}
