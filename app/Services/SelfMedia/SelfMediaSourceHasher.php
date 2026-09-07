<?php

namespace App\Services\SelfMedia;

use App\Models\Article;
use Illuminate\Support\Str;

final class SelfMediaSourceHasher
{
    public function hash(Article $article): string
    {
        return hash('sha256', json_encode([
            'title' => $this->normalize((string) $article->title),
            'excerpt' => $this->normalize((string) ($article->excerpt ?? '')),
            'content' => $this->normalize((string) $article->content),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::of($value)->replaceMatches('/\s+/u', ' ')->trim()->toString();
    }
}
