<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $category = \App\Models\Category::query()->create(['name' => '分类', 'slug' => 'category']);
        $author = \App\Models\Author::query()->create(['name' => '作者']);
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
}
