<?php

namespace App\Services\Content\Adapters;

final class BaiduAicaigouPlatformAdapter extends AbstractBrowserAssistedPlatformAdapter
{
    protected function key(): string
    {
        return 'baidu-aicaigou';
    }

    protected function contentTypes(): array
    {
        return ['product'];
    }

    protected function fieldContract(): array
    {
        return [
            'product_title' => ['max' => 60, 'required' => true],
            'selling_points' => ['max' => 5, 'required' => true],
            'attributes' => ['required' => true],
            'detail_markdown' => ['required' => true],
            'images' => ['max' => 10, 'required' => false],
        ];
    }

    protected function mapPackage(array $package, array $base): array
    {
        return [
            'product_title' => mb_substr($base['title'], 0, 60),
            'selling_points' => collect((array) ($package['body_sections'] ?? []))->pluck('heading')->filter()->take(5)->values()->all(),
            'attributes' => $base['parameters'],
            'detail_markdown' => $base['body_markdown'],
            'images' => $base['media'],
            'source_ids' => $base['source_ids'],
            'inquiry_requirements' => ['口径', '压力等级', '介质', '温度', '连接标准', '数量'],
            'account' => $base['account'],
        ];
    }
}
