<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use PHPUnit\Framework\TestCase;

class ArticleHtmlPresenterTest extends TestCase
{
    public function test_markdown_renderer_supports_github_flavored_markdown(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml(<<<'MD'
## Checklist

| 项目 | 状态 |
| --- | --- |
| 表格 | 正常 |

- [x] 已完成
- [ ] 未完成

~~删除线~~
https://example.com/docs
MD);

        $this->assertStringContainsString('<div class="article-table-wrap"><table class="article-table">', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked=""', $html);
        $this->assertStringContainsString('<del>删除线</del>', $html);
        $this->assertStringContainsString('<a href="https://example.com/docs">https://example.com/docs</a>', $html);
    }

    public function test_markdown_renderer_strips_unsafe_html(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml('<script>alert("x")</script>[bad](javascript:alert(1))');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_card_summary_omits_a_leading_markdown_section_heading(): void
    {
        $article = new Article([
            'title' => 'Enterprise GEO Guide',
            'excerpt' => "## Why GEO matters\n\nA clear answer for enterprise teams.",
            'content' => '',
        ]);

        $this->assertSame(
            'A clear answer for enterprise teams.',
            ArticleHtmlPresenter::cardSummary($article)
        );
    }

    public function test_whole_document_markdown_fence_is_removed_but_inner_code_block_is_preserved(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml("```markdown\n# 主标题\n\n## 二级标题\n\n正文\n\n```php\necho 1;\n```\n```");

        $this->assertStringContainsString('<h1>主标题</h1>', $html);
        $this->assertStringContainsString('<h2>二级标题</h2>', $html);
        $this->assertStringContainsString('<code class="language-php">echo 1;', $html);
        $this->assertStringNotContainsString('language-markdown', $html);
    }
}
