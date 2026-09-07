<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleCitationMarkerCleaner;
use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServicePromptTest extends TestCase
{
    public function test_custom_prompt_without_variables_receives_smart_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业、可信、适合 GEO 引用的文章。',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('请写一篇专业、可信、适合 GEO 引用的文章。', $prompt);
        $this->assertStringContainsString('【任务上下文】', $prompt);
        $this->assertStringContainsString('- 文章标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('- 核心关键词：AI CRM', $prompt);
        $this->assertStringContainsString('这是来自知识库的参考资料。', $prompt);
    }

    public function test_prompt_with_variables_keeps_precise_rendering_without_extra_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '标题：{{title}}'."\n".'{{#if keyword}}关键词：{{keyword}}{{/if}}'."\n".'{{#if Knowledge}}知识：{{Knowledge}}{{/if}}',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('关键词：AI CRM', $prompt);
        $this->assertStringContainsString('知识：这是来自知识库的参考资料。', $prompt);
        $this->assertStringNotContainsString('【任务上下文】', $prompt);
    }

    public function test_english_prompt_without_variables_receives_english_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for AI search and answer engines.',
            'Reference knowledge from the business knowledge base.'
        );

        $this->assertStringContainsString('Task context:', $prompt);
        $this->assertStringContainsString('- Article title: What is AI CRM?', $prompt);
        $this->assertStringContainsString('- Core keyword: AI CRM', $prompt);
        $this->assertStringContainsString('Reference knowledge from the business knowledge base.', $prompt);
        $this->assertStringContainsString('Citation marker constraint', $prompt);
        $this->assertStringContainsString('must not contain internal evidence IDs', $prompt);
        $this->assertStringContainsString('Public-facing knowledge boundary', $prompt);
        $this->assertStringContainsString('Never expose internal knowledge-base names or identifiers', $prompt);
        $this->assertStringContainsString('evidence cutoff dates', $prompt);
        $this->assertStringContainsString('Please output only the final article body in Markdown.', $prompt);
    }

    public function test_worker_prompt_with_knowledge_context_forbids_evidence_ids(): void
    {
        $prompt = $this->renderContentPrompt(
            'GEO 诊断怎么做？',
            'GEO 诊断',
            '请写一篇基于事实证据的文章。',
            "【知识库证据】\n【证据 K1】\n来源：GEOFlow 官方文档\n内容：GEO 诊断需要先定位问题。"
        );

        $this->assertStringContainsString('【证据 K1】', $prompt);
        $this->assertStringContainsString('正文引用标注约束', $prompt);
        $this->assertStringContainsString('最终文章中不得出现任何内部证据编号', $prompt);
        $this->assertStringContainsString('[K2][K3]', $prompt);
        $this->assertStringNotContainsString('并在相关句子后标注证据编号', $prompt);
        $this->assertStringContainsString('证据不足时不要编造来源或结论', $prompt);
        $this->assertStringContainsString('公开内容边界', $prompt);
        $this->assertStringContainsString('不得披露内部知识库名称或编号', $prompt);
        $this->assertStringContainsString('版本号、证据截止日期', $prompt);
        $this->assertStringContainsString('“资料与发布边界”“内部依据”', $prompt);
        $this->assertStringContainsString('本文用于采购前期核对', $prompt);
    }

    public function test_worker_prompt_without_knowledge_context_still_forbids_evidence_ids(): void
    {
        $prompt = $this->renderContentPrompt(
            'GEO 诊断怎么做？',
            'GEO 诊断',
            '请写一篇文章。',
            ''
        );

        $this->assertStringContainsString('正文引用标注约束', $prompt);
        $this->assertStringContainsString('最终文章中不得出现任何内部证据编号', $prompt);
        $this->assertStringContainsString('公开内容边界', $prompt);
    }

    public function test_existing_citation_constraint_does_not_skip_public_facing_knowledge_boundary(): void
    {
        $prompt = $this->renderContentPrompt(
            '橡胶接头采购要点',
            '橡胶接头',
            "【正文引用标注约束】最终文章不得显示 K 编号。\n请生成采购文章。",
            '内部依据：KB-HJ-ALL-001 v1.0.0，证据截止 2026-09-03。',
        );

        $this->assertSame(1, substr_count($prompt, '【正文引用标注约束】'));
        $this->assertStringContainsString('公开内容边界', $prompt);
        $this->assertStringContainsString('不得披露内部知识库名称或编号', $prompt);
        $this->assertStringContainsString('治理或审批状态、审核备注', $prompt);
        $this->assertStringContainsString('由供需双方书面确认', $prompt);
    }

    public function test_article_citation_marker_cleaner_removes_supported_marker_forms(): void
    {
        $cleaner = app(ArticleCitationMarkerCleaner::class);
        $content = '甲[K1][K2]，乙 ` [K3] `，丙【K4】，丁（K5），戊［K6］，己[K7、K8]，庚 `K9`。';

        $cleaned = $cleaner->cleanContent($content);

        $this->assertSame('甲，乙，丙，丁，戊，己，庚 `K9`。', $cleaned);
        $this->assertFalse($cleaner->contains($cleaned));
        $this->assertSame($cleaned, $cleaner->cleanContent($cleaned));
    }

    public function test_article_citation_marker_cleaner_preserves_unconfirmed_bare_ids_in_generated_summaries(): void
    {
        $cleaner = app(ArticleCitationMarkerCleaner::class);

        $this->assertSame(
            '第一段 K1 第二段第三段',
            $cleaner->cleanGeneratedSummary('第一段 K1 第二段 [K2] 第三段', '来源 [K1][K2]'),
        );
    }

    public function test_english_prompt_language_is_stable_with_chinese_knowledge(): void
    {
        $prompt = $this->renderContentPrompt(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for business readers.',
            str_repeat('这是中文知识材料。', 20),
        );

        $this->assertStringContainsString('Citation marker constraint', $prompt);
        $this->assertStringContainsString('Public-facing knowledge boundary', $prompt);
        $this->assertStringContainsString('Please output only the final article body', $prompt);
        $this->assertStringNotContainsString('请直接输出最终文章正文', $prompt);
        $this->assertStringNotContainsString('公开内容边界', $prompt);
    }

    public function test_unknown_template_blocks_are_preserved_for_future_extensions(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}'."\n".'标题：{{title}}',
            ''
        );

        $this->assertStringContainsString('{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}', $prompt);
        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
    }

    private function renderContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $title, $keyword, $promptContent, $knowledgeContext);
    }
}
