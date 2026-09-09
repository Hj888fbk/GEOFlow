<?php

namespace Tests\Unit;

use App\Models\ManualPublicationAccount;
use App\Services\SelfMedia\PortableArticleDocumentService;
use DomainException;
use Tests\TestCase;

final class PortableArticleDocumentServiceTest extends TestCase
{
    public function test_it_normalizes_headings_preserves_structure_and_binds_media_by_key(): void
    {
        $service = new PortableArticleDocumentService;
        $document = $service->build('验收文章', <<<'MD'
```markdown
# 验收文章

# 章节一

## 章节二

### 章节三

#### 章节四

**加粗**

1. 编号
2. 编号

- 项目

> 引用

| 项目 | 值 |
| --- | --- |
| A | 1 |

{{media:m_aaaaaaaaaaaaaaaaaaaaaaaa}}

```php
echo "ok";
```
```
MD, [[
            'media_key' => 'm_aaaaaaaaaaaaaaaaaaaaaaaa',
            'role' => 'body',
            'required' => true,
        ]], ManualPublicationAccount::PLATFORM_CSDN);

        $this->assertSame('portable-article-document/v1', $document['schema_version']);
        $this->assertStringNotContainsString('```markdown', $document['markdown']);
        $this->assertStringNotContainsString('# 验收文章', $document['markdown']);
        $this->assertStringContainsString('## 章节一', $document['markdown']);
        $this->assertStringContainsString('### 章节三', $document['markdown']);
        $this->assertStringContainsString('#### 章节四', $document['markdown']);
        $this->assertSame([
            ['level' => 2, 'text' => '章节一'],
            ['level' => 2, 'text' => '章节二'],
            ['level' => 3, 'text' => '章节三'],
            ['level' => 4, 'text' => '章节四'],
        ], $document['render_fingerprint']['heading_outline']);
        $this->assertStringContainsString('data-geoflow-media-key="m_aaaaaaaaaaaaaaaaaaaaaaaa"', $document['html']);
        $this->assertSame(['m_aaaaaaaaaaaaaaaaaaaaaaaa'], $document['render_fingerprint']['image_order']);
        $this->assertSame(3, $document['render_fingerprint']['list_item_count']);
        $this->assertSame(1, $document['render_fingerprint']['table_count']);
    }

    public function test_it_rejects_missing_or_duplicate_required_images(): void
    {
        $this->expectException(DomainException::class);

        (new PortableArticleDocumentService)->build('标题', '正文', [[
            'media_key' => 'm_bbbbbbbbbbbbbbbbbbbbbbbb',
            'role' => 'body',
            'required' => true,
        ]], ManualPublicationAccount::PLATFORM_BAIJIAHAO);
    }

    public function test_non_csdn_code_blocks_degrade_to_readable_quotes(): void
    {
        $document = (new PortableArticleDocumentService)->build('标题', "## 步骤\n\n```shell\necho ok\n```", [], ManualPublicationAccount::PLATFORM_SOHU_MEDIA);

        $this->assertStringNotContainsString('<pre>', $document['html']);
        $this->assertStringContainsString('<blockquote>', $document['html']);
    }

    public function test_three_body_images_keep_their_order_when_the_first_is_also_the_cover(): void
    {
        $keys = [
            'm_111111111111111111111111',
            'm_222222222222222222222222',
            'm_333333333333333333333333',
        ];
        $manifest = array_map(static fn (string $key, int $index): array => [
            'media_key' => $key,
            'role' => 'body',
            'required' => true,
            'is_cover' => $index === 0,
            'position' => $index + 1,
        ], $keys, array_keys($keys));
        $document = (new PortableArticleDocumentService)->build(
            '图片验收',
            "## 结构与特殊字符\n\n中文标点：，。；数字 1.6MPa 与 [链接](https://example.com)。\n\n{{media:{$keys[0]}}}\n\n说明一\n\n{{media:{$keys[1]}}}\n\n说明二\n\n{{media:{$keys[2]}}}",
            $manifest,
            ManualPublicationAccount::PLATFORM_BAIJIAHAO,
        );

        $this->assertSame($keys, $document['render_fingerprint']['image_order']);
        $this->assertSame(3, substr_count($document['html'], 'data-geoflow-media-key='));
        foreach ($keys as $key) {
            $this->assertStringContainsString('data-geoflow-media-key="'.$key.'"', $document['html']);
        }
        $this->assertStringContainsString('中文标点：，。；数字 1.6MPa', $document['plain_text']);
    }

    public function test_it_rejects_a_whole_document_code_block_and_heading_level_jumps(): void
    {
        $service = new PortableArticleDocumentService;

        try {
            $service->build('标题', "```php\necho 'only code';\n```", [], ManualPublicationAccount::PLATFORM_CSDN);
            $this->fail('Expected a whole-document code block to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('整篇正文', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('标题层级跳跃');
        $service->build('标题', "## 正常二级\n\n#### 跳过三级", [], ManualPublicationAccount::PLATFORM_CSDN);
    }
}
