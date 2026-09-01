<?php

namespace App\Services\Content\Adapters;

final class Alibaba1688PlatformAdapter extends AbstractBrowserAssistedPlatformAdapter
{
    protected function key(): string
    {
        return '1688';
    }

    protected function contentTypes(): array
    {
        return ['product'];
    }

    protected function fieldContract(): array
    {
        return [
            'subject' => ['max' => 60, 'required' => true],
            'attributes' => ['required' => true],
            'description_markdown' => ['required' => true],
            'images' => ['max' => 8, 'required' => false],
            'price' => ['required' => false, 'automatic' => false],
        ];
    }

    protected function mapPackage(array $package, array $base): array
    {
        return [
            'subject' => mb_substr($base['title'], 0, 60),
            'attributes' => $base['parameters'],
            'description_markdown' => $base['body_markdown'],
            'images' => array_slice($base['media'], 0, 8),
            'price' => null,
            'price_note' => '根据口径、材质、压力、法兰标准和数量询价，系统不生成固定价格。',
            'source_ids' => $base['source_ids'],
            'account' => $base['account'],
        ];
    }
}
