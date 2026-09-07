<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Ai\Agents\DeepSeekVisibilityAnalysisAgent;
use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

final class DeepSeekAnalysisClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  array<string,mixed>  $options
     */
    public function analyze(AiModel $model, string $prompt, array $sources = [], array $options = []): AiVisibilityResult
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('DeepSeek 分析提示词为空');
        }

        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('DeepSeek 模型 ID 为空');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('DeepSeek API 地址为空');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('DeepSeek API Key 为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $providerName = OpenAiRuntimeProvider::registerProvider('ai_visibility_deepseek', $driver, $providerUrl, $apiKey);
        $maxTokens = $this->resolveMaxTokens($model, $options);
        $agent = new DeepSeekVisibilityAnalysisAgent($maxTokens);

        $fullPrompt = $this->buildPrompt($prompt, $sources);
        $request = [
            'provider_url' => $providerUrl,
            'model_id' => $modelId,
            'prompt' => $fullPrompt,
            'source_count' => count($sources),
        ];

        $startedAt = hrtime(true);
        try {
            $response = $agent->prompt($fullPrompt, [], $providerName, $modelId);
        } catch (Throwable $exception) {
            throw new RuntimeException('DeepSeek 分析失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $rawText = (string) ($response->text ?? '');
        $answerText = OpenAiRuntimeProvider::normalizeGeneratedText($rawText);
        $usage = $this->extractUsage($response);
        if ($answerText === '') {
            throw new RuntimeException($this->emptyResponseMessage($response, $usage, $maxTokens));
        }

        return $this->normalizer->normalizeTextAnalysis(
            providerType: AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
            providerKey: 'deepseek',
            modelId: $modelId,
            answerText: $answerText,
            sources: $sources,
            usage: $usage,
            request: $request,
            rawResponse: [
                'text' => $rawText,
                'usage' => $usage,
            ],
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  list<AiVisibilitySourceData>  $sources
     */
    private function buildPrompt(string $prompt, array $sources): string
    {
        if ($sources === []) {
            return $prompt;
        }

        $sourceLines = [];
        foreach ($sources as $source) {
            $title = $source->title !== null ? $source->title : 'Untitled source';
            $url = $source->url !== null ? ' - '.$source->url : '';
            $summary = $source->summary ?? $source->snippet ?? '';
            $summary = $summary !== '' ? "\n摘要：".mb_substr($summary, 0, 500, 'UTF-8') : '';
            $sourceLines[] = sprintf('[%s] %s%s%s', $source->citationKey ?? 'S?', $title, $url, $summary);
        }

        return $prompt."\n\n可用信源如下（这些是外部搜索/工具返回的信源，不代表 DeepSeek 原生引用）：\n".implode("\n\n", $sourceLines);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractUsage(object $response): array
    {
        $usage = $response->usage ?? null;
        if ($usage instanceof Arrayable) {
            return $usage->toArray();
        }
        if ($usage instanceof JsonSerializable) {
            $serialized = $usage->jsonSerialize();

            return is_array($serialized) ? $serialized : [];
        }

        return is_array($usage) ? $usage : [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveMaxTokens(AiModel $model, array $options): int
    {
        $requestedLimit = (int) ($options['max_tokens'] ?? 0);
        if ($requestedLimit > 0) {
            return $requestedLimit;
        }

        $modelLimit = (int) ($model->max_tokens ?? 0);
        if ($modelLimit > 0) {
            return $modelLimit;
        }

        return max(512, (int) config('geoflow.ai_visibility.default_analysis_max_tokens', 4096));
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function emptyResponseMessage(object $response, array $usage, int $maxTokens): string
    {
        $finishReason = $this->extractFinishReason($response);
        $completionTokens = max(0, (int) ($usage['completion_tokens'] ?? 0));
        $reasoningTokens = max(0, (int) ($usage['reasoning_tokens'] ?? 0));
        $budgetExhausted = $finishReason === FinishReason::Length->value
            || ($maxTokens > 0 && $completionTokens >= $maxTokens);
        $cause = $budgetExhausted ? '输出令牌预算已用尽' : '未生成可见正文';

        return sprintf(
            'DeepSeek 分析返回空内容：%s（finish_reason=%s，completion_tokens=%d，reasoning_tokens=%d）',
            $cause,
            $finishReason,
            $completionTokens,
            $reasoningTokens,
        );
    }

    private function extractFinishReason(object $response): string
    {
        $steps = $response->steps ?? null;
        if (! $steps instanceof Collection) {
            return FinishReason::Unknown->value;
        }

        $finishReason = $steps->last()?->finishReason ?? null;

        return $finishReason instanceof FinishReason ? $finishReason->value : FinishReason::Unknown->value;
    }
}
