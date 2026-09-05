<?php

namespace App\Services\GeoFlow;

final class ArticleSpecialPromptRenderer
{
    /**
     * Render keyword/description prompts with the variables advertised by the admin UI.
     */
    public function render(
        string $template,
        string $title,
        string $keyword,
        string $content,
    ): string {
        $template = trim($template);
        if ($template === '') {
            return '';
        }

        $context = [
            'title' => $title,
            'keyword' => $keyword,
            'content' => $content,
        ];
        $hasKnownVariable = preg_match('/\{\{\s*(title|keyword|content)\s*\}\}/iu', $template) === 1
            || preg_match('/\{\{#if\s+(title|keyword|content)\s*\}\}/iu', $template) === 1;

        $rendered = preg_replace_callback(
            '/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su',
            static function (array $matches) use ($context): string {
                $name = mb_strtolower((string) ($matches[1] ?? ''), 'UTF-8');
                if (! array_key_exists($name, $context)) {
                    return (string) ($matches[0] ?? '');
                }

                return trim($context[$name]) !== '' ? (string) ($matches[2] ?? '') : '';
            },
            $template,
        ) ?? $template;

        $rendered = preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u',
            static function (array $matches) use ($context): string {
                $name = mb_strtolower((string) ($matches[1] ?? ''), 'UTF-8');

                return array_key_exists($name, $context)
                    ? $context[$name]
                    : (string) ($matches[0] ?? '');
            },
            $rendered,
        ) ?? $rendered;

        if (! $hasKnownVariable) {
            $rendered = trim($rendered)."\n\n【文章上下文】\n"
                .'标题：'.$title."\n"
                .'焦点关键词：'.$keyword."\n"
                .'正文：'.$content;
        }

        return trim($rendered);
    }
}
