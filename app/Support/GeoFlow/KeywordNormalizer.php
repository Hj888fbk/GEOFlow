<?php

namespace App\Support\GeoFlow;

/**
 * 统一文章关键词的拆分、清洗和去重规则。
 *
 * 关键词会同时被后台 SEO 字段、WordPress 标签和 Rank Math 使用，
 * 因此不能让每个调用方各自实现一套分隔符和标点规则。
 */
final class KeywordNormalizer
{
    /**
     * @return list<string>
     */
    public static function split(string $raw, string $focusKeyword = ''): array
    {
        $raw = preg_replace('/^```(?:text|markdown|json)?\s*|\s*```$/iu', '', trim($raw)) ?? trim($raw);
        $raw = preg_replace('/^(?:关键词|关键字|keywords?)\s*[：:]\s*/iu', '', $raw) ?? $raw;
        $parts = preg_split('/[,，;；、|\n\r]+/u', $raw) ?: [];
        if (trim($focusKeyword) !== '') {
            array_unshift($parts, $focusKeyword);
        }

        $normalized = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = self::clean((string) $part);
            if ($part === '' || mb_strlen($part, 'UTF-8') > 100) {
                continue;
            }

            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $part;
        }

        return array_slice($normalized, 0, 8);
    }

    public static function normalize(string $raw, string $focusKeyword = '', int $maxCharacters = 500): string
    {
        $keywords = self::split($raw, $focusKeyword);
        while ($keywords !== [] && mb_strlen(implode('，', $keywords), 'UTF-8') > $maxCharacters) {
            array_pop($keywords);
        }

        return implode('，', $keywords);
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/^\s*(?:[-*•]|\d+[.)、])\s*/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{FFFD}]/u', '', $value) ?? $value;
        $value = trim($value);
        $value = preg_replace('/^[\s"\'`\[\]【】()（）]+|[\s"\'`\[\]【】()（）]+$/u', '', $value) ?? $value;

        // 模型偶尔会把不可识别的中文标点输出为问号（例如
        // “恒佳鸭嘴?”）。问号不是产品关键词的一部分，统一移除，
        // 避免后台、WordPress 标签和 Rank Math 出现脏词。
        $value = str_replace(['?', '？'], '', $value);

        return trim((string) (preg_replace('/^[\s.,，;；:：!！|\/]+|[\s.,，;；:：!！|\/]+$/u', '', $value) ?? $value));
    }
}
