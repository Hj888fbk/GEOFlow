<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

final class DeepSeekVisibilityAnalysisAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(private readonly int $outputTokenLimit) {}

    public function instructions(): string
    {
        return '你是 GEO/AI 可见性分析助手。请基于输入的 AI 回答和信源直接输出最终分析，不展示思考过程，并明确区分事实、推断和投放建议。';
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $providerKey = $provider instanceof Lab ? $provider->value : $provider;

        return $providerKey === Lab::DeepSeek->value
            ? ['thinking' => ['type' => 'disabled']]
            : [];
    }
}
