<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Support\GeoFlow\KeywordNormalizer;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WordPressRestPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly WordPressRestRequestFactory $requestFactory,
        private readonly WordPressMediaSyncService $mediaSyncService,
        private readonly WordPressTaxonomySyncService $taxonomySyncService,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $indexResponse = $this->requestFactory->request($channel, 10)->get($channel->wordpressRestBaseUrl());
        $this->throwIfFailed($indexResponse, 'WordPress REST 入口检测');

        $response = $this->requestFactory->request($channel, 10)
            ->get($channel->wordpressRestBaseUrl().'/wp/v2/users/me', ['context' => 'edit']);
        if ($response->failed()) {
            if ($this->currentUserRouteUnavailable($response)) {
                return $this->healthFromAuthenticatedEditContext($channel);
            }

            $this->throwIfFailed($response, 'WordPress 健康检查');
        }
        $user = $response->json();
        if (! is_array($user)) {
            $user = [];
        }
        $capabilities = is_array($user['capabilities'] ?? null) ? $user['capabilities'] : [];

        return [
            'ok' => true,
            'channel_type' => 'wordpress_rest',
            'rest_base_url' => $channel->wordpressRestBaseUrl(),
            'user_id' => (int) ($user['id'] ?? 0),
            'user_name' => (string) ($user['name'] ?? ''),
            'can_edit_posts' => (bool) ($capabilities['edit_posts'] ?? false),
            'can_publish_posts' => (bool) ($capabilities['publish_posts'] ?? false),
            'can_upload_files' => (bool) ($capabilities['upload_files'] ?? false),
            'capability_source' => 'wp_v2_users_me',
        ];
    }

    private function currentUserRouteUnavailable(Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        $json = $response->json();

        return is_array($json) && (string) ($json['code'] ?? '') === 'rest_no_route';
    }

    /**
     * Some hardened WordPress sites intentionally remove the users REST routes.
     * An authenticated edit-context request still distinguishes a working
     * Application Password from anonymous access without changing remote data.
     *
     * @return array<string,mixed>
     */
    private function healthFromAuthenticatedEditContext(DistributionChannel $channel): array
    {
        $restBase = $channel->wordpressRestBaseUrl();
        $probes = [
            'posts' => '/wp/v2/posts',
            'media' => '/wp/v2/media',
            'categories' => '/wp/v2/categories',
        ];

        foreach ($probes as $label => $path) {
            $response = $this->requestFactory->request($channel, 10)->get($restBase.$path, [
                'context' => 'edit',
                'per_page' => 1,
                '_fields' => 'id',
            ]);
            $this->throwIfFailed($response, 'WordPress '.ucfirst($label).' 编辑上下文检查');
        }

        $config = $channel->resolvedChannelConfig();

        return [
            'ok' => true,
            'channel_type' => 'wordpress_rest',
            'rest_base_url' => $restBase,
            'user_id' => 0,
            'user_name' => (string) $config['wordpress_username'],
            'can_edit_posts' => true,
            'can_publish_posts' => null,
            'can_upload_files' => null,
            'capability_source' => 'authenticated_edit_context_fallback',
            'authenticated_edit_contexts' => array_keys($probes),
            'note' => '目标站点禁用了wp/v2/users/me；已通过文章、媒体和分类编辑上下文验证凭据。发布与上传权限仍以实际操作结果为准。',
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $response = $this->requestFactory->request($channel)
            ->withHeaders(['Idempotency-Key' => (string) $distribution->idempotency_key])
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/posts', $this->postPayload($channel, $payload, $distribution));
        $this->throwIfFailed($response, 'WordPress 文章发布');

        return $this->postResult($response, $channel, $article);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return $this->publish($distribution, $payload);
        }

        $response = $this->requestFactory->request($channel)
            ->withHeaders(['Idempotency-Key' => (string) $distribution->idempotency_key])
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, $this->postPayload($channel, $payload, $distribution));
        $this->throwIfFailed($response, 'WordPress 文章更新');

        return $this->postResult($response, $channel, $article);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return [
                'deleted' => true,
                'remote_id' => null,
                'remote_url' => null,
                'message' => 'missing_remote_post_id',
            ];
        }

        $response = $this->requestFactory->request($channel)
            ->delete($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, ['force' => false]);
        $this->throwIfFailed($response, 'WordPress 文章删除');

        return [
            'deleted' => true,
            'remote_id' => (string) $postId,
            'remote_url' => null,
        ];
    }

    public function syncSiteSettings(DistributionChannel $channel, ?string $idempotencyKey = null, ?array $settings = null): array
    {
        $settings ??= $channel->resolvedSiteSettings();
        $payload = [
            'title' => $settings['site_name'],
            'description' => $settings['site_description'],
            'posts_per_page' => $settings['per_page'],
        ];

        $request = $this->requestFactory->request($channel);
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $response = $request
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/settings', $payload);
        $this->throwIfFailed($response, 'WordPress 站点设置同步');

        return [
            'ok' => true,
            'settings' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    public function reconcilePublication(ArticleDistribution $distribution, array $payload): ?array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $slug = trim((string) ($article['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        $response = $this->requestFactory->request($channel, 10)
            ->get($channel->wordpressRestBaseUrl().'/wp/v2/posts', [
                'slug' => $slug,
                'context' => 'edit',
                'per_page' => 1,
                '_fields' => 'id,link,slug,title',
            ]);
        if ($response->failed()) {
            return null;
        }
        $posts = $response->json();
        $post = is_array($posts) && is_array($posts[0] ?? null) ? $posts[0] : null;

        // Some WordPress installations rewrite non-ASCII/random slugs during
        // save. If the exact slug lookup misses, use an exact title match from
        // a small search result set before declaring the remote outcome unknown.
        if (! is_array($post) || (int) ($post['id'] ?? 0) <= 0) {
            $title = trim((string) ($article['title'] ?? ''));
            if ($title !== '') {
                $searchResponse = $this->requestFactory->request($channel, 10)
                    ->get($channel->wordpressRestBaseUrl().'/wp/v2/posts', [
                        'search' => $title,
                        'context' => 'edit',
                        'per_page' => 10,
                        '_fields' => 'id,link,slug,title',
                    ]);
                if ($searchResponse->successful()) {
                    $searchPosts = $searchResponse->json();
                    foreach (is_array($searchPosts) ? $searchPosts : [] as $candidate) {
                        if (! is_array($candidate)) {
                            continue;
                        }
                        $candidateTitle = (string) data_get($candidate, 'title.raw', data_get($candidate, 'title.rendered', ''));
                        if ($candidateTitle !== '' && trim(html_entity_decode(strip_tags($candidateTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $title) {
                            $post = $candidate;
                            break;
                        }
                    }
                }
            }
        }
        if (! is_array($post) || (string) ($post['slug'] ?? '') !== $slug || (int) ($post['id'] ?? 0) <= 0) {
            // A title-based match is valid even when WordPress intentionally
            // rewrote the slug. Preserve the strict slug check for exact
            // matches, but accept the verified title fallback above.
            $matchedByTitle = is_array($post)
                && (int) ($post['id'] ?? 0) > 0
                && trim(html_entity_decode(strip_tags((string) data_get($post, 'title.raw', data_get($post, 'title.rendered', ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === trim((string) ($article['title'] ?? ''));
            if (! $matchedByTitle) {
                return null;
            }
        }

        return [
            'remote_id' => (string) $post['id'],
            'remote_url' => (string) ($post['link'] ?? ''),
            'remote_meta' => [
                'wordpress_post_id' => (int) $post['id'],
                'outcome_reconciled' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function postPayload(DistributionChannel $channel, array $payload, ?ArticleDistribution $distribution = null): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $config = $channel->resolvedChannelConfig();
        $contentHtml = (string) ($article['content_html'] ?? '');
        $featuredMediaId = 0;

        if ($config['wordpress_image_strategy'] === 'upload_to_media') {
            $contentHtml = $this->mediaSyncService->rewriteContentImages($channel, $payload, $contentHtml, $distribution);
            // 正文第一张图同步设为 WP 特色图片（featured_media），
            // 否则列表/卡片全部落回主题默认图，看起来"每篇都配同一张"。
            $uploadedMedia = $this->mediaSyncService->takeLastUploadedMedia();
            $featuredMediaId = (int) ($uploadedMedia[0]['id'] ?? 0);
        }

        $postPayload = [
            'title' => (string) ($article['title'] ?? ''),
            'slug' => (string) ($article['slug'] ?? ''),
            'status' => (string) $config['wordpress_post_status'],
            'content' => $contentHtml,
            'excerpt' => (string) ($article['excerpt'] ?? ''),
        ];

        if ($featuredMediaId > 0) {
            $postPayload['featured_media'] = $featuredMediaId;
        }

        // 注入 Rank Math SEO meta：焦点关键词 + SEO 描述，避免 WP 侧每次手填。
        $rankMathMeta = $this->rankMathMeta($article);
        if ($rankMathMeta !== []) {
            $postPayload['meta'] = $rankMathMeta;
        }

        $categoryIds = $this->taxonomySyncService->categoryIds($channel, $payload);
        if ($categoryIds !== []) {
            $postPayload['categories'] = $categoryIds;
        }

        $tagIds = $this->taxonomySyncService->tagIds($channel, $payload);
        if ($tagIds !== []) {
            $postPayload['tags'] = $tagIds;
        }

        return $postPayload;
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少 WordPress 渠道。');
        }

        return $distribution->channel;
    }

    /**
     * 构造 Rank Math 暴露在 REST API 里的文章 meta 字段。
     *
     * @param  array<string,mixed>  $article
     * @return array<string,string>
     */
    private function rankMathMeta(array $article): array
    {
        $meta = [];

        $focusKeyword = trim((string) ($article['focus_keyword'] ?? ''));
        $keywords = KeywordNormalizer::split((string) ($article['keywords'] ?? ''), $focusKeyword);
        if ($keywords !== []) {
            // Rank Math 支持用逗号分隔多个焦点关键词。之前只发送第一个词，
            // 导致 WordPress 文章始终只显示“法兰”等单个关键词。
            $meta['rank_math_focus_keyword'] = implode(', ', $keywords);
        }

        $title = trim((string) ($article['title'] ?? ''));
        if ($title !== '') {
            $meta['rank_math_title'] = mb_substr($title, 0, 120);
        }

        $description = trim((string) ($article['meta_description'] ?? ''));
        if ($description === '') {
            $description = trim((string) ($article['excerpt'] ?? ''));
        }
        if ($description !== '') {
            $meta['rank_math_description'] = mb_substr($description, 0, 160);
        }

        return $meta;
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException($operation.'失败：HTTP '.$response->status());
    }

    /**
     * @return array<string,mixed>
     */
    private function postResult(Response $response, DistributionChannel $channel, array $article): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('WordPress 返回内容不是有效 JSON。');
        }

        $postId = (int) ($json['id'] ?? 0);

        $remoteMeta = [
            'wordpress_post_id' => $postId,
        ];

        // 媒体映射（源内容 sha256 → WP 媒体）随发布结果并入 remote_meta，
        // 后续 update/重试可直接复用已上传媒体，不重复产生媒体库文件。
        $mediaMap = $this->mediaSyncService->takeMediaMap();
        if ($mediaMap !== []) {
            $remoteMeta['wp_media_map'] = $mediaMap;
        }

        $seoSync = $this->syncRankMathMeta($channel, $postId, $article);
        if ($seoSync !== []) {
            $remoteMeta['rank_math'] = $seoSync;
        }

        return [
            'remote_id' => $postId > 0 ? (string) $postId : '',
            'remote_url' => (string) ($json['link'] ?? ''),
            'remote_meta' => $remoteMeta,
        ];
    }

    /**
     * Rank Math keeps its post meta private from the core REST schema. Its
     * own REST endpoint is the supported way to update it when available. A
     * missing/denied endpoint must not turn an otherwise successful article
     * publication into a failed delivery, so the result is recorded as a
     * diagnostic flag and can be retried on the next update.
     *
     * @param  array<string,mixed>  $article
     * @return array<string,mixed>
     */
    private function syncRankMathMeta(DistributionChannel $channel, int $postId, array $article): array
    {
        if ($postId <= 0) {
            return [];
        }

        $meta = $this->rankMathMeta($article);
        if ($meta === []) {
            return ['status' => 'skipped', 'reason' => 'no_seo_values'];
        }

        try {
            $response = $this->requestFactory->request($channel, 10)
                ->post($channel->wordpressRestBaseUrl().'/rankmath/v1/updateMeta', [
                    'objectType' => 'post',
                    'objectID' => $postId,
                    'meta' => $meta,
                ]);
            if ($response->successful()) {
                return [
                    'status' => 'synced',
                    'endpoint' => 'rankmath/v1/updateMeta',
                    'fields' => array_keys($meta),
                ];
            }

            return [
                'status' => 'unavailable',
                'endpoint' => 'rankmath/v1/updateMeta',
                'http_status' => $response->status(),
                'reason' => 'rank_math_endpoint_rejected',
            ];
        } catch (\Throwable) {
            return [
                'status' => 'unavailable',
                'endpoint' => 'rankmath/v1/updateMeta',
                'reason' => 'rank_math_endpoint_unreachable',
            ];
        }
    }
}
