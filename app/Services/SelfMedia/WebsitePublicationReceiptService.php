<?php

namespace App\Services\SelfMedia;

use App\Jobs\GenerateSelfMediaBatchJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublicationBatch;
use App\Models\WebsitePublicationReceipt;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class WebsitePublicationReceiptService
{
    public function __construct(
        private SelfMediaSourceHasher $hasher,
        private SelfMediaBatchService $batches,
    ) {}

    /** @param array<string,mixed> $receipt */
    public function record(Article $article, array $receipt, Admin $actor): WebsitePublicationReceipt
    {
        $sourceHash = $this->hasher->hash($article);
        $formalUrl = trim((string) ($receipt['formal_url'] ?? ''));
        $httpStatus = (int) ($receipt['http_status'] ?? 0);
        $reportedSourceHash = strtolower(trim((string) ($receipt['source_hash'] ?? '')));
        $readbackHash = strtolower(trim((string) ($receipt['readback_hash'] ?? '')));
        $responsibleProject = trim((string) ($receipt['responsible_project_id'] ?? ''));

        if (! $this->isSafeHttpUrl($formalUrl)) {
            throw new DomainException('官网回执缺少有效的正式 URL。');
        }
        if ($responsibleProject !== 'HJ-WEB') {
            throw new DomainException('官网回执责任项目必须是 HJ-WEB。');
        }
        if ($httpStatus !== 200 || ! hash_equals($sourceHash, $reportedSourceHash) || ! hash_equals($sourceHash, $readbackHash)) {
            throw new DomainException('官网在线回读未通过，不能进入自媒体计划。');
        }

        $stored = DB::transaction(fn (): WebsitePublicationReceipt => WebsitePublicationReceipt::query()->updateOrCreate(
            ['article_id' => (int) $article->id, 'source_hash' => $sourceHash],
            [
                'responsible_project_id' => $responsibleProject,
                'formal_url' => $formalUrl,
                'http_status' => 200,
                'readback_hash' => $readbackHash,
                'readback_succeeded' => true,
                'verified_at' => $receipt['verified_at'] ?? now(),
                'receipt_payload' => $this->safeReceiptPayload($receipt),
            ],
        ));

        try {
            $batch = $this->batches->createAutomaticIfEnabled($article->fresh() ?? $article, $stored, $actor);
            if ($batch !== null && $batch->status === ManualPublicationBatch::STATUS_PLANNED) {
                GenerateSelfMediaBatchJob::dispatch((int) $batch->id)->afterCommit();
            }
        } catch (DomainException $exception) {
            report($exception);
        }

        return $stored->refresh();
    }

    private function isSafeHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }

    /** @return array<string,mixed> */
    private function safeReceiptPayload(array $receipt): array
    {
        return [
            'receipt_id' => trim((string) ($receipt['receipt_id'] ?? '')) ?: null,
            'responsible_project_id' => 'HJ-WEB',
            'formal_url' => trim((string) ($receipt['formal_url'] ?? '')),
            'http_status' => 200,
            'source_hash' => strtolower(trim((string) ($receipt['source_hash'] ?? ''))),
            'readback_hash' => strtolower(trim((string) ($receipt['readback_hash'] ?? ''))),
            'verified_at' => $receipt['verified_at'] ?? now()->toIso8601String(),
        ];
    }
}
