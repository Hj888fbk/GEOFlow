<?php

namespace App\Services\SelfMedia;

use App\Models\ManualPublicationBatch;
use DomainException;

final class SelfMediaFactConstraintGuard
{
    private const NUMBER_PATTERN = '/(?<![\pL\pN])\d+(?:\.\d+)?\s*(?:MPa|kPa|Pa|mm|cm|m|%|℃|小时|天|年|月|日)?/iu';

    /** @param list<string> $sources
     * @return list<string>
     */
    public function extractAllowedNumbers(array $sources): array
    {
        $numbers = [];
        foreach ($sources as $source) {
            preg_match_all(self::NUMBER_PATTERN, $this->withoutListOrdinals($source), $matches);
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
            throw new DomainException('平台改写增加了事实约束包之外的数字：'.implode('、', $unexpected));
        }
    }

    private function withoutListOrdinals(string $value): string
    {
        return preg_replace('/^\s*\d+[.、)]\s+/mu', '', $value) ?? $value;
    }

    private function canonicalNumber(string $number): string
    {
        return strtolower((string) preg_replace('/\s+/u', '', trim($number)));
    }
}
