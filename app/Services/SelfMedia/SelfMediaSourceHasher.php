<?php

namespace App\Services\SelfMedia;

use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Str;

final class SelfMediaSourceHasher
{
    public function hash(Article $article): string
    {
        return hash('sha256', json_encode([
            'title' => $this->normalize((string) $article->title),
            'excerpt' => $this->normalize((string) ($article->excerpt ?? '')),
            'content' => $this->normalize($this->canonicalPublishedContent($article)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 官网回读用的规范化入口：把线上读到的标题/摘要/正文 HTML 规范化成与母稿一致的形式。
     *
     * @return array{title:string,excerpt:string,content:string}
     */
    public function normalizeReadback(string $title, string $excerpt, string $contentHtml): array
    {
        return [
            'title' => $this->normalize($title),
            'excerpt' => $this->normalize($excerpt),
            'content' => $this->normalize($contentHtml),
        ];
    }

    /**
     * @param  array{title:string,excerpt:string,content:string}  $normalized
     */
    public function hashNormalized(array $normalized): string
    {
        return hash('sha256', json_encode([
            'title' => $normalized['title'],
            'excerpt' => $normalized['excerpt'],
            'content' => $normalized['content'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 母稿的发布表示：markdown 正文经分发管线同款渲染（ArticleHtmlPresenter）转成 HTML，
     * 再由 normalize() 去标签归一化。这样官网（WordPress 等外部渠道，收到的是渲染后 HTML）
     * 与 GEOFlow 母稿在纯文本层面一致，回读哈希才具备可比性。
     * 已是 HTML 的正文（手工稿/历史数据）直接按原样规范化。
     */
    private function canonicalPublishedContent(Article $article): string
    {
        $content = (string) $article->content;
        if ($this->looksLikeHtml($content)) {
            return $content;
        }

        $body = ArticleHtmlPresenter::stripLeadingTitleHeading($content, (string) $article->title);

        return ArticleHtmlPresenter::markdownToHtml($body);
    }

    private function looksLikeHtml(string $content): bool
    {
        // markdown 正文里的图片/链接语法不含块级标签；出现块级 HTML 标签即视为 HTML 正文。
        return preg_match('/^\s*<(h[1-6]|p|div|table|ul|ol|blockquote|section|article)\b/im', $content) === 1;
    }

    private function normalize(string $value): string
    {
        // Gutenberg block comments are transport metadata and must not affect
        // the readback fingerprint. Remove them before caption handling.
        $value = preg_replace('/<!--\s*\/?wp:[^>]+-->/iu', '', $value) ?? $value;

        // WordPress/Gutenberg may wrap uploaded images in <figure>, add a
        // generated <figcaption>, or emit a short caption paragraph after the
        // figure. The source markdown has no caption field, so retain only an
        // image-count marker and ignore this transport-only decoration.
        // A real <figcaption> is unambiguously transport decoration; remove
        // the optional short paragraph WordPress emits after that figure.
        $value = preg_replace(
            '/(<figure\b[^>]*>.*?<figcaption\b[^>]*>.*?<\/figcaption>.*?<\/figure>)\s*(?:(?:<p\b[^>]*>\s*<\/p>)\s*)*<p\b[^>]*>\s*[^<。！？；，,]{1,120}\s*<\/p>/isu',
            '$1',
            $value,
        ) ?? $value;
        // Some Gutenberg media blocks expose the attachment caption as a
        // short paragraph followed by an empty paragraph. Requiring that
        // empty spacer avoids deleting legitimate prose immediately after an
        // image (for example the table introduction in article 40).
        $value = preg_replace(
            '/(<\/figure>)\s*<p\b[^>]*>\s*[^<。！？；，,]{1,120}\s*<\/p>\s*<p\b[^>]*>\s*<\/p>/isu',
            '$1',
            $value,
        ) ?? $value;
        $value = preg_replace_callback(
            '/<figure\b[^>]*>.*?<\/figure>/isu',
            static function (array $matches): string {
                $count = max(1, substr_count(strtolower((string) ($matches[0] ?? '')), '<img'));

                return str_repeat(' [[image]] ', $count);
            },
            $value,
        ) ?? $value;
        $value = preg_replace('/<img\b[^>]*>/iu', ' [[image]] ', $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::of($value)->replaceMatches('/\s+/u', ' ')->trim()->toString();
    }
}
