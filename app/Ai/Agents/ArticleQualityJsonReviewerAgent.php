<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

final class ArticleQualityJsonReviewerAgent implements Agent, HasProviderOptions
{
    use ConfiguresArticleQualityProviderOptions;
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit = 2048,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions."\n\n供应商当前使用 JSON 回退模式。只输出一个符合 F: Format 定义的 JSON 对象，不要输出 Markdown 代码块或解释。issues.field 只能是 title、excerpt、content、keywords、meta_description。所有要求为字符串的字段必须输出字符串；没有值时输出空字符串，禁止输出 null。knowledge_refs、legal_refs、issues、uncertainties 必须输出 JSON 数组。";
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }
}
