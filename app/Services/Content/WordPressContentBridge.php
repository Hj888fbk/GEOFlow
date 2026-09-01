<?php

namespace App\Services\Content;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ChannelVariant;
use App\Models\ContentGenerationRun;
use App\Models\ContentMaster;
use App\Models\ContentTask;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Support\Facades\DB;

class WordPressContentBridge
{
    public function __construct(
        private readonly HengjiaContentPackageValidator $validator,
        private readonly ArticleGeoFlowService $articleService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
    ) {}

    /** @return array<string,mixed> */
    public function previewPayload(ContentMaster $master, string $contentType = 'post'): array
    {
        if ($contentType !== 'post') {
            throw new \DomainException('当前 WordPress 发布桥只支持文章；page/product 需官网插件能力验证后再启用。');
        }

        $package = (array) $master->package;
        $seo = (array) ($package['seo'] ?? []);

        return [
            'content_type' => $contentType,
            'title' => (string) ($seo['title'] ?? $master->title),
            'h1' => (string) ($seo['h1'] ?? $master->h1),
            'slug' => (string) ($seo['slug'] ?? $master->slug),
            'excerpt' => (string) ($seo['summary'] ?? $master->summary),
            'meta_description' => (string) ($seo['meta_description'] ?? $master->meta_description),
            'content_markdown' => $this->renderMarkdown($package),
            'faq' => array_values((array) ($package['faq'] ?? [])),
            'sources' => array_values((array) ($package['sources'] ?? [])),
            'internal_links' => array_values((array) ($package['internal_links'] ?? [])),
            'media' => array_values((array) ($package['media'] ?? [])),
            'schema_nodes' => array_values((array) ($package['schema_nodes'] ?? [])),
            'content_package_version' => $master->schema_version,
            'content_package_hash' => $master->package_hash,
            'remote_write' => false,
        ];
    }

