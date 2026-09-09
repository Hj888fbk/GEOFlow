<?php

namespace App\Services\SelfMedia;

use App\Models\ManualPublicationAccount;
use App\Support\Site\ArticleHtmlPresenter;
use DomainException;

final class PortableArticleDocumentService
{
    public const SCHEMA_VERSION = 'portable-article-document/v1';

    private const MEDIA_TOKEN_PATTERN = '/\{\{media:([a-z0-9][a-z0-9_-]{7,79})\}\}/i';

    /**
     * @param list<array<string,mixed>> $mediaManifest
     * @return array<string,mixed>
     */
    public function build(string $title, string $markdown, array $mediaManifest, string $platform): array
    {
        $normalized = $this->normalizeMarkdown($markdown, $title);
        $normalized = $this->convertLegacyImagePlaceholders($normalized, $mediaManifest);
        if (preg_match('/【图片\d+】/u', $normalized) === 1) {
            throw new DomainException('正文包含无法解析的旧图片占位符，未创建平台工作单。');
        }
        $this->assertMediaReferences($normalized, $mediaManifest);

        $markdownForPlatform = $this->renderMarkdownForPlatform($normalized, $platform);
        $html = $this->renderHtml($markdownForPlatform);
        $plain = $this->plainText($html);
        if ($plain === '') {
            throw new DomainException('平台改写正文为空，未创建工作单。');
        }

        $outline = $this->headingOutlineFromHtml($html);
        $imageOrder = $this->mediaOrder($markdownForPlatform);
        $fingerprint = [
            'text_sha256' => hash('sha256', $this->canonicalRenderedText($plain)),
            'heading_outline' => $outline,
            'list_item_count' => $this->listItemCount($markdownForPlatform),
            'table_count' => $this->tableCount($markdownForPlatform),
            'image_order' => $imageOrder,
        ];
        $fingerprint['sha256'] = hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'title' => trim($title),
            'markdown' => $markdownForPlatform,
            'html' => $html,
            'plain_text' => $plain,
            'nodes' => $this->nodes($markdownForPlatform),
            'render_fingerprint' => $fingerprint,
        ];
    }

    public function normalizeMarkdown(string $markdown, string $title = ''): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if (preg_match('/\A```(?:markdown|md)[ \t]*\n([\s\S]*?)\n```[ \t]*\z/i', $markdown, $matches) === 1) {
            $markdown = trim((string) $matches[1]);
        }
        if ($markdown === '') {
            throw new DomainException('平台改写返回了空正文。');
        }

        $lines = explode("\n", $markdown);
        $insideFence = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $insideFence = ! $insideFence;
                continue;
            }
            if ($insideFence || preg_match('/^#\s+(.+?)\s*$/u', $line, $heading) !== 1) {
                continue;
            }
            $headingText = trim((string) $heading[1]);
            $headingText = trim((string) preg_replace('/\s+#+\s*$/u', '', $headingText));
            if ($title !== '' && $this->canonicalText($headingText) === $this->canonicalText($title)) {
                unset($lines[$index]);
            } else {
                $lines[$index] = '## '.$headingText;
            }
        }
        $markdown = trim(implode("\n", $lines));
        if ($insideFence) {
            throw new DomainException('正文代码块没有正确闭合，未创建工作单。');
        }
        if (preg_match('/\A\s*(?:```|~~~)[\s\S]*(?:```|~~~)\s*\z/', $markdown) === 1
            && preg_match('/\A\s*(```|~~~)[^\n]*\n[\s\S]*\n\1\s*\z/', $markdown) === 1) {
            throw new DomainException('整篇正文被识别为代码块，未创建工作单。');
        }
        $this->assertHeadingHierarchy($markdown);

        return $markdown;
    }

    /** @param list<array<string,mixed>> $manifest */
    public function injectSourceMediaTokens(string $markdown, array $manifest): string
    {
        $byUrl = [];
        foreach ($manifest as $item) {
            $url = trim((string) ($item['source_url'] ?? ''));
            $key = trim((string) ($item['media_key'] ?? ''));
            if ($url !== '' && $key !== '') {
                $byUrl[$this->canonicalUrl($url)] = $key;
            }
            $reference = trim((string) ($item['source_reference'] ?? ''));
            if ($reference !== '' && $key !== '') {
                $byUrl[$this->canonicalUrl($reference)] = $key;
            }
        }

        $markdown = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', function (array $matches) use ($byUrl): string {
            $key = $byUrl[$this->canonicalUrl((string) $matches[2])] ?? null;

            return $key ? '{{media:'.$key.'}}' : (string) $matches[0];
        }, $markdown) ?? $markdown;
        $markdown = preg_replace_callback('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/isu', function (array $matches) use ($byUrl): string {
            $key = $byUrl[$this->canonicalUrl(html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))] ?? null;

            return $key ? '{{media:'.$key.'}}' : '';
        }, $markdown) ?? $markdown;

        return $markdown;
    }

    /** @param list<array<string,mixed>> $manifest */
    private function convertLegacyImagePlaceholders(string $markdown, array $manifest): string
    {
        $body = array_values(array_filter($manifest, static fn (mixed $item): bool => is_array($item) && ($item['role'] ?? 'body') === 'body'));

        return preg_replace_callback('/【图片(\d+)】/u', static function (array $matches) use ($body): string {
            $index = ((int) $matches[1]) - 1;
            $key = trim((string) ($body[$index]['media_key'] ?? ''));

            return $key === '' ? (string) $matches[0] : '{{media:'.$key.'}}';
        }, $markdown) ?? $markdown;
    }

    /** @param list<array<string,mixed>> $manifest */
    private function assertMediaReferences(string $markdown, array $manifest): void
    {
        $expected = array_values(array_map(
            static fn (array $item): string => (string) ($item['media_key'] ?? ''),
            array_filter($manifest, static fn (mixed $item): bool => is_array($item) && ($item['role'] ?? 'body') === 'body' && (bool) ($item['required'] ?? true)),
        ));
        $observed = $this->mediaOrder($markdown);
        if ($expected === [] && $observed === []) {
            return;
        }
        if ($expected !== $observed || count($observed) !== count(array_unique($observed))) {
            throw new DomainException('正文图片引用缺失、重复或顺序变化，未创建平台工作单。');
        }
    }

    private function renderMarkdownForPlatform(string $markdown, string $platform): string
    {
        if ($platform === ManualPublicationAccount::PLATFORM_CSDN) {
            return $markdown;
        }

        // 百家号/头条等编辑器对 HTML 表格支持差，平台稿一律把表格转成分条列述。
        $markdown = $this->tablesToLists($markdown);

        return preg_replace_callback('/(^|\n)(```|~~~)[^\n]*\n([\s\S]*?)\n\2(?=\n|$)/', static function (array $matches): string {
            $code = trim((string) $matches[3]);
            $quoted = implode("\n", array_map(static fn (string $line): string => '> '.$line, explode("\n", $code)));

            return (string) $matches[1].$quoted;
        }, $markdown) ?? $markdown;
    }

    /**
     * Markdown 管道表格转分条列述：每行数据变一条 "- **列名**：值；…" 列表项。
     * 单列"表格"（拆不出 2 列以上）不转换，避免误伤含竖线的普通段落。
     */
    private function tablesToLists(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $out = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];
            if ($this->isTableRow($line)
                && $i + 1 < $count
                && preg_match('/^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/u', $lines[$i + 1]) === 1) {
                $headers = $this->splitTableRow($line);
                if (count($headers) >= 2) {
                    $i += 2;
                    while ($i < $count && $this->isTableRow($lines[$i])) {
                        $cells = $this->splitTableRow($lines[$i]);
                        $parts = [];
                        foreach ($cells as $index => $cell) {
                            $header = trim((string) ($headers[$index] ?? ''));
                            $parts[] = ($header !== '' ? '**'.$header.'**：' : '').$cell;
                        }
                        $out[] = '- '.implode('；', $parts);
                        $i++;
                    }
                    continue;
                }
            }
            $out[] = $line;
            $i++;
        }

        return implode("\n", $out);
    }

    private function isTableRow(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== '' && str_contains($trimmed, '|');
    }

    /** @return list<string> */
    private function splitTableRow(string $line): array
    {
        $trimmed = trim(trim($line), '|');

        return array_values(array_map('trim', explode('|', $trimmed)));
    }

    private function assertHeadingHierarchy(string $markdown): void
    {
        $outline = $this->headingOutline($markdown);
        $previous = 1;
        foreach ($outline as $heading) {
            $level = (int) $heading['level'];
            if ($level > $previous + 1) {
                throw new DomainException('正文标题层级跳跃，未创建工作单。');
            }
            $previous = $level;
        }
    }

    private function renderHtml(string $markdown): string
    {
        $renderable = preg_replace_callback(self::MEDIA_TOKEN_PATTERN, static fn (array $matches): string => '![]('.'https://geoflow.invalid/media/'.strtolower((string) $matches[1]).')', $markdown) ?? $markdown;
        $html = ArticleHtmlPresenter::markdownToHtml($renderable);

        return preg_replace_callback('#<img\b([^>]*?)\bsrc="https://geoflow\.invalid/media/([a-z0-9_-]+)"([^>]*)>#i', static function (array $matches): string {
            $attrs = trim((string) $matches[1].' '.(string) $matches[3]);

            return '<img data-geoflow-media-key="'.strtolower((string) $matches[2]).'" '.$attrs.'>';
        }, $html) ?? $html;
    }

    private function plainText(string $html): string
    {
        $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?\s*>|<\/(?:h[1-6]|p|li|tr|blockquote|table|ul|ol)>/i', "\n", $html) ?? $html;
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/u', "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    /** @return list<array{level:int,text:string}> */
    private function headingOutline(string $markdown): array
    {
        preg_match_all('/^(#{1,4})[ \t]+(.+?)\s*#*\s*$/mu', $markdown, $matches, PREG_SET_ORDER);

        return array_values(array_map(static fn (array $match): array => [
            'level' => strlen((string) $match[1]),
            'text' => trim((string) $match[2]),
        ], $matches));
    }

    /** @return list<array{level:int,text:string}> */
    private function headingOutlineFromHtml(string $html): array
    {
        preg_match_all('/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/i', $html, $matches, PREG_SET_ORDER);

        return array_values(array_map(static fn (array $match): array => [
            'level' => (int) $match[1],
            'text' => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        ], $matches));
    }

    /** @return list<string> */
    private function mediaOrder(string $markdown): array
    {
        preg_match_all(self::MEDIA_TOKEN_PATTERN, $markdown, $matches);

        return array_values(array_map('strtolower', (array) ($matches[1] ?? [])));
    }

    private function listItemCount(string $markdown): int
    {
        return preg_match_all('/^[ \t]*(?:[-+*]|\d+[.)])[ \t]+\S/mu', $markdown);
    }

    private function tableCount(string $markdown): int
    {
        return preg_match_all('/^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/mu', $markdown);
    }

    /** @return list<array<string,mixed>> */
    private function nodes(string $markdown): array
    {
        $nodes = [];
        $insideCode = false;
        $code = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                if ($insideCode) {
                    $nodes[] = ['type' => 'code_block', 'text' => implode("\n", $code)];
                    $code = [];
                }
                $insideCode = ! $insideCode;
                continue;
            }
            if ($insideCode) {
                $code[] = $line;
                continue;
            }
            if (preg_match(self::MEDIA_TOKEN_PATTERN, trim($line), $media) === 1) {
                $nodes[] = ['type' => 'image', 'media_key' => strtolower((string) $media[1])];
            } elseif (preg_match('/^(#{1,4})\s+(.+)$/u', $line, $heading) === 1) {
                $nodes[] = ['type' => 'heading', 'level' => strlen((string) $heading[1]), 'text' => trim((string) $heading[2])];
            } elseif (preg_match('/^\s*>\s?(.*)$/u', $line, $quote) === 1) {
                $nodes[] = ['type' => 'quote', 'text' => (string) $quote[1]];
            } elseif (preg_match('/^\s*(?:[-+*]|\d+[.)])\s+(.+)$/u', $line, $list) === 1) {
                $nodes[] = ['type' => 'list_item', 'text' => (string) $list[1]];
            } elseif (trim($line) !== '') {
                $nodes[] = ['type' => str_contains($line, '|') ? 'table_row' : 'paragraph', 'text' => trim($line)];
            }
        }

        return $nodes;
    }

    private function canonicalText(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }

    private function canonicalRenderedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function canonicalUrl(string $url): string
    {
        return strtolower(trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
