<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateContentMasterRequest;
use App\Http\Requests\Admin\PromoteContentMasterRequest;
use App\Http\Requests\Admin\SaveContentTaskRequest;
use App\Http\Requests\Admin\SaveEvidenceClaimRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\ChannelVariant;
use App\Models\ContentGenerationRun;
use App\Models\ContentMaster;
use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\EvidenceClaim;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;
use App\Models\PromptRecipeVersion;
use App\Models\Task;
use App\Services\Content\ChannelPublicationService;
use App\Services\Content\ChannelVariantBuilder;
use App\Services\Content\HengjiaContentCenterDashboardService;
use App\Services\Content\HengjiaContentGenerationService;
use App\Services\Content\HengjiaContentSourceSynchronizer;
use App\Services\Content\HengjiaDailyContentPlanner;
use App\Services\Content\WordPressContentBridge;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class HengjiaContentCenterController extends Controller
{
    public function today(HengjiaContentCenterDashboardService $dashboard): View
    {
        return view('admin.hengjia-content.native-dashboard', [
            'pageTitle' => __('hengjia_content.module'),
            'activeMenu' => 'hengjia_content',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => 'today',
            'dashboard' => $dashboard->snapshot(),
            'nativeFlowEnabled' => (bool) config('hengjia-content.native_flow.enabled', false),
            'nativeFlowTaskIds' => array_values(array_filter(array_map(
                'intval',
                (array) config('hengjia-content.native_flow.test_task_ids', []),
            ))),
        ]);
    }

    /**
     * 旧恒佳子页只保留兼容链接，实际操作回到 GEOFlow 原生路由。
     */
    public function nativeRedirect(Request $request): RedirectResponse
    {
        $routeName = (string) $request->route()?->getName();
        $target = match ($routeName) {
            'admin.hengjia-content.tasks' => 'admin.tasks.index',
            'admin.hengjia-content.evidence' => 'admin.knowledge-bases.index',
            'admin.hengjia-content.studio', 'admin.hengjia-content.previews' => 'admin.articles.index',
            'admin.hengjia-content.publishing' => 'admin.manual-publications.index',
            'admin.hengjia-content.accounts' => $request->user('admin')?->isSuperAdmin()
                ? 'admin.manual-publications.settings.index'
                : 'admin.manual-publications.index',
            'admin.hengjia-content.governance' => 'admin.ai-prompts',
            default => 'admin.hengjia-content.today',
        };

        return redirect()->route($target)->with('message', '已回到 GEOFlow 原生功能；恒佳驾驶舱不再维护第二套任务、文章、提示词或账号入口。');
    }

    /**
     * 保留旧 POST 路由名以避免书签或旧页面报 404，但不再写平行候选表。
     */
    public function retiredWrite(Request $request): RedirectResponse
    {
        $routeName = (string) $request->route()?->getName();
        $target = match (true) {
            str_contains($routeName, 'evidence'), str_contains($routeName, 'sources') => 'admin.knowledge-bases.index',
            str_contains($routeName, 'accounts') => $request->user('admin')?->isSuperAdmin()
                ? 'admin.manual-publications.settings.index'
                : 'admin.manual-publications.index',
            str_contains($routeName, 'variants') => 'admin.manual-publications.index',
            str_contains($routeName, 'masters'), str_contains($routeName, 'tasks') => 'admin.tasks.index',
            default => 'admin.hengjia-content.today',
        };

        return redirect()->route($target)->withErrors(
            '该旧入口已停止写入。请使用 GEOFlow 原生 Task、KnowledgeBase、Prompt、Article 和分发流程。',
        );
    }

    public function tasks(): View
    {
        return $this->page('tasks', [
            'tasks' => ContentTask::query()->with(['assignee:id,username,display_name', 'masters'])->orderByDesc('due_on')->orderBy('priority')->paginate(30),
            'adapters' => PlatformAdapter::query()->where('status', 'active')->orderBy('id')->get(),
            'accounts' => ManualPublicationAccount::query()->where('is_active', true)->with('adapter:id,key,name')->orderBy('account_name')->get(),
        ]);
    }

    public function storeTask(SaveContentTaskRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $overlap = ContentTask::query()
            ->where('page_role', $data['page_role'])
            ->where('primary_keyword', $data['primary_keyword'])
            ->whereNotIn('status', [ContentTask::STATUS_CANCELLED, ContentTask::STATUS_COMPLETED])
            ->first();
        $blockers = $overlap ? [[
            'code' => 'existing_page_or_task_overlap',
            'message' => __('hengjia_content.messages.task_overlap', ['id' => $overlap->id]),
        ]] : [];
        $hashInput = [
            'product_key' => $data['product_key'],
            'page_role' => $data['page_role'],
            'primary_keyword' => $data['primary_keyword'],
            'target_channels' => $data['target_channels'],
        ];
        $task = ContentTask::query()->create($data + [
            'candidate_key' => null,
            'status' => $blockers === [] ? ContentTask::STATUS_READY : ContentTask::STATUS_BLOCKED,
            'review_status' => ContentTask::REVIEW_PENDING,
            'blockers' => $blockers,
            'input_hash' => hash('sha256', json_encode($hashInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'created_by_admin_id' => $request->user('admin')?->getAuthIdentifier(),
        ]);
        /** @var Admin $admin */
        $admin = $request->user('admin');
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:task_created', [
            'content_task_id' => $task->getKey(),
            'input_hash' => $task->input_hash,
            'status' => $task->status,
        ]);

        return back()->with('message', __('hengjia_content.messages.task_saved'));
    }

    public function planDaily(Request $request, HengjiaDailyContentPlanner $planner): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $tasks = $planner->plan(now(), null, (int) $admin->getKey());
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:daily_candidates_planned', [
            'candidate_count' => $tasks->count(),
            'candidate_ids' => $tasks->modelKeys(),
            'date' => now()->toDateString(),
        ]);

        return back()->with('message', __('hengjia_content.messages.daily_planned', ['count' => $tasks->count()]));
    }

    public function evidence(): View
    {
        return $this->page('evidence', [
            'claims' => EvidenceClaim::query()->with(['task:id,title', 'sourceFile:id,relative_path,sha256,is_approved'])->latest('id')->paginate(30),
            'tasks' => ContentTask::query()->whereNotIn('status', [ContentTask::STATUS_COMPLETED, ContentTask::STATUS_CANCELLED])->orderByDesc('id')->get(['id', 'title']),
            'sourceFiles' => ContentSourceFile::query()->latest('last_synced_at')->limit(100)->get(),
        ]);
    }

    public function storeEvidence(SaveEvidenceClaimRequest $request): RedirectResponse
    {
        $data = $request->validated();
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $claim = DB::transaction(function () use ($data, $admin): EvidenceClaim {
            $source = ! empty($data['content_source_file_id'])
                ? ContentSourceFile::query()->whereKey((int) $data['content_source_file_id'])->lockForUpdate()->first()
                : null;
            if (! empty($data['content_source_file_id']) && ! $source instanceof ContentSourceFile) {
                throw ValidationException::withMessages(['content_source_file_id' => __('hengjia_content.messages.source_missing')]);
            }

            $normalized = $this->normalizeEvidenceForStorage($data, $source, $admin);

            return EvidenceClaim::query()->create([
                'claim_id' => (string) Str::uuid(),
                'content_task_id' => $data['content_task_id'] ?? null,
                'content_source_file_id' => $source?->getKey(),
                'source_id' => $data['source_id'],
                'claim_type' => $data['claim_type'],
                'subject' => $data['subject'],
                'predicate' => $data['predicate'],
                'claim_value' => $data['claim_value'],
                'unit' => $data['unit'] ?? null,
                'scope' => $data['scope'],
                'evidence_status' => $data['evidence_status'],
                'public_permission' => $data['public_permission'],
                'structured_payload' => $normalized['qualification'],
                'official_lookup_url' => $normalized['official_lookup_url'],
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $normalized['valid_until'],
                'reviewed_by_admin_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'conflict_notes' => $data['conflict_notes'] ?? null,
            ]);
        });
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:evidence_claim_recorded', [
            'evidence_claim_id' => $claim->getKey(),
            'claim_id' => $claim->claim_id,
            'source_id' => $claim->source_id,
            'evidence_status' => $claim->evidence_status,
            'public_permission' => $claim->public_permission,
            'input_hash' => $this->hashPayload($data),
        ]);

        return back()->with('message', __('hengjia_content.messages.evidence_saved'));
    }

    public function approveSource(Request $request, ContentSourceFile $sourceFile): RedirectResponse
    {
        $data = $request->validate([
            'evidence_status' => ['required', 'in:'.implode(',', ContentSourceFile::EVIDENCE_STATUSES)],
            'public_permission' => ['required', 'in:'.implode(',', ContentSourceFile::PUBLIC_PERMISSIONS)],
        ]);
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin?->isSuperAdmin(), 403);
        if ($data['public_permission'] === ContentSourceFile::PERMISSION_PUBLISHABLE
            && ! $this->isStrongEvidenceStatus((string) $data['evidence_status'])) {
            throw ValidationException::withMessages([
                'public_permission' => __('hengjia_content.messages.source_publishable_requires_strong_evidence'),
            ]);
        }
        DB::transaction(function () use ($sourceFile, $data, $admin): void {
            $locked = ContentSourceFile::query()->whereKey($sourceFile->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'evidence_status' => $data['evidence_status'],
                'public_permission' => $data['public_permission'],
                'is_approved' => true,
                'metadata' => array_replace((array) $locked->metadata, [
                    'approved_by_admin_id' => $admin->getKey(),
                    'approved_at' => now()->toAtomString(),
                ]),
            ])->save();
        });
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:source_approved', [
            'content_source_file_id' => $sourceFile->getKey(),
            'evidence_status' => $data['evidence_status'],
            'public_permission' => $data['public_permission'],
            'sha256' => $sourceFile->fresh()->sha256,
        ]);

        return back()->with('message', __('hengjia_content.messages.source_approved'));
    }

    public function syncSources(Request $request, HengjiaContentSourceSynchronizer $synchronizer): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin?->isSuperAdmin(), 403);
        $result = $synchronizer->sync();
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:sources_synchronized', [
            'scanned' => $result['scanned'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'failure_count' => count($result['failures']),
        ]);

        return back()->with('message', __('hengjia_content.messages.sources_synced', [
            'scanned' => $result['scanned'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'failed' => count($result['failures']),
        ]));
    }

    public function studio(): View
    {
        return $this->page('studio', [
            'masters' => ContentMaster::query()->with(['task:id,title,status,primary_keyword', 'article:id,title,status,review_status'])->latest('id')->paginate(24),
            'readyTasks' => ContentTask::query()->where('status', ContentTask::STATUS_READY)->orderBy('priority')->get(),
            'aiModels' => AiModel::query()->where('status', 'active')->orderBy('failover_priority')->get(['id', 'name', 'model_id']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'authors' => Author::query()->orderBy('name')->get(['id', 'name']),
            'existingTasks' => Task::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function generate(GenerateContentMasterRequest $request, ContentTask $contentTask, HengjiaContentGenerationService $generation): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $model = AiModel::query()->findOrFail((int) $request->validated('ai_model_id'));
        $master = $generation->generate($contentTask, $model, $admin);
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:master_generated', [
            'content_task_id' => $contentTask->getKey(),
            'content_master_id' => $master->getKey(),
            'ai_model_id' => $model->getKey(),
            'package_hash' => $master->package_hash,
            'status' => $master->status,
        ]);

        return back()->with('message', __('hengjia_content.messages.master_generated', ['id' => $master->id, 'status' => $master->status]));
    }

    public function approveMaster(Request $request, ContentMaster $contentMaster, WordPressContentBridge $bridge, ChannelVariantBuilder $variants): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin?->canManageProtectedWorkflows(), 403);
        $master = $bridge->approve($contentMaster, $admin);
        $builtVariants = $variants->build($master);
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:master_approved', [
            'content_master_id' => $master->getKey(),
            'package_hash' => $master->package_hash,
            'channel_variant_ids' => $builtVariants->modelKeys(),
        ]);

        return back()->with('message', __('hengjia_content.messages.master_approved'));
    }

    public function promoteMaster(PromoteContentMasterRequest $request, ContentMaster $contentMaster, WordPressContentBridge $bridge, ChannelVariantBuilder $variants): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $data = $request->validated();
        $article = $bridge->promoteToArticle($contentMaster, $admin, (int) $data['category_id'], (int) $data['author_id'], isset($data['task_id']) ? (int) $data['task_id'] : null);
        $variants->build($contentMaster->refresh());
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:master_promoted_to_article', [
            'content_master_id' => $contentMaster->getKey(),
            'article_id' => $article->getKey(),
            'package_hash' => $contentMaster->fresh()->package_hash,
            'input_hash' => $this->hashPayload($data),
        ]);

        return back()->with('message', __('hengjia_content.messages.master_promoted', ['id' => $article->id]));
    }

    public function rollbackMaster(Request $request, ContentMaster $contentMaster, WordPressContentBridge $bridge): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin?->isSuperAdmin(), 403);
        $bridge->rollbackPromotion($contentMaster, $admin);
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:master_promotion_rolled_back', [
            'content_master_id' => $contentMaster->getKey(),
            'article_id' => $contentMaster->fresh()->article_id,
        ]);

        return back()->with('message', __('hengjia_content.messages.master_rolled_back'));
    }

    public function previews(): View
    {
        return $this->page('previews', [
            'variants' => ChannelVariant::query()->with(['master:id,title,status,article_id', 'adapter:id,key,name,version', 'account:id,account_name'])->latest('id')->paginate(30),
        ]);
    }

    public function rebuildVariants(Request $request, ContentMaster $contentMaster, ChannelVariantBuilder $builder): RedirectResponse
    {
        $variants = $builder->build($contentMaster);
        /** @var Admin $admin */
        $admin = $request->user('admin');
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:channel_variants_rebuilt', [
            'content_master_id' => $contentMaster->getKey(),
            'channel_variant_ids' => $variants->modelKeys(),
        ]);

        return back()->with('message', __('hengjia_content.messages.variants_rebuilt', ['count' => $variants->count()]));
    }

    public function publishing(): View
    {
        return $this->page('publishing', [
            'variants' => ChannelVariant::query()->with(['master.article', 'adapter:id,key,name,version', 'account:id,account_name,authorization_status,login_status'])->latest('updated_at')->paginate(30),
            'manualPublications' => ManualPublication::query()->with('channelVariant:id,content_master_id,status')->whereNotNull('channel_variant_id')->latest('id')->limit(20)->get(),
        ]);
    }

    public function approveVariant(Request $request, ChannelVariant $channelVariant, ChannelPublicationService $publishing): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        abort_unless($admin->canManageProtectedWorkflows(), 403);
        $variant = $publishing->approve($channelVariant, $admin);
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:channel_variant_approved', [
            'channel_variant_id' => $variant->getKey(),
            'payload_hash' => $variant->payload_hash,
        ]);

        return back()->with('message', __('hengjia_content.messages.variant_approved'));
    }

    public function prepareVariant(Request $request, ChannelVariant $channelVariant, ChannelPublicationService $publishing): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin?->canManageProtectedWorkflows(), 403);

        try {
            $variant = $publishing->prepare($channelVariant, $admin);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:channel_variant_prepared', [
            'channel_variant_id' => $variant->getKey(),
            'status' => $variant->status,
            'payload_hash' => $variant->payload_hash,
        ]);

        return back()->with('message', __('hengjia_content.messages.variant_prepared', ['status' => $variant->status]));
    }

    public function readbackVariant(Request $request, ChannelVariant $channelVariant, ChannelPublicationService $publishing): RedirectResponse
    {
        $result = $publishing->readback($channelVariant);
        /** @var Admin $admin */
        $admin = $request->user('admin');
        AdminActivityLogger::logFromRequest($request, $admin, 'hengjia_content:channel_variant_read_back', [
            'channel_variant_id' => $channelVariant->getKey(),
            'status' => (string) ($result['status'] ?? ''),
            'has_remote_id' => trim((string) ($result['remote_id'] ?? '')) !== '',
        ]);

        return back()->with('message', __('hengjia_content.messages.variant_readback', ['status' => (string) $result['status']]));
    }

    public function governance(): View
    {
        return $this->page('governance', [
            'prompts' => PromptRecipeVersion::query()->orderBy('recipe_key')->orderByDesc('id')->get(),
            'sources' => ContentSourceFile::query()->latest('last_synced_at')->paginate(50),
            'runs' => ContentGenerationRun::query()->latest('started_at')->limit(20)->get(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data = []): View
    {
        return view('admin.hengjia-content.'.$view, $data + [
            'pageTitle' => __('hengjia_content.module'),
            'activeMenu' => 'hengjia_content',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $view,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{qualification:array<string,mixed>|null,official_lookup_url:?string,valid_until:?string}
     */
    private function normalizeEvidenceForStorage(array $data, ?ContentSourceFile $source, Admin $reviewer): array
    {
        $publishable = $data['public_permission'] === ContentSourceFile::PERMISSION_PUBLISHABLE;
        if ($publishable) {
            if (! $this->isStrongEvidenceStatus((string) $data['evidence_status']) || ! $source instanceof ContentSourceFile) {
                throw ValidationException::withMessages([
                    'public_permission' => __('hengjia_content.messages.publishable_source_required'),
                ]);
            }
            if (! $source->is_approved
                || $source->public_permission !== ContentSourceFile::PERMISSION_PUBLISHABLE
                || ! $this->isStrongEvidenceStatus((string) $source->evidence_status)) {
                throw ValidationException::withMessages([
                    'content_source_file_id' => __('hengjia_content.messages.source_not_approved'),
                ]);
            }
        }

        if (($data['claim_type'] ?? null) !== EvidenceClaim::TYPE_QUALIFICATION) {
            return [
                'qualification' => null,
                'official_lookup_url' => $data['official_lookup_url'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
            ];
        }
        if (! $source instanceof ContentSourceFile) {
            throw ValidationException::withMessages([
                'content_source_file_id' => __('hengjia_content.messages.qualification_source_required'),
            ]);
        }

        $qualification = (array) ($data['qualification'] ?? []);
        if (($qualification['public_permission'] ?? null) !== $data['public_permission']) {
            throw ValidationException::withMessages([
                'qualification.public_permission' => __('hengjia_content.messages.qualification_permission_mismatch'),
            ]);
        }
        if (! hash_equals((string) $source->sha256, (string) ($qualification['file_sha256'] ?? ''))) {
            throw ValidationException::withMessages([
                'qualification.file_sha256' => __('hengjia_content.messages.qualification_hash_mismatch'),
            ]);
        }

        $qualificationLookup = rtrim(trim((string) ($qualification['official_lookup_url'] ?? '')), '/');
        $topLevelLookup = rtrim(trim((string) ($data['official_lookup_url'] ?? '')), '/');
        if ($topLevelLookup !== '' && $topLevelLookup !== $qualificationLookup) {
            throw ValidationException::withMessages([
                'official_lookup_url' => __('hengjia_content.messages.qualification_lookup_mismatch'),
            ]);
        }
        if (! str_starts_with(strtolower($qualificationLookup), 'https://')) {
            throw ValidationException::withMessages([
                'qualification.official_lookup_url' => __('hengjia_content.messages.qualification_lookup_https'),
            ]);
        }

        $qualificationValidUntil = (string) ($qualification['valid_until'] ?? '');
        $topLevelValidUntil = (string) ($data['valid_until'] ?? '');
        if ($topLevelValidUntil !== '' && $topLevelValidUntil !== $qualificationValidUntil) {
            throw ValidationException::withMessages([
                'valid_until' => __('hengjia_content.messages.qualification_valid_until_mismatch'),
            ]);
        }
        if ($publishable && now()->startOfDay()->gt(Carbon::parse($qualificationValidUntil)->startOfDay())) {
            throw ValidationException::withMessages([
                'qualification.valid_until' => __('hengjia_content.messages.qualification_expired'),
            ]);
        }

        $qualification['file_sha256'] = (string) $source->sha256;
        $qualification['public_permission'] = (string) $data['public_permission'];
        $qualification['official_lookup_url'] = $qualificationLookup;
        $qualification['valid_until'] = $qualificationValidUntil;
        $qualification['reviewer'] = trim((string) ($reviewer->display_name ?: $reviewer->username));

        return [
            'qualification' => $qualification,
            'official_lookup_url' => $qualificationLookup,
            'valid_until' => $qualificationValidUntil,
        ];
    }

    private function isStrongEvidenceStatus(string $status): bool
    {
        return in_array($status, [
            ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
            ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
        ], true);
    }

    /** @param array<string,mixed> $payload */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
