<?php

namespace App\Services\SelfMedia;

use App\Models\ManualPublicationBatch;
use DomainException;

final class SelfMediaFactConstraintGuard
{
    // 前导排除只看 ASCII 字母/数字（保护 DN100、KXT200 等型号），
    // 中文是 \pL，若排除中文字符会导致"成立于2014年"这类数字漏检。
    private const NUMBER_PATTERN = '/(?<![A-Za-z0-9])\d+(?:\.\d+)?\s*(?:MPa|kPa|Pa|mm|cm|m|%|℃|小时|天|年|月|日)?/u';

    /**
     * 正文图片占位符（如【图片1】）是版式标记而非事实数字，
     * 扫描前整体剔除 token，不单独放行其中的数字，其他数字校验保持不变。
     * 同时剔除 body_html 里的 <img data-geoflow-media-key="...">：媒体 key（如 m_22ed34aa…）
     * 里的十六进制片段会被数字扫描误抓，图片本身不是事实数字。
     */
    private const IMAGE_PLACEHOLDER_PATTERN = '/【图片\d+】|\{\{media:[a-z0-9_-]+\}\}|<img\b[^>]*>/iu';

    /** @param list<string> $sources
     * @return list<string>
     */
    public function extractAllowedNumbers(array $sources): array
    {
        $numbers = [];
        foreach ($sources as $source) {
            preg_match_all(self::NUMBER_PATTERN, $this->withoutImagePlaceholders($this->withoutListOrdinals($source)), $matches);
            foreach ($matches[0] ?? [] as $match) {
                $numbers[$this->canonicalNumber((string) $match)] = true;
            }
        }

        return array_keys($numbers);
    }

    /** @param array<string,mixed> $variant */
    public function assertVariant(ManualPublicationBatch $batch, array $variant): void
    {
        $allowed = array_fill_keys(array_map(
            fn ($number): string => $this->canonicalNumber((string) $number),
            (array) data_get($batch->fact_constraints, 'allowed_numbers', []),
        ), true);
        $variantText = implode("\n", [
            (string) ($variant['title'] ?? ''),
            (string) ($variant['summary'] ?? ''),
            (string) ($variant['body_plain'] ?? ''),
            (string) ($variant['body_markdown'] ?? ''),
            (string) ($variant['body_html'] ?? ''),
            implode(' ', array_map('strval', (array) ($variant['tags'] ?? []))),
        ]);
        $unexpected = array_values(array_filter(
            $this->extractAllowedNumbers([$variantText]),
            static fn (string $number): bool => ! isset($allowed[$number]),
        ));
        if ($unexpected !== []) {
            // 附带上数字所在上下文，便于区分「版式序号误伤」与「真实事实性数字」。
            $contexts = [];
            foreach ($unexpected as $number) {
                $pattern = '/(?<![\pL\pN])'.preg_quote($number, '/').'(?![\pN])/u';
                if (preg_match($pattern, $variantText, $m, PREG_OFFSET_CAPTURE)) {
                    $charPos = mb_strlen(substr($variantText, 0, $m[0][1]));
                    $contexts[] = $number.'@「'.str_replace("\n", '\n', mb_substr($variantText, max(0, $charPos - 40), 85)).'」';
                } else {
                    $contexts[] = $number;
                }
            }
            throw new DomainException('平台改写增加了事实约束包之外的数字：'.implode('；', $contexts));
        }
    }

    private function withoutListOrdinals(string $value): string
    {
        // 行首序号（2. / 3、/ 4)）与 HTML 段落内序号（<p>2. …）都是版式标记。
        $value = preg_replace('/(^|>)\s*\d+[.、)]\s+/mu', '$1', $value) ?? $value;

        // 「TOP 2」「TOP2：」等榜单小标题同样是版式标记，不是事实数字。
        $value = preg_replace('/(?<![\pL\pN])TOP\s*\d+\s*[:：|｜]?\s*/iu', '', $value) ?? $value;

        // 「第3名/第2位/第5步」等阿拉伯数字序号同样是版式标记。
        return preg_replace('/第\s*\d+\s*[名位条步款]/u', '', $value) ?? $value;
    }

    private function withoutImagePlaceholders(string $value): string
    {
        return preg_replace(self::IMAGE_PLACEHOLDER_PATTERN, '', $value) ?? $value;
    }

    private function canonicalNumber(string $number): string
    {
        // 单位是写法不是数值：2026年≡2026、1.6MPa≡1.6，归一成同一数值，
        // 避免模型把"2026年"写成"2026"就被误判为新增事实数字。
        $number = trim((string) $number);
        $number = preg_replace('/(?:MPa|kPa|Pa|mm|cm|m|%|℃|小时|天|年|月|日)\s*$/u', '', $number) ?? $number;

        return strtolower((string) preg_replace('/\s+/u', '', $number));
    }
}
