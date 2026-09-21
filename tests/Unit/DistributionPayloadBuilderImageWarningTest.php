<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DistributionPayloadBuilderImageWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_local_image_is_flagged_and_logged(): void
    {
        Log::spy();
        $relativePath = 'storage/geoflow-payload-test/big.jpg';
        $absolutePath = storage_path('app/public/geoflow-payload-test/big.jpg');
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, str_repeat("\0", 5 * 1024 * 1024 + 1));

        try {
            $payload = $this->buildPayload("正文。\n\n![大图](/{$relativePath})");
        } finally {
            @unlink($absolutePath);
            @rmdir(dirname($absolutePath));
        }

        $asset = $this->assetFor($payload, '/'.$relativePath);
        $this->assertSame('file_too_large', $asset['skip_reason'] ?? null);
        $this->assertArrayNotHasKey('content_base64', $asset);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => ($context['skip_reason'] ?? null) === 'file_too_large'
                && ($context['source_url'] ?? null) === '/storage/geoflow-payload-test/big.jpg'
                && is_string($context['path'] ?? null),
        );
    }

    public function test_missing_local_image_is_logged_as_unreadable(): void
    {
        Log::spy();

        $payload = $this->buildPayload("正文。\n\n![缺图](/storage/geoflow-payload-test/does-not-exist.jpg)");

        $asset = $this->assetFor($payload, '/storage/geoflow-payload-test/does-not-exist.jpg');
        $this->assertArrayNotHasKey('content_base64', $asset);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => ($context['skip_reason'] ?? null) === 'file_unreadable'
                && ($context['source_url'] ?? null) === '/storage/geoflow-payload-test/does-not-exist.jpg',
        );
    }

    public function test_remote_image_without_local_file_is_not_logged(): void
    {
        Log::spy();

        $payload = $this->buildPayload("正文。\n\n![外链图](https://cdn.example.com/image.jpg)");

        $asset = $this->assetFor($payload, 'https://cdn.example.com/image.jpg');
        $this->assertArrayNotHasKey('content_base64', $asset);
        Log::shouldNotHaveReceived('warning');
    }

    /** @return array<string,mixed> */
    private function buildPayload(string $content): array
    {
        $category = Category::query()->create(['name' => 'Tech', 'slug' => uniqid('payload-category-')]);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $article = Article::query()->create([
            'title' => 'Payload Article',
            'slug' => uniqid('payload-article-'),
            'content' => $content,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        return app(DistributionPayloadBuilder::class)->build($article);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function assetFor(array $payload, string $sourceUrl): array
    {
        foreach ((array) data_get($payload, 'assets.images', []) as $asset) {
            if (is_array($asset) && (string) ($asset['source_url'] ?? '') === $sourceUrl) {
                return $asset;
            }
        }

        $this->fail('Payload is missing image asset for '.$sourceUrl);
    }
}
