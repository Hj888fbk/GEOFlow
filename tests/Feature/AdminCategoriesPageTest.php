<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台栏目管理页最小可用测试：鉴权、页面可达、入口链接正确。
 */
class AdminCategoriesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_categories_page(): void
    {
        $this->get(route('admin.categories.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_categories_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_admin',
            'password' => 'secret-123',
            'email' => 'categories-admin@example.com',
            'display_name' => 'Categories Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(__('admin.categories.page_title'));
    }

    public function test_articles_page_category_manage_button_points_to_categories_route(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_link_admin',
            'password' => 'secret-123',
            'email' => 'categories-link-admin@example.com',
            'display_name' => 'Categories Link Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(AdminWeb::routePath('admin.categories.index'), false);
    }

    public function test_chinese_category_name_generates_pinyin_slug(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_slug_admin',
            'password' => 'secret-123',
            'email' => 'categories-slug-admin@example.com',
            'display_name' => 'Categories Slug Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => '行业资讯',
                'slug' => '',
                'description' => '',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => '行业资讯',
            'slug' => 'xing-ye-zi-xun',
        ]);
    }

    public function test_chinese_category_slug_conflict_gets_readable_counter_suffix(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_slug_conflict_admin',
            'password' => 'secret-123',
            'email' => 'categories-slug-conflict-admin@example.com',
            'display_name' => 'Categories Slug Conflict Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Category::query()->create([
            'name' => '已有资讯',
            'slug' => 'xing-ye-zi-xun',
            'description' => '',
            'sort_order' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => '行业资讯',
                'slug' => '',
                'description' => '',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => '行业资讯',
            'slug' => 'xing-ye-zi-xun-2',
        ]);
    }

    public function test_manual_category_slug_still_takes_priority(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_manual_slug_admin',
            'password' => 'secret-123',
            'email' => 'categories-manual-slug-admin@example.com',
            'display_name' => 'Categories Manual Slug Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => '行业资讯',
                'slug' => 'custom-news',
                'description' => '',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => '行业资讯',
            'slug' => 'custom-news',
        ]);
    }

    public function test_long_chinese_category_name_slug_is_truncated_with_suffix_room(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_long_slug_admin',
            'password' => 'secret-123',
            'email' => 'categories-long-slug-admin@example.com',
            'display_name' => 'Categories Long Slug Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $longName = str_repeat('行业资讯', 10);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => $longName,
                'slug' => '',
                'description' => '',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->where('name', $longName)->firstOrFail();
        // slug 列 VARCHAR(100)：基础部分截断到 96，为 -2/-3 后缀预留空间，
        // 且在 '-' 词边界切断，不以 '-' 结尾。
        $this->assertLessThanOrEqual(96, mb_strlen((string) $category->slug, 'UTF-8'));
        $this->assertStringEndsNotWith('-', (string) $category->slug);
    }

    public function test_category_with_only_trashed_articles_is_still_not_deletable(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_delete_admin',
            'password' => 'secret-123',
            'email' => 'categories-delete-admin@example.com',
            'display_name' => 'Categories Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $category = Category::query()->create([
            'name' => 'Soft Deleted Category',
            'slug' => 'soft-deleted-category',
            'description' => '',
            'sort_order' => 0,
        ]);

        $author = Author::query()->create([
            'name' => 'Category Author',
            'email' => 'category-author@example.com',
        ]);

        $article = Article::query()->create([
            'title' => 'Soft deleted article',
            'slug' => 'soft-deleted-article',
            'excerpt' => '',
            'content' => 'Body',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $article->delete();

        $deleteUrl = route('admin.categories.delete', ['categoryId' => (int) $category->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(__('admin.categories.article_count_badge', ['count' => 1]))
            ->assertSee(__('admin.categories.article_count_trashed_hint', ['count' => 1]))
            ->assertDontSee($deleteUrl, false);

        $this->actingAs($admin, 'admin')
            ->post($deleteUrl)
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('categories', [
            'id' => (int) $category->id,
        ]);
    }
}
