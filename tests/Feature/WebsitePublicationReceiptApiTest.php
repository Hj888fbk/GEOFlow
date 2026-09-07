<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebsitePublicationReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_receipt_requires_publish_scope_and_idempotency_key(): void
    {
        [$admin, $article] = $this->fixtures();
        $payload = $this->payload($article);

        $writeToken = $admin->createToken('website-receipt-write-only', ['articles:write'])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$writeToken,
            'X-Idempotency-Key' => 'website-receipt-scope-check',
        ])->postJson($this->endpoint($article), $payload)
            ->assertForbidden();

        $publishToken = $admin->createToken('website-receipt-publish', ['articles:publish'])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$publishToken,
            'X-Idempotency-Key' => '',
        ])
            ->postJson($this->endpoint($article), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertDatabaseCount('website_publication_receipts', 0);
    }

    public function test_website_receipt_is_idempotent_and_returns_the_same_receipt(): void
    {
        [$admin, $article] = $this->fixtures();
        $token = $admin->createToken('website-receipt-publish', ['articles:publish'])->plainTextToken;
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'website-receipt-idempotent-replay',
        ];
        $payload = $this->payload($article);

        $first = $this->withHeaders($headers)->postJson($this->endpoint($article), $payload)->assertCreated();
        $second = $this->withHeaders($headers)->postJson($this->endpoint($article), $payload)->assertCreated();

        $this->assertSame($first->json('data.receipt.id'), $second->json('data.receipt.id'));
        $this->assertDatabaseCount('website_publication_receipts', 1);
        $this->assertDatabaseCount('manual_publication_batches', 0);
        $this->assertDatabaseCount('manual_publications', 0);
    }

    public function test_hash_mismatch_is_rejected_before_any_self_media_work_is_created(): void
    {
        [$admin, $article] = $this->fixtures();
        $token = $admin->createToken('website-receipt-publish', ['articles:publish'])->plainTextToken;
        $payload = $this->payload($article);
        $payload['readback_hash'] = str_repeat('0', 64);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'website-receipt-hash-mismatch',
        ])->postJson($this->endpoint($article), $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'website_readback_failed');

        $this->assertDatabaseCount('website_publication_receipts', 0);
        $this->assertDatabaseCount('manual_publication_batches', 0);
        $this->assertDatabaseCount('manual_publications', 0);
    }

    /** @return array{Admin,Article} */
    private function fixtures(): array
    {
        $admin = Admin::query()->create([
            'username' => uniqid('website_receipt_'),
            'password' => 'secret-123',
            'email' => uniqid('website-receipt-').'@example.com',
            'display_name' => 'Website Receipt API',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['name' => '官网回执分类', 'slug' => uniqid('website-receipt-category-')]);
        $author = Author::query()->create(['name' => '官网回执作者']);
        $article = Article::query()->create([
            'title' => '官网正式回执文章',
            'slug' => uniqid('website-receipt-article-'),
            'excerpt' => '正式摘要',
            'content' => '经过在线回读的官网正式正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return [$admin, $article];
    }

    /** @return array<string,mixed> */
    private function payload(Article $article): array
    {
        $hash = app(SelfMediaSourceHasher::class)->hash($article);

        return [
            'receipt_id' => 'HJ-WEB-'.uniqid(),
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => 'https://www.example.com/articles/'.$article->slug,
            'http_status' => 200,
            'source_hash' => $hash,
            'readback_hash' => $hash,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function endpoint(Article $article): string
    {
        return '/api/v1/articles/'.$article->id.'/website-publication-receipt';
    }
}
