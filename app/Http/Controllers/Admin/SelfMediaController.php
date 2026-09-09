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
use App\Services\GeoFlow\ManualPublicationService;
use App\Services\SelfMedia\SelfMediaBatchService;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReceiptService;
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
            'platforms' => ['required', 'array', 'min:1', 'max:10'],
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

                foreach ($publications as $publication) {
                    $account = $publication->account;
                    if (! $this->isUsableBrowserAccount($account, $publication)) {
                        $account = ManualPublicationAccount::query()
                            ->where('persona_id', $publication->persona_id)
                            ->where('platform', $publication->platform)
                            ->where('is_active', true)
                            ->where('browser_adapter_enabled', true)
                            ->whereNotNull('editor_url')
                            ->oldest('id')
                            ->first();
                    }
                    if (! $this->isUsableBrowserAccount($account, $publication)) {
                        throw new DomainException('平台 '.$publication->platform.' 尚未配置并启用浏览器适配器。');
                    }

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

        return back()->with('message', '审核通过，工作单已进入平台处理队列。');
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
            'platform_override' => ['nullable', 'array', 'max:10'],
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
}
