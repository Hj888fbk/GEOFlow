<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;

final class ContentBriefNormalizer
{
    public function __construct(private readonly ContentStructureProfileCatalog $profiles) {}

    /**
     * @return array{
     *   product_key?:string,page_role?:string,audience?:string,decision_stage?:string,
     *   buyer_questions?:list<string>,procurement_direction?:string,structure_profile?:string,
     *   desired_action?:string,image_keywords?:list<string>
     * }
     */
    public function normalize(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('内容策划必须是有效对象');
            }
            $value = $decoded;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('内容策划必须是有效对象');
        }

        $brief = [];
        $scalarLimits = [
            'product_key' => 160,
            'page_role' => 80,
            'audience' => 600,
            'decision_stage' => 160,
            'procurement_direction' => 1200,
            'structure_profile' => 80,
            'desired_action' => 600,
        ];
        foreach ($scalarLimits as $field => $limit) {
            $normalized = $this->normalizeScalar($value[$field] ?? null, $field, $limit);
            if ($normalized !== '') {
                $brief[$field] = $normalized;
            }
        }

        if (isset($brief['page_role']) && ! in_array($brief['page_role'], $this->profiles->pageRoleKeys(), true)) {
            throw new InvalidArgumentException('页面职责不在支持范围内');
        }
        if (isset($brief['structure_profile']) && ! in_array($brief['structure_profile'], $this->profiles->profileKeys(), true)) {
            throw new InvalidArgumentException('文章结构模板不在支持范围内');
        }

        $buyerQuestions = $this->normalizeList($value['buyer_questions'] ?? [], 'buyer_questions', 20, 300);
        if ($buyerQuestions !== []) {
            $brief['buyer_questions'] = $buyerQuestions;
        }
        $imageKeywords = $this->normalizeList($value['image_keywords'] ?? [], 'image_keywords', 20, 120);
        if ($imageKeywords !== []) {
            $brief['image_keywords'] = $imageKeywords;
        }

        if (! isset($brief['structure_profile']) && isset($brief['page_role'])) {
            $brief['structure_profile'] = $this->profiles->suggestKey($brief['page_role']);
        }

        return $brief;
    }

    private function normalizeScalar(mixed $value, string $field, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException($field.' 必须是文本');
        }
        $normalized = trim((string) $value);
        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($field.' 内容过长');
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeList(mixed $value, string $field, int $maxItems, int $maxItemLength): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/[\r\n,，;；]+/u', $value) ?: [];
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException($field.' 必须是文本列表');
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                throw new InvalidArgumentException($field.' 包含无效项目');
            }
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (mb_strlen($item, 'UTF-8') > $maxItemLength) {
                throw new InvalidArgumentException($field.' 单项内容过长');
            }
            $items[$item] = true;
            if (count($items) > $maxItems) {
                throw new InvalidArgumentException($field.' 项目过多');
            }
        }

        return array_keys($items);
    }
}
