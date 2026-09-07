<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\WebsitePublicationReceipt;
use App\Services\GeoFlow\WordPressRestRequestFactory;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 官网发布回读：检测 WordPress 渠道草稿转正后，自动完成在线回读并提交官网回执。
 * 只有「公开 URL 匿名 200」且「线上内容回读哈希 = 母稿哈希」时才提交，否则跳过等待下轮。
 */
final readonly class WebsitePublicationReadbackService
{
    public function __construct(
        private WordPressRestRequestFactory $wpRequests,
        private SafeOutboundHttpClient $safeHttp,
        private SelfMediaSourceHasher $hasher,
        private WebsitePublicationReceiptService $receipts,
    ) {}

    /**
     * 对一条已同步的 WordPress 分发记录尝试回执提交。
     * 返回 null 表示未就绪（草稿/不一致/远端异常），调用方下轮再试。
     */
    public function attemptReceipt(ArticleDistribution $distribution): ?WebsitePublicationReceipt
    {
        $channel = $distribution->channel;
        if (! $channel instanceof DistributionChannel
            || (string) $channel->channel_type !== 'wordpress_rest'
            || (string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
            return null;
        }

        $article = Article::query()->find((int) $distribution->article_id);
        if (! $article instanceof Article || (string) $article->status !== 'published') {
            return null;
        }

        $sourceHash = $this->hasher->hash($article);
        $existing = WebsitePublicationReceipt::query()
            ->where('article_id', (int) $article->id)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof WebsitePublicationReceipt) {
            return $existing;
        }

        $remoteId = trim((string) ($distribution->remote_id ?? ''));
        if ($remoteId === '') {
            return null;
        }

        $post = $this->fetchRemotePost($channel, $remoteId, (int) $article->id);
        if ($post === null || (string) ($post['status'] ?? '') !== 'publish') {
            return null;
        }

        $formalUrl = trim((string) ($distribution->remote_url ?? '')) !== ''
            ? trim((string) $distribution->remote_url)
            : trim((string) data_get($post, 'link', ''));
        if ($formalUrl === '' || ! $this->publiclyReadable($formalUrl, (int) $article->id)) {
            return null;
        }

        $readbackHash = $this->hasher->hashNormalized($this->hasher->normalizeReadback(
            (string) data_get($post, 'title.raw', data_get($post, 'title.rendered', '')),
            (string) data_get($post, 'excerpt.raw', data_get($post, 'excerpt.rendered', '')),
            (string) data_get($post, 'content.raw', data_get($post, 'content.rendered', '')),
        ));

        if (! hash_equals($sourceHash, $readbackHash)) {
            Log::warning('website-publication-readback: hash mismatch, skip receipt', [
                'article_id' => (int) $article->id,
                'distribution_id' => (int) $distribution->id,
                'source_hash' => $sourceHash,
                'readback_hash' => $readbackHash,
            ]);

            return null;
        }

        $actor = $this->resolveActor($article);
        if (! $actor instanceof Admin) {
            Log::warning('website-publication-readback: no active admin actor, skip', [
                'article_id' => (int) $article->id,
            ]);

            return null;
        }

        return $this->receipts->record($article, [
            'receipt_id' => 'HJ-WEB-WPAUTO-'.(int) $distribution->id,
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => $formalUrl,
            'http_status' => 200,
            'source_hash' => $sourceHash,
            'readback_hash' => $readbackHash,
            'verified_at' => now()->toIso8601String(),
        ], $actor);
    }

    /** @return array<string,mixed>|null */
    private function fetchRemotePost(DistributionChannel $channel, string $remoteId, int $articleId): ?array
    {
        try {
            $base = rtrim((string) $channel->endpoint_url, '/');
            $response = $this->wpRequests->request($channel, 15)
                ->get($base.'/wp-json/wp/v2/posts/'.rawurlencode($remoteId), [
                    'context' => 'edit',
                    '_fields' => 'status,link,title,excerpt,content',
                ]);
            if (! $response->successful()) {
                return null;
            }
            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $exception) {
            Log::warning('website-publication-readback: remote fetch failed', [
                'article_id' => $articleId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function publiclyReadable(string $url, int $articleId): bool
    {
        try {
            $response = $this->safeHttp->get(
                Http::timeout(10)->connectTimeout(5),
                $url,
                256 * 1024,
            );

            return $response->successful();
        } catch (Throwable $exception) {
            Log::warning('website-publication-readback: public url check failed', [
                'article_id' => $articleId,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveActor(Article $article): ?Admin
    {
        $taskAdminId = (int) ($article->task?->model_access_admin_id ?? 0);
        if ($taskAdminId > 0) {
            $admin = Admin::query()
                ->whereKey($taskAdminId)
                ->where('status', 'active')
                ->first();
            if ($admin instanceof Admin) {
                return $admin;
            }
        }

        return Admin::query()
            ->where('role', 'super_admin')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }
}