    public function approve(ContentMaster $master, Admin $reviewer): ContentMaster
    {
        [$approvedMaster, $valid] = DB::transaction(function () use ($master, $reviewer): array {
            $locked = ContentMaster::query()
                ->with('task')
                ->whereKey($master->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertMasterIntegrity($locked);
            if ($locked->status === ContentMaster::STATUS_APPROVED) {
                return [$locked, true];
            }
            if (! in_array((string) $locked->status, [ContentMaster::STATUS_IN_REVIEW, ContentMaster::STATUS_BLOCKED], true)) {
                throw new \DomainException('内容母版当前状态不能批准。');
            }

            $task = ContentTask::query()->whereKey($locked->content_task_id)->lockForUpdate()->firstOrFail();
            $validation = $this->validator->validate((array) $locked->package, $task);
            if (! $validation['valid']) {
                $blockingIssues = array_values(array_merge($validation['errors'], $validation['blockers']));
                $locked->forceFill([
                    'status' => ContentMaster::STATUS_BLOCKED,
                    'blockers' => $blockingIssues,
                ])->save();
                $task->forceFill([
                    'status' => ContentTask::STATUS_BLOCKED,
                    'review_status' => ContentTask::REVIEW_CHANGES_REQUIRED,
                    'blockers' => $blockingIssues,
                ])->save();

                return [$locked, false];
            }

            $locked->forceFill([
                'status' => ContentMaster::STATUS_APPROVED,
                'blockers' => [],
                'reviewed_by_admin_id' => $reviewer->getKey(),
                'approved_at' => now(),
            ])->save();
            $task->forceFill([
                'status' => ContentTask::STATUS_APPROVED,
                'review_status' => ContentTask::REVIEW_APPROVED,
                'reviewed_by_admin_id' => $reviewer->getKey(),
                'blockers' => [],
            ])->save();

            return [$locked, true];
        });

        if (! $valid) {
            throw new \DomainException('内容包未通过证据与事实门禁。');
        }

        return $approvedMaster->refresh();
    }

    public function promoteToArticle(
        ContentMaster $master,
        Admin $admin,
        int $categoryId,
        int $authorId,
        ?int $taskId = null,
    ): Article {
        $master = $master->fresh('task');
        if (! $master instanceof ContentMaster) {
            throw new \DomainException('内容母版不存在。');
        }
        $this->assertMasterIntegrity($master);
        if ($master->status !== ContentMaster::STATUS_APPROVED) {
            throw new \DomainException('内容母版必须先通过人工审核。');
        }
        $payload = $this->previewPayload($master);
        $existing = $master->article_id ? Article::query()->find((int) $master->article_id) : null;
        $snapshot = $existing ? [
            'created_new' => false,
            'article_id' => $existing->getKey(),
            'title' => $existing->title,
            'slug' => $existing->slug,
            'excerpt' => $existing->excerpt,
            'content' => $existing->content,
            'keywords' => $existing->keywords,
            'meta_description' => $existing->meta_description,
            'category_id' => $existing->category_id,
            'author_id' => $existing->author_id,
            'task_id' => $existing->task_id,
            'status' => $existing->status,
            'review_status' => $existing->review_status,
            'published_at' => $existing->published_at?->toAtomString(),
            'snapshotted_at' => now()->toAtomString(),
        ] : ['created_new' => true, 'snapshotted_at' => now()->toAtomString()];

        $articleData = [
            'title' => $payload['title'],
            'slug' => $payload['slug'],
            'excerpt' => $payload['excerpt'],
            'content' => $payload['content_markdown'],
            'keywords' => implode(',', (array) data_get($master->package, 'seo.secondary_keywords', [])),
            'meta_description' => $payload['meta_description'],
            'category_id' => $categoryId,
            'author_id' => $authorId,
            'task_id' => $taskId ?? $master->task?->task_id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
        ];
        $result = $existing
            ? $this->articleService->updateArticle((int) $existing->getKey(), $articleData, (int) $admin->getKey())
            : $this->articleService->createArticle($articleData, (int) $admin->getKey());
        $article = Article::query()->findOrFail((int) $result['id']);

        DB::transaction(function () use ($article, $master, $snapshot): void {
            $article->forceFill([
                'content_master_id' => $master->getKey(),
                'content_package_version' => $master->schema_version,
                'content_package_hash' => $master->package_hash,
            ])->save();
            $master->forceFill([
                'article_id' => $article->getKey(),
                'article_snapshot' => $snapshot,
                'status' => ContentMaster::STATUS_PROMOTED,
                'promoted_at' => now(),
            ])->save();
        });

        return $article->refresh();
    }

    /** @return list<int> */
    public function queueApprovedVariant(ChannelVariant $variant, Admin $admin): array
    {
        $variant->loadMissing(['master.article', 'distributionChannel']);
        $article = $variant->master?->article;
        if (! $article instanceof Article || ! in_array((string) $article->status, ['published', 'private'], true)) {
            throw new \DomainException('现有 Article 尚未完成审核发布，不能进入 WordPress 分发。');
        }
        if (! $variant->distribution_channel_id) {
            throw new \DomainException('渠道版本未绑定 WordPress 分发渠道。');
        }

        $ids = $this->distributionOrchestrator->enqueueForArticleTargets(
            $article,
            [(int) $variant->distribution_channel_id],
            [
                'approved_by_admin_id' => (int) $admin->getKey(),
                'content_master_id' => (int) $variant->content_master_id,
                'content_package_hash' => (string) $variant->master->package_hash,
            ],
        );
        ArticleDistribution::query()->whereIn('id', $ids)->update(['channel_variant_id' => $variant->getKey()]);
        $variant->forceFill([
            'status' => $ids === [] ? ChannelVariant::STATUS_BLOCKED : ChannelVariant::STATUS_QUEUED,
            'blockers' => $ids === [] ? [[
                'code' => 'wordpress_distribution_not_queued',
                'message' => 'WordPress 分发队列没有返回任务，未执行远端写入。',
            ]] : [],
            'receipt' => [
                'distribution_ids' => $ids,
                'queued_at' => now()->toAtomString(),
                'approved_by_admin_id' => (int) $admin->getKey(),
            ],
        ])->save();

        return $ids;
    }

    /** @return array<string,mixed> */
    public function readback(ChannelVariant $variant): array
    {
        $distribution = ArticleDistribution::query()
            ->where('channel_variant_id', $variant->getKey())
            ->latest('id')
            ->first();
        if (! $distribution) {
            return ['status' => 'not_queued', 'remote_id' => null, 'remote_url' => null, 'field_differences' => []];
        }

        $result = [
            'status' => $distribution->status,
            'remote_id' => $distribution->remote_id,
            'remote_url' => $distribution->remote_url,
            'field_differences' => (array) data_get($distribution->remote_meta, 'field_differences', []),
            'last_error_message' => $distribution->last_error_message,
            'readback_at' => now()->toAtomString(),
        ];
        $variantStatus = match ((string) $distribution->status) {
            'synced' => ChannelVariant::STATUS_PUBLISHED,
            'failed' => ChannelVariant::STATUS_FAILED,
            'cancelled' => ChannelVariant::STATUS_CANCELLED,
            'outcome_unknown' => ChannelVariant::STATUS_OUTCOME_UNKNOWN,
            default => ChannelVariant::STATUS_QUEUED,
        };
        $variant->forceFill([
            'status' => $variantStatus,
            'remote_id' => $distribution->remote_id,
            'remote_url' => $distribution->remote_url,
            'receipt' => array_replace((array) $variant->receipt, $result),
            'last_readback_at' => now(),
        ])->save();

        return $result;
    }

    public function rollbackPromotion(ContentMaster $master, Admin $admin): void
    {
        DB::transaction(function () use ($master, $admin): void {
            $lockedMaster = ContentMaster::query()->whereKey($master->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedMaster->status !== ContentMaster::STATUS_PROMOTED) {
                throw new \DomainException('只有已写入 Article 的内容母版可以回滚。');
            }
            $snapshot = (array) $lockedMaster->article_snapshot;
            $articleId = (int) ($lockedMaster->article_id ?? 0);
            if ($articleId <= 0 || $snapshot === []) {
                throw new \DomainException('没有可回滚的 Article 字段快照。');
            }
            $article = Article::withTrashed()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article instanceof Article || (int) $article->content_master_id !== (int) $lockedMaster->getKey()) {
                throw new \DomainException('Article 当前关联已变化，已停止回滚以避免覆盖其他版本。');
            }
            if (($snapshot['created_new'] ?? false) === true) {
                $article->forceFill([
                    'content_master_id' => null,
                    'content_package_version' => null,
                    'content_package_hash' => null,
                ])->save();
                $this->articleService->trashArticle($articleId);
            } else {
                $this->articleService->updateArticle($articleId, array_intersect_key($snapshot, array_flip([
                    'title', 'slug', 'excerpt', 'content', 'keywords', 'meta_description',
                    'category_id', 'author_id', 'task_id',
                ])), (int) $admin->getKey());
                $article = Article::query()->findOrFail($articleId);
                $article->forceFill([
                    'status' => (string) ($snapshot['status'] ?? 'draft'),
                    'review_status' => (string) ($snapshot['review_status'] ?? 'pending'),
                    'published_at' => $snapshot['published_at'] ?? null,
                    'content_master_id' => null,
                    'content_package_version' => null,
                    'content_package_hash' => null,
                ])->save();
            }
            $lockedMaster->forceFill(['status' => ContentMaster::STATUS_ROLLED_BACK])->save();
        });
    }

