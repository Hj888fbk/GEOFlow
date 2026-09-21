<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleWorkflowSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_chinese_title_gets_readable_pinyin_slug(): void
    {
        $slug = ArticleWorkflow::generateUniqueSlug('XH41-F 法兰式橡胶鸭嘴止回阀');

        $this->assertMatchesRegularExpression('/^xh41-f-fa-lan-shi-xiang-xiao-ya-zui-zhi-hui-fa$/', $slug);
    }

    public function test_slug_collision_keeps_readable_base_and_adds_suffix(): void
    {
        $category = Category::query()->create(['name' => '分类', 'slug' => 'category']);
        $author = Author::query()->create(['name' => '作者']);
        Article::query()->create([
            'title' => '已有文章',
            'slug' => 'existing-title',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $slug = ArticleWorkflow::generateUniqueSlug('Existing Title');

        $this->assertMatchesRegularExpression('/^existing-title-[a-z0-9]{4}$/', $slug);
    }

    public function test_long_title_slug_stays_within_80_chars_and_cuts_at_word_boundary(): void
    {
        $title = '法兰式橡胶鸭嘴止回阀行业资讯产品选型安装维护技术指南大全2026年最新版';
        $full = Str::slug(Str::transliterate($title, '', false));
        $this->assertGreaterThan(80, mb_strlen($full, 'UTF-8'));

        $slug = ArticleWorkflow::generateUniqueSlug($title);

        $this->assertLessThanOrEqual(80, mb_strlen($slug, 'UTF-8'));
        // 截断发生在 '-' 词边界：完整拼音 slug 应以「截断结果 + -」开头，
        // 即最后一个拼音音节完整保留，不会被硬切断。
        $this->assertStringStartsWith($slug.'-', $full);
    }

    public function test_long_ascii_title_slug_stays_within_80_chars(): void
    {
        $title = 'the quick brown fox jumps over the lazy dog and keeps running through the forest every single morning';
        $full = Str::slug(Str::transliterate($title, '', false));
        $this->assertGreaterThan(80, mb_strlen($full, 'UTF-8'));

        $slug = ArticleWorkflow::generateUniqueSlug($title);

        $this->assertLessThanOrEqual(80, mb_strlen($slug, 'UTF-8'));
        $this->assertStringStartsWith($slug.'-', $full);
    }

    public function test_single_long_word_title_falls_back_to_hard_cut_at_80_chars(): void
    {
        $title = str_repeat('a', 120);

        $slug = ArticleWorkflow::generateUniqueSlug($title);

        // 没有 '-' 词边界时退回 80 字符硬切。
        $this->assertSame(str_repeat('a', 80), $slug);
    }
}
