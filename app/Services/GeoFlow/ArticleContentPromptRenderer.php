<?php

namespace App\Services\GeoFlow;

final class ArticleContentPromptRenderer
{
    /** @param array<string,mixed> $runtimeContext */
    public function renderForEditor(string $title, string $keyword, ?string $promptContent, string $knowledgeContext = '', array $runtimeContext = []): string
    {
        return $this->render($title, $keyword, $promptContent, $knowledgeContext, $runtimeContext);
    }

    /** @param array<string,mixed> $runtimeContext */
    public function renderForWorker(string $title, string $keyword, ?string $promptContent, string $knowledgeContext = '', array $runtimeContext = []): string
    {
        return $this->render($title, $keyword, $promptContent, $knowledgeContext, $runtimeContext);
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐文章上下文。
     */
    private function render(
        string $title,
        string $keyword,
        ?string $promptContent,
        string $knowledgeContext,
        array $runtimeContext,
    ): string {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。";
            $isFallbackPrompt = true;
        }
        $isEnglish = $this->isLikelyEnglishPrompt($prompt);

        $runtimeContext = $this->normalizeRuntimeContext($runtimeContext);
        $context = array_merge([
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ], $runtimeContext);
        $hasExplicitContextVariables = $isFallbackPrompt || $this->promptHasKnownContextVariables($prompt);
        $hasExplicitKnowledgeVariable = $this->promptHasContextVariable($prompt, 'knowledge');
        $renderedPrompt = $this->renderPromptTemplate($prompt, $context);

        if (! $hasExplicitContextVariables) {
            $renderedPrompt = $this->appendSmartPromptContext($renderedPrompt, $title, $keyword, $knowledgeContext, $isEnglish);
        } elseif (! $hasExplicitKnowledgeVariable) {
            $renderedPrompt = $this->appendKnowledgeContext($renderedPrompt, $knowledgeContext, $isEnglish);
        }
        $missingRuntimeContext = array_filter(
            $runtimeContext,
            fn (string $value, string $name): bool => trim($value) !== '' && ! $this->promptHasContextVariable($prompt, $name),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($missingRuntimeContext !== []) {
            $renderedPrompt = $this->appendRuntimeContext($renderedPrompt, $missingRuntimeContext, $isEnglish);
        }

        $finalInstructions = array_values(array_filter([
            $this->promptHasCitationMarkerConstraint($prompt) ? '' : $this->knowledgeAttributionInstruction($isEnglish),
            $this->finalPromptInstruction($isEnglish),
        ], static fn (string $instruction): bool => trim($instruction) !== ''));

        return trim($renderedPrompt)."\n\n".implode("\n", $finalInstructions);
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        $variables = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $this->knownContextNames()));

        return preg_match('/\{\{\s*('.$variables.')\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+('.$variables.')\s*\}\}/iu', $prompt) === 1;
    }

    private function promptHasContextVariable(string $prompt, string $name): bool
    {
        $variable = preg_quote($name, '/');

        return preg_match('/\{\{\s*'.$variable.'\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+'.$variable.'\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * @param  array<string,string>  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array<string,string>  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return (string) ($context[mb_strtolower($name, 'UTF-8')] ?? '');
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), $this->knownContextNames(), true);
    }

    /** @return list<string> */
    private function knownContextNames(): array
    {
        return [
            'title', 'keyword', 'knowledge', 'product', 'page_role', 'audience', 'decision_stage',
            'buyer_questions', 'procurement_direction', 'desired_action', 'author', 'structure',
            'media_context', 'domain_rules',
        ];
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    private function normalizeRuntimeContext(array $context): array
    {
        $normalized = [];
        foreach ($this->knownContextNames() as $name) {
            if (in_array($name, ['title', 'keyword', 'knowledge'], true) || ! array_key_exists($name, $context)) {
                continue;
            }
            $value = $context[$name];
            if (is_array($value)) {
                $value = implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $normalized[$name] = trim((string) $value);
        }

        return $normalized;
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext, bool $isEnglish): string
    {
        if ($isEnglish) {
            $lines = [
                'Task context:',
                '- Article title: '.$title,
            ];
            if (trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【任务上下文】',
            '- 文章标题：'.$title,
        ];
        if (trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if (trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function appendKnowledgeContext(string $prompt, string $knowledgeContext, bool $isEnglish): string
    {
        if (trim($knowledgeContext) === '') {
            return trim($prompt);
        }

        if ($isEnglish) {
            return trim($prompt)."\n\nReference knowledge:\n".$knowledgeContext;
        }

        return trim($prompt)."\n\n【参考知识】\n".$knowledgeContext;
    }

    /** @param array<string,string> $context */
    private function appendRuntimeContext(string $prompt, array $context, bool $isEnglish): string
    {
        $labels = $isEnglish ? [
            'product' => 'Product',
            'page_role' => 'Page role',
            'audience' => 'Audience',
            'decision_stage' => 'Decision stage',
            'buyer_questions' => 'Buyer questions',
            'procurement_direction' => 'Procurement focus',
            'desired_action' => 'Desired action',
            'author' => 'Author identity',
            'structure' => 'Content structure',
            'media_context' => 'Approved media context',
            'domain_rules' => 'Domain fact rules',
        ] : [
            'product' => '产品',
            'page_role' => '页面职责',
            'audience' => '目标受众',
            'decision_stage' => '采购阶段',
            'buyer_questions' => '采购问题',
            'procurement_direction' => '采购关注',
            'desired_action' => '目标动作',
            'author' => '作者身份',
            'structure' => '文章结构',
            'media_context' => '可用媒体',
            'domain_rules' => '领域事实规则',
        ];
        $lines = [$isEnglish ? 'Content planning context:' : '【内容策划上下文】'];
        foreach ($context as $name => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = ($labels[$name] ?? $name).'：'.$value;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function finalPromptInstruction(bool $isEnglish): string
    {
        if ($isEnglish) {
            return 'Please output only the final article body in Markdown. Do not repeat the prompt or output placeholders.';
        }

        return '请直接输出最终文章正文（Markdown），不要重复提示词、不要输出占位符。';
    }

    private function knowledgeAttributionInstruction(bool $isEnglish): string
    {
        if ($isEnglish) {
            return 'Citation marker constraint: the final article must not contain internal evidence IDs, citation placeholders, or numbered citation markers, including [K1], [K2][K3], 【K1】, （K1）, or equivalent forms. When attribution is needed, use natural phrases such as “the materials show,” “the client confirmed,” or “according to the store materials,” without K-number labels. If the evidence is insufficient, use cautious wording and do not invent sources or conclusions.';
        }

        return '正文引用标注约束：最终文章中不得出现任何内部证据编号、引用占位符或编号引用标记，包括 [K1]、[K2][K3]、【K1】、（K1）及同类形式。文章中如需表达依据，直接写“资料显示”“客户确认”“根据门店资料”，不要添加 K 编号。证据不足时不要编造来源或结论。';
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    private function promptHasCitationMarkerConstraint(string $prompt): bool
    {
        return str_contains($prompt, '【正文引用标注约束】')
            || str_contains($prompt, '[Citation Marker Constraint]');
    }
}
