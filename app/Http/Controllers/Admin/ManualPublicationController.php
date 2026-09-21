<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualPublicationRequest;
use App\Http\Requests\Admin\TransitionManualPublicationRequest;
use App\Http\Requests\Admin\UpdateManualPublicationRequest;
use App\Models\Admin;
use App\Models\Article;
use App\Models\BrowserOperatorClient;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Models\ManualPublicationPersona;
use App\Models\Task;
use App\Models\WebsitePublicationReceipt;
use App\Services\GeoFlow\ManualPublicationService;
use App\Services\SelfMedia\ManualPublicationLifecycleService;
use App\Services\SelfMedia\SelfMediaPlatformRouter;
use App\Services\SelfMedia\SelfMediaSourceHasher;
use App\Services\SelfMedia\WebsitePublicationReadbackService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ManualPublicationController extends Controller
{
    public function __construct(private readonly ManualPublicationService $service) {}

    public function index(
        Request $request,
        SelfMediaSourceHasher $sourceHasher,
        WebsitePublicationReadbackService $websiteReadback,
    ): View {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('viewAny', ManualPublication::class);
        $view = in_array((string) $request->query('view'), ['pending', 'launch', 'history', 'advanced'], true)
            ? (string) $request->query('view')
            : 'pending';
        $query = $this->filteredQuery($request, $admin);
        if ($view === 'pending') {
            $query->whereNull('archived_at')->whereNotIn('status', [
                ManualPublication::STATUS_COMPLETED,
                ManualPublication::STATUS_CANCELLED,
                ManualPublication::STATUS_SKIPPED,
            ]);
        } elseif ($view === 'history') {
            $query->where(function (Builder $history): void {
                $history->whereNotNull('archived_at')->orWhereIn('status', [
                    ManualPublication::STATUS_COMPLETED,
                    ManualPublication::STATUS_CANCELLED,
                    ManualPublication::STATUS_SKIPPED,
                ]);
            });
        }
        $publications = (clone $query)
            ->with($this->relations())
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $eligibleReceipts = WebsitePublicationReceipt::query()
            ->with('article:id,title,slug,content,excerpt,task_id')
            ->where('readback_succeeded', true)
            ->latest('verified_at')
            ->limit(100)
            ->get()
            ->filter(static fn (WebsitePublicationReceipt $receipt): bool => $receipt->article !== null
                && $receipt->isVerifiedFor($sourceHasher->hash($receipt->article)));

        // 每次打开发布中心都做一次轻量只读补偿回读。之前只有在完全
        // 没有旧回执时才轮询，导致已有旧回执时新官网文章永远不会出现。
        $websiteReadback->pollPending(20);
        $eligibleReceipts = WebsitePublicationReceipt::query()
            ->with('article:id,title,slug,content,excerpt,task_id')
            ->where('readback_succeeded', true)
            ->latest('verified_at')
            ->limit(100)
            ->get()
            ->filter(static fn (WebsitePublicationReceipt $receipt): bool => $receipt->article !== null
                && $receipt->isVerifiedFor($sourceHasher->hash($receipt->article)));
        $batchQuery = ManualPublicationBatch::query()
            ->with([
                'article:id,title',
                'persona:id,name',
                'publications:id,manual_publication_batch_id,account_id,platform,status,platform_title,body_markdown,source_stale_at',
                'publications.account:id,account_name,platform',
            ]);
        if (! $admin->isSuperAdmin()) {
            $batchQuery->whereHas('publications', fn (Builder $publications) => $publications->where('assigned_admin_id', $admin->id));
        }
        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $batchQuery->where(function (Builder $matching) use ($search): void {
                $matching->whereHas('article', fn (Builder $article) => $article->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('publications.account', fn (Builder $account) => $account->where('account_name', 'like', '%'.$search.'%'));
            });
        }
        if ($view === 'pending') {
            $batchQuery->whereNull('archived_at')->whereNotIn('status', [ManualPublicationBatch::STATUS_COMPLETED, ManualPublicationBatch::STATUS_CANCELLED]);
        } elseif ($view === 'history') {
            $batchQuery->where(function (Builder $history): void {
                $history->whereNotNull('archived_at')->orWhereIn('status', [ManualPublicationBatch::STATUS_COMPLETED, ManualPublicationBatch::STATUS_CANCELLED]);
            });
        }
        $personas = ManualPublicationPersona::query()
            ->where('is_active', true)
            ->with(['accounts' => fn ($accounts) => $accounts->where('is_active', true)->orderBy('platform')->orderBy('account_name')])
            ->orderBy('name')
            ->get();
        $readyAccounts = ManualPublicationAccount::query()
            ->where('is_active', true)
            ->where('browser_adapter_enabled', true)
            ->whereNotNull('editor_url')
            ->where(function (Builder $identity): void {
                $identity->whereNotNull('profile_url')->orWhereNotNull('account_uid')->orWhereNotNull('homepage_identifier');
            });

        return view('admin.manual-publications.index', [
            'pageTitle' => '发布中心',
            'activeMenu' => 'publishing',
            'adminSiteName' => AdminWeb::siteName(),
            'view' => $view,
            'publications' => $publications,
            'batches' => $batchQuery->latest('id')->paginate(20, ['*'], 'batch_page')->withQueryString(),
            'trashBatches' => $view === 'history'
                ? ManualPublicationBatch::onlyTrashed()->with(['article:id,title', 'publicationsWithTrashed.account:id,account_name,platform'])->latest('deleted_at')->limit(100)->get()
                : collect(),
            'trashPublications' => $view === 'history'
                ? ManualPublication::onlyTrashed()->whereNull('manual_publication_batch_id')->with($this->relations())->latest('deleted_at')->limit(100)->get()
                : collect(),
            'eligibleReceipts' => $eligibleReceipts,
            'personas' => $personas,
            'accounts' => $personas->flatMap->accounts->values(),
            'intents' => SelfMediaPlatformRouter::INTENTS,
            'tasks' => $view === 'advanced' ? Task::query()->with('selfMediaPolicy')->orderBy('name')->get(['id', 'name', 'status']) : collect(),
            'filters' => $request->only(['status', 'type', 'platform', 'assigned_admin_id', 'article_id', 'scheduled_from', 'scheduled_to', 'search']),
            'stats' => [
                'client_connected' => BrowserOperatorClient::query()->where('client_type', BrowserOperatorClient::TYPE_DESKTOP)->where('last_seen_at', '>=', now()->subMinutes(5))->exists(),
                'account_ready' => (clone $readyAccounts)->count(),
                'account_total' => ManualPublicationAccount::query()->where('is_active', true)->whereIn('platform', ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)->count(),
                'pending_review' => ManualPublicationBatch::query()->where('status', ManualPublicationBatch::STATUS_PENDING_REVIEW)->count(),
                'pending_publish' => ManualPublication::query()->whereIn('status', [ManualPublication::STATUS_READY, ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED, ManualPublication::STATUS_OUTCOME_UNKNOWN])->count(),
            ],
            'admins' => $admin->isSuperAdmin() ? $this->activeAdmins() : collect([$admin]),
            'canCreate' => Gate::forUser($admin)->allows('create', ManualPublication::class),
            'platforms' => ManualPublicationAccount::PLATFORMS,
            'draftSyncPlatforms' => ManualPublicationAccount::DRAFT_SYNC_PLATFORMS,
            'editorUrlPresets' => ManualPublicationAccount::editorUrlPresets(),
            'activeDrawer' => in_array((string) $request->query('drawer'), ['accounts', 'settings'], true) ? (string) $request->query('drawer') : null,
            'selectedBatchId' => max(0, (int) $request->query('batch')),
            'extensionVersion' => '0.3.1',
            'extensionSha256' => is_file(base_path('dist/browser-extension/geoflow-chrome-operator-0.3.1.zip'))
                ? (hash_file('sha256', base_path('dist/browser-extension/geoflow-chrome-operator-0.3.1.zip')) ?: null)
                : null,
            'desktopPublisherVersion' => '0.1.0',
            'desktopPublisherAvailable' => is_file(base_path('dist/desktop-publisher/GEOFlow-Desktop-Publisher-0.1.0-win-x64.exe'))
                && is_file(base_path('dist/desktop-publisher/GEOFlow-Desktop-Publisher-0.1.0-win-x64.exe.sig')),
        ]);
    }

    public function redirectCreate(): RedirectResponse
    {
        return redirect()->route('admin.manual-publications.index', ['view' => 'advanced']);
    }

    public function create(Request $request): View
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('create', ManualPublication::class);
        $article = Article::query()
            ->whereKey((int) $request->query('article_id'))
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->first(['id', 'title', 'content', 'review_status']);

        return view('admin.manual-publications.form', $this->formViewData($request, null, $article));
    }

    public function store(StoreManualPublicationRequest $request): RedirectResponse
    {
        try {
            $publication = $this->service->create($request->validated(), $this->admin($request));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.created'));
    }

    public function show(Request $request, int $manualPublicationId): View
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('view', $publication);
        $publication->load($this->relations());

        return view('admin.manual-publications.show', [
            'pageTitle' => __('admin.manual_publications.detail_title', ['id' => $publication->getKey()]),
            'activeMenu' => 'publishing',
            'adminSiteName' => AdminWeb::siteName(),
            'publication' => $publication,
            'duplicates' => $this->service->duplicatesFor($publication)
                ->filter(fn (ManualPublication $duplicate): bool => Gate::forUser($admin)->allows('view', $duplicate))
                ->values(),
            'canEdit' => Gate::forUser($admin)->allows('update', $publication),
            'canTransition' => Gate::forUser($admin)->allows('transition', $publication),
            'canReopen' => Gate::forUser($admin)->allows('reopen', $publication),
        ]);
    }

    public function edit(Request $request, int $manualPublicationId): View
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('update', $publication);

        return view('admin.manual-publications.form', $this->formViewData($request, $publication));
    }

    public function update(UpdateManualPublicationRequest $request, int $manualPublicationId): RedirectResponse
    {
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();

        try {
            $publication = $this->service->update(
                $publication,
                $request->safe()->except('revision'),
                (int) $request->validated('revision'),
            );
        } catch (DomainException|ManualPublicationConflictException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.updated'));
    }

    public function transition(TransitionManualPublicationRequest $request, int $manualPublicationId): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        $targetStatus = (string) $request->validated('target_status');
        $ability = $publication->isReopenTransition($targetStatus) ? 'reopen' : 'transition';
        Gate::forUser($admin)->authorize($ability, $publication);

        try {
            $this->service->transition(
                $publication,
                $targetStatus,
                (int) $request->validated('revision'),
                $admin,
                completionUrl: $request->validated('completion_url'),
                resultNote: $request->validated('result_note'),
            );
        } catch (DomainException|ManualPublicationConflictException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(__('admin.manual_publications.error.unexpected'));
        }

        return redirect()
            ->route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()])
            ->with('message', __('admin.manual_publications.message.transitioned'));
    }

    public function trash(Request $request, int $manualPublicationId, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('delete', $publication);

        try {
            $lifecycle->trashPublication($manualPublicationId);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }
        $this->auditLifecycle($request, $admin, 'manual_publication.trashed', $manualPublicationId);

        return back()->with('message', '工单已移入回收站，将保留 30 天。');
    }

    public function restore(Request $request, int $manualPublicationId, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::onlyTrashed()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('restore', $publication);
        $lifecycle->restorePublication($manualPublicationId);
        $this->auditLifecycle($request, $admin, 'manual_publication.restored', $manualPublicationId);

        return back()->with('message', '工单已从回收站恢复。');
    }

    public function archive(Request $request, int $manualPublicationId, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('archive', $publication);

        try {
            $lifecycle->archivePublication($manualPublicationId);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }
        $this->auditLifecycle($request, $admin, 'manual_publication.archived', $manualPublicationId);

        return back()->with('message', '已完成工单已归档。');
    }

    public function unarchive(Request $request, int $manualPublicationId, ManualPublicationLifecycleService $lifecycle): RedirectResponse
    {
        $admin = $this->admin($request);
        $publication = ManualPublication::query()->whereKey($manualPublicationId)->firstOrFail();
        Gate::forUser($admin)->authorize('archive', $publication);
        $lifecycle->unarchivePublication($manualPublicationId);
        $this->auditLifecycle($request, $admin, 'manual_publication.unarchived', $manualPublicationId);

        return back()->with('message', '工单已取消归档。');
    }

    public function export(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        Gate::forUser($admin)->authorize('exportAny', ManualPublication::class);
        $query = $this->filteredQuery($request, $admin);
        $filename = 'geoflow-manual-publications-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map(fn (string $key): string => (string) __('admin.manual_publications.export.'.$key), [
                'id', 'type', 'platform', 'article', 'persona', 'account', 'assignee', 'status', 'scheduled_at',
                'target_url', 'content', 'risk_status', 'duplicates', 'completion_url', 'result_note', 'created_at',
            ]));

            $query->chunkById(200, function ($rows) use ($handle): void {
                $rows->load($this->relations());

                foreach ($rows as $row) {
                    if (! $row instanceof ManualPublication) {
                        continue;
                    }
                    fputcsv($handle, array_map(fn (mixed $value): string => $this->csvCell($value), [
                        $row->getKey(),
                        __('admin.manual_publications.type.'.$row->type),
                        $row->platformDisplayName(),
                        $row->article?->title ?? '',
                        $row->personaDisplayName() ?? '',
                        $row->accountDisplayName() ?? '',
                        $row->assignee?->name ?? '',
                        __('admin.manual_publications.status.'.$row->status),
                        $row->scheduled_at?->format('Y-m-d H:i:s') ?? '',
                        $row->target_url ?? '',
                        $row->content,
                        __('admin.manual_publications.risk.'.$row->risk_status),
                        $row->duplicate_warning_count,
                        $row->completion_url ?? '',
                        $row->result_note ?? '',
                        $row->created_at?->format('Y-m-d H:i:s') ?? '',
                    ]));
                }
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function formViewData(Request $request, ?ManualPublication $publication, ?Article $selectedArticle = null): array
    {
        if ($publication instanceof ManualPublication) {
            $publication->load($this->relations());
        }

        $selectedArticle ??= $publication?->article;
        $articleSearch = trim((string) $request->query('article_search'));
        $articles = Article::query()
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->when($articleSearch !== '', function (Builder $query) use ($articleSearch): void {
                $query->where('title', 'like', '%'.$articleSearch.'%');
            })
            ->latest('id')
            ->paginate(50, ['id', 'title', 'review_status'], 'article_page')
            ->withQueryString();

        if ($selectedArticle instanceof Article
            && ! $articles->getCollection()->contains(fn (Article $article): bool => $article->is($selectedArticle))) {
            $articles->setCollection(
                $articles->getCollection()->prepend($selectedArticle)->unique('id')->values(),
            );
        }

        return [
            'pageTitle' => $publication === null
                ? __('admin.manual_publications.create_title')
                : __('admin.manual_publications.edit_title', ['id' => $publication->getKey()]),
            'activeMenu' => 'publishing',
            'adminSiteName' => AdminWeb::siteName(),
            'publication' => $publication,
            'selectedArticle' => $selectedArticle,
            'personas' => ManualPublicationPersona::query()->where('is_active', true)->orderBy('name')->get(),
            'accounts' => ManualPublicationAccount::query()->where('is_active', true)->with('persona:id,name')->orderBy('account_name')->get(),
            'admins' => $this->activeAdmins(),
            'articles' => $articles,
            'articleSearch' => $articleSearch,
            'platforms' => ManualPublicationAccount::PLATFORMS,
            'prefilledContent' => $selectedArticle instanceof Article
                ? Str::limit((string) $selectedArticle->content, ManualPublication::MAX_CONTENT_CHARACTERS, '')
                : '',
        ];
    }

    /** @return Builder<ManualPublication> */
    private function filteredQuery(Request $request, Admin $admin): Builder
    {
        $query = ManualPublication::query()->visibleTo($admin);
        $status = trim((string) $request->query('status'));
        $type = trim((string) $request->query('type'));
        $platform = trim((string) $request->query('platform'));

        if (in_array($status, ManualPublication::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($type, ManualPublication::TYPES, true)) {
            $query->where('type', $type);
        }
        if (in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            $query->where('platform', $platform);
        }

        $assigneeId = (int) $request->query('assigned_admin_id');
        if ($admin->isSuperAdmin() && $assigneeId > 0) {
            $query->where('assigned_admin_id', $assigneeId);
        }

        $articleId = (int) $request->query('article_id');
        if ($articleId > 0) {
            $query->where('article_id', $articleId);
        }

        foreach (['scheduled_from' => '>=', 'scheduled_to' => '<='] as $field => $operator) {
            $date = $this->dateFilter($request->query($field));
            if ($date !== null) {
                $query->whereDate('scheduled_at', $operator, $date);
            }
        }

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('content', 'like', '%'.$search.'%')
                    ->orWhere('target_url', 'like', '%'.$search.'%')
                    ->orWhereHas('article', fn (Builder $articleQuery) => $articleQuery->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('account', fn (Builder $accountQuery) => $accountQuery->where('account_name', 'like', '%'.$search.'%'));
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function relations(): array
    {
        return [
            'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'review_status', 'deleted_at']),
            'persona:id,name,disclosure_text,is_active',
            'account:id,persona_id,platform,custom_platform,account_name,profile_url,is_active',
            'assignee:id,username,display_name',
            'creator:id,username,display_name',
        ];
    }

    private function activeAdmins()
    {
        return Admin::query()->where('status', 'active')->orderBy('display_name')->orderBy('username')->get(['id', 'username', 'display_name']);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function dateFilter(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function csvCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : (string) $value;

        return $cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }

    private function auditLifecycle(Request $request, Admin $admin, string $action, int $publicationId): void
    {
        $request->attributes->set('admin_activity_target_type', 'manual_publication');
        $request->attributes->set('admin_activity_target_id', $publicationId);
        AdminActivityLogger::logFromRequest($request, $admin, $action);
    }
}
