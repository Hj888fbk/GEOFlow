<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Support\GeoFlow\KeywordNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ArticleGeneratedMetadataService
{
    public function __construct(
        private readonly ArticleSpecialPromptRenderer $promptRenderer,
        private readonly WorkerAiModelInvocationGateway $modelInvocationGateway,
    ) {}

    /**
     * @return array{keywords:string,meta_description:string,keyword_status:string,description_status:string}
     */
    public function generate(
        Task $task,
        AiExecutionContext $executionContext,
        AiModel $aiModel,
        string $title,
        string $focusKeyword,
        string $content,
        string $fallbackDescription,
    ): array {
        $keywords = KeywordNormalizer::normalize('', $focusKeyword);
        $description = $this->normalizeDescription($fallbackDescription);
        $keywordStatus = (int) ($task->auto_keywords ?? 1) === 1 ? 'prompt_missing' : 'disabled';
        $descriptionStatus = (int) ($task->auto_description ?? 1) === 1 ? 'prompt_missing' : 'disabled';

        if ((int) ($task->auto_keywords ?? 1) === 1) {
            $keywordPrompt = $this->latestPrompt('keyword');
            if ($keywordPrompt !== null) {
                try {
                    $keywordPromptText = $this->promptRenderer->render($keywordPrompt, $title, $focusKeyword, $content)
                        ."\n输出规则：只返回 3-8 个完整关键词；关键词必须来自标题或正文，保留产品型号和单位，不要截断中文词，不要使用问号、引号或列表编号。";
                    $rawKeywords = $this->generateText(
                        $executionContext,
                        $aiModel,
                        $keywordPromptText,
                        'keywords',
                    );
                    $keywords = KeywordNormalizer::normalize($rawKeywords, $focusKeyword);
                    $keywordStatus = 'generated';
                } catch (AiModelAccessException $exception) {
                    throw $exception;
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
                        $executionContext,
                        $aiModel,
                        $this->promptRenderer->render($descriptionPrompt, $title, $focusKeyword, $content),
                        'description',
                    );
                    $generatedDescription = $this->normalizeDescription($rawDescription);
                    if ($generatedDescription !== '') {
                        $description = $generatedDescription;
                        $descriptionStatus = 'generated';
                    } else {
                        $descriptionStatus = 'fallback';
                    }
                } catch (AiModelAccessException $exception) {
                    throw $exception;
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

    private function generateText(
        AiExecutionContext $executionContext,
        AiModel $aiModel,
        string $prompt,
        string $field,
    ): string {
        if ($prompt === '') {
            return '';
        }

        return $this->modelInvocationGateway->generate(
            $executionContext,
            $aiModel,
            $prompt,
            static fn (array $invocation): string => OpenAiRuntimeProvider::normalizeGeneratedText(
                (string) ($invocation['response']->text ?? ''),
            ),
            [
                'call_key' => 'metadata-'.$field,
                'operation' => 'article.metadata.'.$field,
                'business_source' => 'worker_article_metadata_generation',
            ],
        );
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
