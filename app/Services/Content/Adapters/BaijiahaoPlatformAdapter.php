<?php

namespace App\Services\Content\Adapters;

final class BaijiahaoPlatformAdapter extends AbstractBrowserAssistedPlatformAdapter
{
    protected function key(): string
    {
        return 'baijiahao';
    }

    protected function fieldContract(): array
    {
        return [
            'title' => ['max' => 30, 'required' => true],
            'abstract' => ['max' => 120, 'required' => true],
            'content_markdown' => ['required' => true],
            'cover_images' => ['max' => 3, 'required' => false],
        ];
    }

    protected function mapPackage(array $package, array $base): array
    {
        return [
            'title' => mb_substr($base['title'], 0, 30),
            'abstract' => mb_substr($base['summary'], 0, 120),
            'content_markdown' => $base['body_markdown'],
            'cover_images' => array_slice($base['media'], 0, 3),
            'source_ids' => $base['source_ids'],
            'account' => $base['account'],
        ];
    }
}