    private function assertMasterIntegrity(ContentMaster $master): void
    {
        if ((string) $master->schema_version !== ContentMaster::SCHEMA_VERSION) {
            throw new \DomainException('内容母版版本与当前内容包合同不一致。');
        }
        $calculatedHash = $this->validator->hash((array) $master->package);
        if ((string) $master->package_hash === '' || ! hash_equals((string) $master->package_hash, $calculatedHash)) {
            throw new \DomainException('内容母版哈希校验失败，可能在生成后被修改。');
        }
        $run = ContentGenerationRun::query()
            ->where('content_master_id', $master->getKey())
            ->latest('id')
            ->first();
        if (! $run instanceof ContentGenerationRun
            || (string) $run->output_hash === ''
            || ! hash_equals((string) $run->output_hash, $calculatedHash)) {
            throw new \DomainException('生成运行回执与内容母版哈希不一致。');
        }
    }

    /** @param array<string,mixed> $package */
    private function renderMarkdown(array $package): string
    {
        $lines = [];
        foreach ((array) ($package['body_sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $lines[] = '## '.trim((string) ($section['heading'] ?? ''));
            $lines[] = trim((string) ($section['body'] ?? ''));
            $lines[] = '> 适用范围：'.trim((string) ($section['applicability'] ?? '待确认'));
        }
        $parameters = array_values((array) ($package['parameters'] ?? []));
        if ($parameters !== []) {
            $lines[] = '## 参数与询价确认项';
            $lines[] = '| 参数 | 值 | 单位 | 适用范围 |';
            $lines[] = '|---|---|---|---|';
            foreach ($parameters as $row) {
                if (is_array($row)) {
                    $lines[] = '| '.$this->cell($row['name'] ?? '').' | '.$this->cell($row['value'] ?? '').' | '.$this->cell($row['unit'] ?? '').' | '.$this->cell($row['applicability'] ?? '').' |';
                }
            }
        }
        $applications = array_values((array) ($package['applications'] ?? []));
        if ($applications !== []) {
            $lines[] = '## 适用与不适用场景';
            foreach ($applications as $row) {
                if (is_array($row)) {
                    $lines[] = '- **'.trim((string) ($row['scenario'] ?? '')).'**（'.trim((string) ($row['suitability'] ?? '')).'）：'.trim((string) ($row['conditions'] ?? ''));
                }
            }
        }
        $faq = array_values((array) ($package['faq'] ?? []));
        if ($faq !== []) {
            $lines[] = '## 常见问题';
            foreach ($faq as $item) {
                if (is_array($item)) {
                    $lines[] = '### '.trim((string) ($item['question'] ?? ''));
                    $lines[] = trim((string) ($item['answer'] ?? ''));
                }
            }
        }
        $sources = array_values((array) ($package['sources'] ?? []));
        if ($sources !== []) {
            $lines[] = '## 来源与核验边界';
            foreach ($sources as $source) {
                if (is_array($source)) {
                    $lines[] = '- '.trim((string) ($source['source_id'] ?? '')).'：'.trim((string) ($source['title'] ?? '')).'（'.trim((string) ($source['evidence_status'] ?? '')).'）';
                }
            }
        }

        return trim(implode("\n\n", array_filter($lines, static fn (string $line): bool => trim($line) !== '')));
    }

    private function cell(mixed $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], trim((string) $value));
    }
}
