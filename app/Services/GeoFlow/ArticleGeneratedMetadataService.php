<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ArticleGeneratedMetadataService
{
    public function __construct(
        private readonly ArticleSpecialPromptRenderer $promptRenderer,
        private readonly ArticleContentGenerationService $contentGenerationService,
    ) {}

    /**
     * @return array{keywords:string,meta_description:string,keyword_status:string,description_status:string}
     */
    public function generate(
        Task $task,
        AiModel $aiModel,
        string $title,
        string $focusKeyword,
        string $content,
        string $fallbackDescription,
    ): array {
        $keywords = $this->normalizeKeywords('', $focusKeyword);
        $description = $this->normalizeDescription($fallbackDescription);
        $keywordStatus = (int) ($task->auto_keywords ?? 1) === 1 ? 'prompt_missing' : 'disabled';
        $descriptionStatus = (int) ($task->auto_description ?? 1) === 1 ? 'prompt_missing' : 'disabled';

        if ((int) ($task->auto_keywords ?? 1) === 1) {
            $keywordPrompt = $this->latestPrompt('keyword');
            if ($keywordPrompt !== null) {
                try {
                    $rawKeywords = $this->generateText(
                        $aiModel,
                        $this->promptRenderer->render($keywordPrompt, $title, $focusKeyword, $content),
                    );
                    $keywords = $this->normalizeKeywords($rawKeywords, $focusKeyword);
                    $keywordStatus = 'generated';
                } catch (Throwable $exception) {
                    $keywordStatus = 'fallback';
                    $this->logFallback('keywords', $task, $exception);
                }
            }
        }

        if ((int) ($task->auto_description ?? 1) === 1) {
            $descriptionPrompt = $this->latestPrompt('description');
            if ($descriptionPrompt !== null) {
                try {
                    $rawDescription = $this->generateText(
                        $aiModel,
                        $this->promptRenderer->render($descriptionPrompt, $title, $focusKeyword, $content),
                    );
                    $generatedDescription = $this->normalizeDescription($rawDescription);
                    if ($generatedDescription !== '') {
                        $description = $generatedDescription;
                        $descriptionStatus = 'generated';
                    } else {
                        $descriptionStatus = 'fallback';
                    }
                } catch (Throwable $exception) {
                    $descriptionStatus = 'fallback';
                    $this->logFallback('description', $task, $exception);
                }
            }
        }

        return [
            'keywords' => $keywords,
            'meta_description' => $description,
            'keyword_status' => $keywordStatus,
            'description_status' => $descriptionStatus,
        ];
    }

    private function latestPrompt(string $type): ?string
    {
        $content = Prompt::query()
            ->where('type', $type)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('content');
        $content = trim((string) $content);

        return $content !== '' ? $content : null;
    }

    private function generateText(AiModel $aiModel, string $prompt): string
    {
        if ($prompt === '') {
            return '';
        }

        $response = $this->contentGenerationService->generate($aiModel, $prompt);

        return OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
    }

    private function normalizeKeywords(string $raw, string $focusKeyword): string
    {
        $raw = preg_replace('/^```(?:text|markdown|json)?\s*|\s*```$/iu', '', trim($raw)) ?? trim($raw);
        $raw = preg_replace('/^(?:关键词|关键字|keywords?)\s*[：:]\s*/iu', '', $raw) ?? $raw;
        $parts = preg_split('/[,，;；、|\n\r]+/u', $raw) ?: [];
        array_unshift($parts, $focusKeyword);

        $normalized = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = preg_replace('/^\s*(?:[-*•]|\d+[.)、])\s*/u', '', (string) $part) ?? (string) $part;
            $part = trim($part, " \t\n\r\0\x0B\"'`[]【】");
            if ($part === '' || mb_strlen($part, 'UTF-8') > 100) {
                continue;
            }

            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $part;
            if (count($normalized) >= 8) {
                break;
            }
        }

        while ($normalized !== [] && mb_strlen(implode('，', $normalized), 'UTF-8') > 500) {
            array_pop($normalized);
        }

        return implode('，', $normalized);
    }

    private function normalizeDescription(string $raw): string
    {
        $value = preg_replace('/^```(?:text|markdown)?\s*|\s*```$/iu', '', trim($raw)) ?? trim($raw);
        $value = preg_replace('/^(?:SEO\s*)?(?:描述|摘要|meta\s*description)\s*[：:]\s*/iu', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value, " \t\n\r\0\x0B\"'`");

        return mb_substr($value, 0, 160, 'UTF-8');
    }

    private function logFallback(string $field, Task $task, Throwable $exception): void
    {
        Log::warning('Article metadata generation fell back to deterministic content.', [
            'task_id' => (int) $task->id,
            'field' => $field,
            'exception_type' => $exception::class,
        ]);
    }
}
