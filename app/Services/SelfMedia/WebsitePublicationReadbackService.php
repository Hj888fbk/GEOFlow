<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\WebsitePublicationReceipt;
use App\Services\GeoFlow\WordPressRestRequestFactory;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\OutboundRequestBlockedException;
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
            // A receipt may have been created by an older worker before the
            // canonical URL backfill was introduced. Reconcile the linked
            // distribution from the already verified receipt as well.
            $verifiedUrl = trim((string) ($existing->formal_url ?? ''));
            if ($verifiedUrl !== '' && (string) $distribution->remote_url !== $verifiedUrl) {
                $distribution->forceFill([
                    'remote_url' => $verifiedUrl,
                    'remote_meta' => array_replace(
                        is_array($distribution->remote_meta) ? $distribution->remote_meta : [],
                        ['canonical_url' => $verifiedUrl],
                    ),
                ])->save();
            }

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
        $readbackUrl = $formalUrl === '' ? null : $this->publiclyReadable($formalUrl, (int) $article->id);
        if ($readbackUrl === null) {
            return null;
        }
        // WordPress may return a legacy/random slug that redirects to its
        // canonical permalink. Persist the final same-origin URL so the
        // publication center and subsequent syncs do not keep exposing the
        // stale link.
        $formalUrl = $readbackUrl;

        // Keep the distribution record aligned with the URL that was actually
        // returned by the public site. WordPress can rewrite a submitted slug
        // (for example to a translated or title-based permalink); storing only
        // the receipt URL left the distribution table and later reconciliation
        // pointing at the obsolete link.
        if ((string) $distribution->remote_url !== $formalUrl) {
            $distribution->forceFill([
                'remote_url' => $formalUrl,
                'remote_meta' => array_replace(
                    is_array($distribution->remote_meta) ? $distribution->remote_meta : [],
                    ['canonical_url' => $formalUrl],
                ),
            ])->save();
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

    /**
     * 补偿轮询最近的官网分发记录。
     *
     * 定时调度器暂停、升级重启或旧记录由 update 产生时，单靠后台
     * schedule 可能让发布中心长期看不到回读结果。该方法只读取已存在
     * 的 WordPress 分发，不创建新分发，也不会改变远端内容；失败项留给
     * 下一轮继续处理。
     */
    public function pollPending(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $candidates = ArticleDistribution::query()
            ->with(['channel', 'article.task'])
            ->whereIn('action', ['publish', 'update'])
            ->whereIn('status', ['synced', 'outcome_unknown'])
            ->where(function ($query): void {
                $query->where(function ($remote): void {
                    $remote->whereNotNull('remote_id')->where('remote_id', '!=', '');
                })->orWhere(function ($remote): void {
                    $remote->whereNotNull('remote_url')->where('remote_url', '!=', '');
                });
            })
            ->whereHas('channel', function ($query): void {
                $query->where('channel_type', 'wordpress_rest')
                    ->where('status', DistributionChannel::STATUS_ACTIVE);
            })
            ->orderByDesc('updated_at')
            ->limit($limit * 3)
            ->get()
            ->unique('article_id')
            ->take($limit);

        $submitted = 0;
        foreach ($candidates as $distribution) {
            try {
                if ($this->attemptReceipt($distribution) instanceof WebsitePublicationReceipt) {
                    $submitted++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $submitted;
    }

    /** @return array<string,mixed>|null */
    private function fetchRemotePost(DistributionChannel $channel, string $remoteId, int $articleId): ?array
    {
        try {
            // 统一使用渠道模型的 REST 基址。直接拼接 endpoint_url 会在
            // endpoint 已包含 /wp-json 时产生 /wp-json/wp-json/...，回读
            // 因此永远拿到 404。
            $base = $channel->wordpressRestBaseUrl();
            $response = $this->wpRequests->request($channel, 15)
                ->get($base.'/wp/v2/posts/'.rawurlencode($remoteId), [
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

    private function publiclyReadable(string $url, int $articleId): ?string
    {
        $url = trim($url);
        $originHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($originHost === '') {
            return null;
        }
        $resolvedUrl = $url;
        try {
            $response = $this->safeHttp->get(
                Http::timeout(10)->connectTimeout(5),
                $url,
                256 * 1024,
                3,
                [],
                static function (string $redirectUrl) use (&$resolvedUrl, $originHost): void {
                    $redirectHost = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
                    if ($redirectHost === '' || ! hash_equals($originHost, $redirectHost)) {
                        throw new OutboundRequestBlockedException('public_url_redirect_origin_changed');
                    }
                    $resolvedUrl = $redirectUrl;
                },
            );

            return $response->successful() ? $resolvedUrl : null;
        } catch (Throwable $exception) {
            Log::warning('website-publication-readback: public url check failed', [
                'article_id' => $articleId,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return null;
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
