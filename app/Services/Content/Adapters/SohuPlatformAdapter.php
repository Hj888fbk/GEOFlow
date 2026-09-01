<?php

namespace App\Services\Content\Adapters;

final class SohuPlatformAdapter extends AbstractBrowserAssistedPlatformAdapter
{
    protected function key(): string
    {
        return 'sohu';
    }

    protected function fieldContract(): array
    {
        return [
            'title' => ['max' => 30, 'required' => true],
            'summary' => ['max' => 120, 'required' => true],
            'content_markdown' => ['required' => true],
            'images' => ['max' => 9, 'required' => false],
        ];
    }

    protected function mapPackage(array $package, array $base): array
    {
        return [
            'title' => mb_substr($base['title'], 0, 30),
            'summary' => mb_substr($base['summary'], 0, 120),
            'content_markdown' => $base['body_markdown'],
            'images' => array_slice($base['media'], 0, 9),
            'source_ids' => $base['source_ids'],
            'account' => $base['account'],
        ];
    }
}
