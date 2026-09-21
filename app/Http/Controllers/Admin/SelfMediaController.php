<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateSelfMediaBatchJob;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\SelfMediaPolicy;
use App\Models\Task;
use App\Models\WebsitePublicationReceipt;
use App\Services\BrowserOperations\PublicationPayloadBuilder;
use App\Services\GeoFlow\ManualPublicationService;
use App\Services\SelfMedia\ManualPublicationLifecycleService;
use App\Services\SelfMedia\PortableArticleDocumentService;
use App\Services\SelfMedia\SelfMediaBatchService;
use App\Services\SelfMedia\SelfMediaFactConstraintGuard;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReceiptService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SelfMediaController extends Controller
{
    public function redirectIndex(): RedirectResponse
    {
        return redirect()->route('admin.manual-publications.index', ['view' => 'launch']);
    }

    public function index(SelfMediaSourceHasher $hasher): View
    {
        $receipts = WebsitePublicationReceipt::query()
            ->with('article:id,title,slug,content,excerpt,task_id')
            ->where('readback_succeeded', true)
            ->latest('verified_at')
            ->limit(100)
            ->get()
            ->filter(static fn (WebsitePublicationReceipt $receipt): bool => $receipt->article !== null
                && $receipt->isVerifiedFor($hasher->hash($receipt->article)));

        return view('admin.self-media.index', [
            'pageTitle' => '按需自媒体发布',
            'activeMenu' => 'publishing',
            'adminSiteName' => AdminWeb::siteName(),
            'eligibleReceipts' => $receipts,
            'batches' => ManualPublicationBatch::query()->with(['article:id,title', 'publications:id,manual_publication_batch_id,platform,status,source_stale_at'])->latest()->paginate(30),
            'tasks' => Task::query()->with('selfMediaPolicy')->orderBy('name')->get(['id', 'name', 'status']),
            'platforms' => ManualPublicationAccount::DRAFT_SYNC_PLATFORMS,
            'intents' => SelfMediaPlatformRouter::INTENTS,
            'counts' => [
                'eligible' => $receipts->count(),
                'candidates' => $receipts->filter(fn ($receipt): bool => ! ManualPublicationBatch::query()->where('website_publication_receipt_id', $receipt->id)->exists())->count(),
                'batches' => ManualPublicationBatch::query()->count(),
                'work_orders' => ManualPublication::query()->whereNotNull('manual_publication_batch_id')->count(),
            ],
        ]);
    }

    public function storeBatch(Request $request, SelfMediaBatchService $batches): RedirectResponse
    {
        $data = $request->validate([
            'website_publication_receipt_id' => ['required', 'integer', Rule::exists('website_publication_receipts', 'id')],
            'content_intent' => ['required', Rule::in(SelfMediaPlatformRouter::INTENTS)],
            'platforms' => ['required', 'array', 'min:1', 'max:'.count(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)],
            'platforms.*' => ['required', Rule::in(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)],
        ]);
        $receipt = WebsitePublicationReceipt::query()->with('article')->findOrFail((int) $data['website_publication_receipt_id']);
        try {
            $batches->createManual($receipt->article, $receipt, (string) $data['content_intent'], $data['platforms'], $request->user('admin'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['self_media' => $exception->getMessage()]);
        }

        return back()->with('message', '已加入自媒体计划，尚未调用模型。');
    }

    public function launch(Request $request, SelfMediaBatchService $batches): RedirectResponse
    {
        $data = $request->validate([
            'website_publication_receipt_id' => ['required', 'integer', Rule::exists('website_publication_receipts', 'id')],
            'persona_id' => ['required', 'integer', Rule::exists('manual_publication_personas', 'id')->where('is_active', true)],
            'content_intent' => ['required', Rule::in(SelfMediaPlatformRouter::INTENTS)],
            'account_ids' => ['required', 'array', 'min:1', 'max:100'],
            'account_ids.*' => ['required', 'integer', 'distinct', Rule::exists('manual_publication_accounts', 'id')->where('is_active', true)],
        ]);
        $receipt = WebsitePublicationReceipt::query()->with('article')->findOrFail((int) $data['website_publication_receipt_id']);
        abort_unless($receipt->article instanceof Article, 404);

        try {
            $batch = $batches->createManual(
                $receipt->article,
                $receipt,
                (string) $data['content_intent'],
                [],
                $request->user('admin'),
                (int) $data['persona_id'],
                array_map('intval', $data['account_ids']),
            );
            GenerateSelfMediaBatchJob::dispatch((int) $batch->id);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['self_media' => $exception->getMessage()]);
        }

        $request->attributes->set('admin_activity_target_type', 'manual_publication_batch');
        $request->attributes->set('admin_activity_target_id', (int) $batch->id);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'manual_publication_batch.launched', [
            'persona_id' => (int) $batch->persona_id,
            'account_ids' => array_values((array) $batch->target_account_ids),
        ]);

        return redirect()
            ->route('admin.manual-publications.index', ['view' => 'pending', 'batch' => $batch->id])
            ->with('message', '发布任务已创建并进入生成队列。');
    }

    public function storeReceipt(Request $request, WebsitePublicationReceiptService $receipts): RedirectResponse
    {
        $data = $request->validate([
            'article_id' => ['required', 'integer', Rule::exists('articles', 'id')],
            'formal_url' => ['required', 'url:http,https', 'max:1000'],
            'http_status' => ['required', 'integer', 'in:200'],
            'source_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'readback_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'responsible_project_id' => ['required', 'in:HJ-WEB'],
            'receipt_id' => ['nullable', 'string', 'max:255'],
            'verified_at' => ['required', 'date'],
        ]);
        try {
            $receipts->record(Article::query()->findOrFail((int) $data['article_id']), $data, $request->user('admin'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['website_receipt' => $exception->getMessage()]);
        }

        return back()->with('message', '官网上线回执已保存。');
    }

    public function generate(int $batchId): RedirectResponse
    {
        $batch = ManualPublicationBatch::query()->findOrFail($batchId);
        if (! in_array($batch->status, [ManualPublicationBatch::STATUS_PLANNED, ManualPublicationBatch::STATUS_FAILED], true)
            || $batch->invalidated_at !== null) {
            throw ValidationException::withMessages(['generation' => '当前批次不能进入生成队列。']);
        }
        try {
            GenerateSelfMediaBatchJob::dispatch((int) $batch->id);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['generation' => $exception->getMessage()]);
        }

        return back()->with('message', '批次已进入专用生成队列；完成后进入 GEOFlow 审核。');
    }

    public function approve(int $batchId, Request $request, ManualPublicationService $service): RedirectResponse
    {
        try {
            DB::transaction(function () use ($batchId, $request, $service): void {
                $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
                if ($batch->status !== ManualPublicationBatch::STATUS_PENDING_REVIEW || $batch->invalidated_at !== null) {
                    throw new DomainException('当前批次不能提交平台处理。');
                }

                $publications = $batch->publications()->with('account')->lockForUpdate()->get();
                if ($publications->isEmpty()) {
                    throw new DomainException('当前批次没有可审核的平台工作单。');
                }
                $selectedAccountIds = array_values(array_unique(array_map('intval', (array) $batch->target_account_ids)));
                $allowsLegacyAccountFallback = $selectedAccountIds === [];

                $resolvedAccounts = [];
                $readinessProblems = [];
                foreach ($publications as $publication) {
                    $account = $publication->account;
                    if (! $allowsLegacyAccountFallback
                        && (! $account instanceof ManualPublicationAccount
                            || ! in_array((int) $account->id, $selectedAccountIds, true))) {
                        throw new DomainException('平台 '.$publication->platform.' 的所选账号与批次不一致，请重新发起发布。');
                    }
                    if ($allowsLegacyAccountFallback && ! $this->isUsableBrowserAccount($account, $publication)) {
                        $account = ManualPublicationAccount::query()
                            ->where('persona_id', $publication->persona_id)
                            ->where('platform', $publication->platform)
                            ->where('is_active', true)
                            ->where('browser_adapter_enabled', true)
                            ->whereNotNull('editor_url')
                            ->oldest('id')
                            ->first();
                    }
                    $blockers = $this->browserAccountBlockers($account, $publication);
                    if ($blockers !== []) {
                        $readinessProblems[] = '平台 '.$publication->platform.'（'.implode('、', $blockers).'）';

                        continue;
                    }
                    $resolvedAccounts[(int) $publication->id] = $account;
                }
                if ($readinessProblems !== []) {
                    throw new DomainException('以下平台账号尚未就绪，请先在账号中心补齐后再提交平台处理：'.implode('；', $readinessProblems).'。');
                }

                foreach ($publications as $publication) {
                    $account = $resolvedAccounts[(int) $publication->id];
                    $publication = $service->update($publication, [
                        'type' => $publication->type,
                        'article_id' => $publication->article_id,
                        'persona_id' => $publication->persona_id,
                        'account_id' => $account->id,
                        'assigned_admin_id' => $publication->assigned_admin_id,
                        'platform' => $publication->platform,
                        'custom_platform' => $publication->custom_platform,
                        'target_url' => $account->editor_url,
                        'target_context' => $publication->target_context,
                        'content' => $publication->content,
                        'scheduled_at' => $publication->scheduled_at,
                        'manual_publication_batch_id' => $publication->manual_publication_batch_id,
                        'platform_title' => $publication->platform_title,
                        'platform_summary' => $publication->platform_summary,
                        'body_markdown' => $publication->body_markdown,
                        'body_html' => $publication->body_html,
                        'document_schema_version' => $publication->document_schema_version,
                        'portable_document' => $publication->portable_document,
                        'render_fingerprint' => $publication->render_fingerprint,
                        'content_type' => $publication->content_type,
                        'tags' => $publication->tags,
                        'media_manifest' => $publication->media_manifest,
                        'source_hash' => $publication->source_hash,
                        'source_snapshot' => $publication->source_snapshot,
                    ], (int) $publication->revision);
                    $service->transition($publication, ManualPublication::STATUS_READY, (int) $publication->revision, $request->user('admin'));
                }

                $batch->forceFill([
                    'status' => ManualPublicationBatch::STATUS_PENDING_PLATFORM,
                    'revision' => (int) $batch->revision + 1,
                ])->save();
            });
        } catch (DomainException|ManualPublicationConflictException $exception) {
            throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
        }

        $request->attributes->set('admin_activity_target_type', 'manual_publication_batch');
        $request->attributes->set('admin_activity_target_id', $batchId);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'manual_publication_batch.approved');

        return back()->with('message', '审核通过，工作单已进入平台处理队列。');
    }

    public function updateDraft(
        int $batchId,
        int $manualPublicationId,
        Request $request,
        ManualPublicationService $service,
        PortableArticleDocumentService $portableDocuments,
        SelfMediaFactConstraintGuard $factGuard,
        PublicationPayloadBuilder $payloadBuilder,
    ): RedirectResponse {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'platform_title' => ['required', 'string', 'max:500'],
            'platform_summary' => ['nullable', 'string', 'max:2000'],
            'body_markdown' => ['required', 'string', 'max:100000'],
        ]);

        try {
            DB::transaction(function () use ($batchId, $manualPublicationId, $data, $service, $portableDocuments, $factGuard, $payloadBuilder): void {
                $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
                if ($batch->status !== ManualPublicationBatch::STATUS_PENDING_REVIEW || $batch->invalidated_at !== null) {
                    throw new DomainException('只有待审核批次可以修改平台稿。');
                }
                $publication = $batch->publications()->whereKey($manualPublicationId)->lockForUpdate()->firstOrFail();
                if ($publication->status !== ManualPublication::STATUS_DRAFT) {
                    throw new DomainException('该平台稿已经交给发布助手，不能继续修改。');
                }
                $document = $portableDocuments->build(
                    (string) $data['platform_title'],
                    (string) $data['body_markdown'],
                    array_values((array) $publication->media_manifest),
                    (string) $publication->platform,
                );
                $variant = [
                    'title' => (string) $data['platform_title'],
                    'summary' => (string) ($data['platform_summary'] ?? ''),
                    'body_plain' => (string) $document['plain_text'],
                    'body_markdown' => (string) $document['markdown'],
                    'body_html' => (string) $document['html'],
                    'tags' => array_values((array) $publication->tags),
                ];
                $factGuard->assertVariant($batch, $variant);
                $updated = $service->update($publication, [
                    'type' => $publication->type,
                    'article_id' => $publication->article_id,
                    'persona_id' => $publication->persona_id,
                    'account_id' => $publication->account_id,
                    'assigned_admin_id' => $publication->assigned_admin_id,
                    'platform' => $publication->platform,
                    'custom_platform' => $publication->custom_platform,
                    'target_url' => $publication->target_url,
                    'target_context' => $publication->target_context,
                    'content' => $variant['body_plain'],
                    'scheduled_at' => $publication->scheduled_at,
                    'manual_publication_batch_id' => $publication->manual_publication_batch_id,
                    'platform_title' => $variant['title'],
                    'platform_summary' => $variant['summary'],
                    'body_markdown' => $variant['body_markdown'],
                    'body_html' => $variant['body_html'],
                    'document_schema_version' => (string) $document['schema_version'],
                    'portable_document' => $document,
                    'render_fingerprint' => (array) $document['render_fingerprint'],
                    'content_type' => $publication->content_type,
                    'tags' => $publication->tags,
                    'media_manifest' => $publication->media_manifest,
                    'source_hash' => $publication->source_hash,
                    'source_snapshot' => $publication->source_snapshot,
                ], (int) $data['revision']);
                $updated->forceFill([
                    'publication_payload' => $payloadBuilder->build(array_merge($updated->getAttributes(), [
                        'source_snapshot' => $updated->source_snapshot,
                        'identity_snapshot' => $updated->identity_snapshot,
                        'tags' => $updated->tags,
                        'media_manifest' => $updated->media_manifest,
                        'portable_document' => $updated->portable_document,
                        'render_fingerprint' => $updated->render_fingerprint,
                    ])),
                ])->save();
            }, 3);
        } catch (DomainException|ManualPublicationConflictException $exception) {
            throw ValidationException::withMessages(['draft' => $exception->getMessage()]);
        }

        $request->attributes->set('admin_activity_target_type', 'manual_publication');
        $request->attributes->set('admin_activity_target_id', $manualPublicationId);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'manual_publication_batch.draft_updated', [
            'batch_id' => $batchId,
        ]);

        return back()->with('message', '平台稿已更新，可继续批量审核。');
    }

    public function trash(int $batchId, Request $request, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->trashBatch($batchId);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['trash' => $exception->getMessage()]);
        }
        $this->auditLifecycle($request, 'manual_publication_batch.trashed', $batchId);

        return back()->with('message', '发布任务已移入回收站，将保留 30 天。');
    }

    public function restore(int $batchId, Request $request, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->restoreBatch($batchId);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['restore' => $exception->getMessage()]);
        }
        $this->auditLifecycle($request, 'manual_publication_batch.restored', $batchId);

        return back()->with('message', '发布任务及其账号进度已恢复。');
    }

    public function archive(int $batchId, Request $request, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->archiveBatch($batchId);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['archive' => $exception->getMessage()]);
        }
        $this->auditLifecycle($request, 'manual_publication_batch.archived', $batchId);

        return back()->with('message', '已完成发布任务已归档。');
    }

    public function unarchive(int $batchId, Request $request, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->unarchiveBatch($batchId);
        $this->auditLifecycle($request, 'manual_publication_batch.unarchived', $batchId);

        return back()->with('message', '发布任务已取消归档。');
    }

    public function cancel(int $batchId, Request $request, ManualPublicationService $service): RedirectResponse
    {
        try {
            DB::transaction(function () use ($batchId, $request, $service): void {
                $batch = ManualPublicationBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
                foreach ($batch->publications()->lockForUpdate()->get() as $publication) {
                    if ($publication->canTransitionTo(ManualPublication::STATUS_CANCELLED)) {
                        $service->transition($publication, ManualPublication::STATUS_CANCELLED, (int) $publication->revision, $request->user('admin'));
                    }
                }
                $batch->forceFill([
                    'status' => ManualPublicationBatch::STATUS_CANCELLED,
                    'execution_lease_token' => null,
                    'lease_expires_at' => null,
                    'revision' => (int) $batch->revision + 1,
                ])->save();
            });
        } catch (DomainException|ManualPublicationConflictException $exception) {
            throw ValidationException::withMessages(['cancellation' => $exception->getMessage()]);
        }

        return back()->with('message', '批次已取消，审计记录保留。');
    }

    public function savePolicy(int $taskId, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'content_intent' => ['required', Rule::in(SelfMediaPlatformRouter::INTENTS)],
            'platform_override' => ['nullable', 'array', 'max:'.count(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)],
            'platform_override.*' => ['required', Rule::in(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)],
            'daily_source_limit' => ['required', 'integer', 'in:1'],
            'pending_batch_limit' => ['required', 'integer', 'between:1,2'],
        ]);
        Task::query()->findOrFail($taskId)->selfMediaPolicy()->updateOrCreate([], [
            'enabled' => $request->boolean('enabled'),
            'content_intent' => (string) $data['content_intent'],
            'platform_override' => array_values((array) ($data['platform_override'] ?? [])) ?: null,
            'routing_version' => SelfMediaPolicy::ROUTING_VERSION,
            'daily_source_limit' => 1,
            'pending_batch_limit' => (int) $data['pending_batch_limit'],
        ]);

        return back()->with('message', $request->boolean('enabled') ? '自动自媒体策略已启用。' : '自动自媒体策略已关闭，官网任务不受影响。');
    }

    private function isUsableBrowserAccount(?ManualPublicationAccount $account, ManualPublication $publication): bool
    {
        return $account instanceof ManualPublicationAccount
            && (int) $account->persona_id === (int) $publication->persona_id
            && $account->platform === $publication->platform
            && $account->is_active
            && $account->browser_adapter_enabled
            && trim((string) $account->editor_url) !== ''
            && (trim((string) $account->profile_url) !== ''
                || trim((string) $account->account_uid) !== ''
                || trim((string) $account->homepage_identifier) !== '');
    }

    /** @return list<string> */
    private function browserAccountBlockers(?ManualPublicationAccount $account, ManualPublication $publication): array
    {
        if ($account instanceof ManualPublicationAccount
            && (int) $account->persona_id === (int) $publication->persona_id
            && $account->platform === $publication->platform) {
            return $this->accountReadinessGaps($account);
        }

        $candidates = ManualPublicationAccount::query()
            ->where('persona_id', $publication->persona_id)
            ->where('platform', $publication->platform)
            ->oldest('id')
            ->get();
        if ($candidates->isEmpty()) {
            return ['尚未配置平台账号'];
        }

        return $candidates
            ->map(fn (ManualPublicationAccount $candidate): array => $this->accountReadinessGaps($candidate))
            ->sortBy(fn (array $gaps): int => count($gaps))
            ->first();
    }

    /** @return list<string> */
    private function accountReadinessGaps(ManualPublicationAccount $account): array
    {
        $gaps = [];
        if (! $account->is_active) {
            $gaps[] = '账号未激活';
        }
        if (! $account->browser_adapter_enabled) {
            $gaps[] = '未启用浏览器适配器';
        }
        if (trim((string) $account->editor_url) === '') {
            $gaps[] = '缺少编辑页 URL';
        }
        if (trim((string) $account->profile_url) === ''
            && trim((string) $account->account_uid) === ''
            && trim((string) $account->homepage_identifier) === '') {
            $gaps[] = '缺少账号身份标识';
        }

        return $gaps;
    }

    private function auditLifecycle(Request $request, string $action, int $batchId): void
    {
        $request->attributes->set('admin_activity_target_type', 'manual_publication_batch');
        $request->attributes->set('admin_activity_target_id', $batchId);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), $action);
    }
}
