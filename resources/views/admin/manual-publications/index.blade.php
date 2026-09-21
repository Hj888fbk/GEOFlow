@extends('admin.layouts.app')

@section('content')
@php
    $selectedBatch = $selectedBatchId > 0 ? $batches->getCollection()->firstWhere('id', $selectedBatchId) : null;
    $statusLabels = [
        'planned' => '等待生成', 'generating' => '生成中', 'pending_review' => '待审核',
        'pending_platform' => '待发布助手', 'active' => '处理中', 'completed' => '已完成',
        'pending_verification' => '待核验', 'failed' => '失败', 'cancelled' => '已取消',
        'invalidated' => '母稿已变化',
    ];
@endphp
<div class="px-4 pb-12 sm:px-0">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">发布中心</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">从选稿到保存平台草稿集中完成；最终发布始终由你在平台页面确认。</p>
        </div>
        @if($canCreate)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.manual-publications.index', ['drawer' => 'accounts'] + request()->except('drawer')) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">账号中心</a>
                <a href="{{ route('admin.manual-publications.index', ['drawer' => 'settings'] + request()->except('drawer')) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">高级设置</a>
                <a href="{{ route('admin.manual-publications.index', ['view' => 'launch']) }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">发起发布</a>
            </div>
        @endif
    </div>

    <div class="mt-5 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3"><span class="text-sm text-gray-600">桌面助手</span><span class="text-sm font-semibold {{ $stats['client_connected'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $stats['client_connected'] ? '已连接' : '未连接' }}</span></div>
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3"><span class="text-sm text-gray-600">账号就绪</span><span class="text-sm font-semibold text-gray-900">{{ $stats['account_ready'] }}/{{ $stats['account_total'] }}</span></div>
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3"><span class="text-sm text-gray-600">待审核</span><span class="text-sm font-semibold text-amber-700">{{ $stats['pending_review'] }}</span></div>
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3"><span class="text-sm text-gray-600">待发布</span><span class="text-sm font-semibold text-blue-700">{{ $stats['pending_publish'] }}</span></div>
    </div>

    <nav class="mt-6 flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="发布中心视图">
        @foreach(['pending' => '待处理', 'launch' => '发起发布', 'history' => '历史记录'] as $key => $label)
            <a href="{{ route('admin.manual-publications.index', ['view' => $key]) }}" class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold {{ $view === $key ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($errors->any())
        <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    @if($view === 'launch')
        <form method="POST" action="{{ route('admin.manual-publications.self-media.launch') }}" class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            <div class="grid gap-6 xl:grid-cols-[1.1fr_.9fr]">
                <section>
                    <div class="flex items-center justify-between gap-4">
                        <div><h2 class="text-lg font-semibold text-gray-900">1. 选择已通过官网回读的文章</h2><p class="mt-1 text-sm text-gray-500">仅显示 HTTP 200、内容哈希和官网回读均通过的文章。</p></div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ $eligibleReceipts->count() }} 篇可用</span>
                    </div>
                    <div class="mt-4 max-h-72 space-y-2 overflow-y-auto pr-1">
                        @forelse($eligibleReceipts as $receipt)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 hover:border-blue-300 hover:bg-blue-50/40">
                                <input type="radio" name="website_publication_receipt_id" value="{{ $receipt->id }}" required @checked((string) old('website_publication_receipt_id') === (string) $receipt->id || $eligibleReceipts->count() === 1) class="mt-1 border-gray-300 text-blue-600">
                                <span class="min-w-0"><span class="block truncate text-sm font-semibold text-gray-900">{{ $receipt->article?->title }}</span><span class="mt-1 block truncate text-xs text-gray-500">{{ $receipt->formal_url }}</span></span>
                            </label>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">当前没有通过官网回读门禁的文章。</div>
                        @endforelse
                    </div>
                    <div class="mt-5">
                        <label class="block text-sm font-medium text-gray-700">内容意图</label>
                        <select name="content_intent" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach($intents as $intent)<option value="{{ $intent }}" @selected(old('content_intent') === $intent)>{{ str_replace('_', ' ', $intent) }}</option>@endforeach
                        </select>
                    </div>
                </section>

                <section>
                    <h2 class="text-lg font-semibold text-gray-900">2. 选择身份与账号</h2>
                    <p class="mt-1 text-sm text-gray-500">可同时选择同一平台的多个账号；单身份和单账号会自动选中。</p>
                    <label class="mt-4 block text-sm font-medium text-gray-700">发布身份</label>
                    <select id="launch-persona" name="persona_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">请选择身份</option>
                        @foreach($personas as $persona)<option value="{{ $persona->id }}" @selected((string) old('persona_id') === (string) $persona->id || $personas->count() === 1)>{{ $persona->name }}</option>@endforeach
                    </select>
                    <div class="mt-4 flex items-center justify-between gap-3"><span class="text-sm font-medium text-gray-700">目标账号</span><button id="select-ready-accounts" type="button" class="text-sm font-semibold text-blue-700 hover:text-blue-900">一键选择全部已就绪账号</button></div>
                    <div id="launch-accounts" class="mt-2 max-h-80 space-y-2 overflow-y-auto pr-1">
                        @foreach($accounts as $account)
                            @php
                                $readinessGaps = [];
                                if (! $account->browser_adapter_enabled) { $readinessGaps[] = __('admin.manual_publications.readiness.gap_adapter'); }
                                if (blank($account->editor_url)) { $readinessGaps[] = __('admin.manual_publications.readiness.gap_editor_url'); }
                                if (blank($account->profile_url) && blank($account->account_uid) && blank($account->homepage_identifier)) { $readinessGaps[] = __('admin.manual_publications.readiness.gap_identity'); }
                                $ready = $readinessGaps === [];
                            @endphp
                            <label data-persona="{{ $account->persona_id }}" data-ready="{{ $ready ? '1' : '0' }}" class="launch-account flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 {{ $ready ? '' : 'opacity-60' }}">
                                <input type="checkbox" name="account_ids[]" value="{{ $account->id }}" @checked(in_array($account->id, array_map('intval', (array) old('account_ids', [])), true)) @disabled(!$ready) class="rounded border-gray-300 text-blue-600">
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-gray-900">{{ $account->account_name }}</span><span class="block text-xs text-gray-500">{{ __('admin.manual_publications.platform.'.$account->platform) }}</span></span>
                                <span class="shrink-0 text-right"><span class="block text-xs font-semibold {{ $ready ? 'text-emerald-700' : 'text-amber-700' }}">{{ $ready ? '就绪' : '待绑定' }}</span>@if(! $ready)<span class="mt-0.5 block text-xs text-amber-600">{{ implode('、', $readinessGaps) }}</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                </section>
            </div>
            <div class="mt-6 flex flex-col gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-gray-500">提交后立即进入生成队列。生成完成后只需审核一次，发布助手只保存草稿，不会点击最终发布。</p><button @disabled($eligibleReceipts->isEmpty()) class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">生成并发起发布</button></div>
        </form>
    @elseif($view === 'history')
        <div class="mt-6 space-y-6">
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">发布历史</h2><a href="{{ route('admin.manual-publications.export', request()->query()) }}" class="text-sm font-semibold text-blue-700">导出 CSV</a></div>
                @include('admin.manual-publications.partials.batch-list', ['batches' => $batches, 'statusLabels' => $statusLabels, 'historyMode' => true])
                @include('admin.manual-publications.partials.work-order-list', ['publications' => $publications])
            </section>
            @if($canCreate)
                <details class="rounded-xl border border-gray-200 bg-white shadow-sm" @if($trashBatches->isNotEmpty() || $trashPublications->isNotEmpty()) open @endif>
                    <summary class="cursor-pointer px-5 py-4 font-semibold text-gray-900">回收站 <span class="ml-2 text-sm font-normal text-gray-500">保留 30 天，不提供立即永久删除</span></summary>
                    <div class="border-t border-gray-100 p-5"><div class="space-y-2">
                        @forelse($trashBatches as $batch)
                            <div class="flex flex-col gap-3 rounded-lg border border-gray-200 p-3 sm:flex-row sm:items-center sm:justify-between"><div><div class="text-sm font-semibold text-gray-900">任务 #{{ $batch->id }} · {{ $batch->article?->title ?? '文章已删除' }}</div><div class="mt-1 text-xs text-gray-500">{{ $batch->deleted_at?->format('Y-m-d H:i') }} 移入回收站 · {{ $batch->publicationsWithTrashed->count() }} 个账号进度</div></div><form method="POST" action="{{ route('admin.manual-publications.self-media.batches.restore', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700">恢复</button></form></div>
                        @empty
                            @if($trashPublications->isEmpty())<p class="text-sm text-gray-500">回收站为空。</p>@endif
                        @endforelse
                        @foreach($trashPublications as $publication)<div class="flex items-center justify-between rounded-lg border border-gray-200 p-3"><span class="text-sm text-gray-700">工单 #{{ $publication->id }} · {{ $publication->platformDisplayName() }}</span><form method="POST" action="{{ route('admin.manual-publications.restore', ['manualPublicationId' => $publication->id]) }}">@csrf<button class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700">恢复</button></form></div>@endforeach
                    </div></div>
                </details>
            @endif
        </div>
    @elseif($view === 'advanced')
        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4"><div><h2 class="font-semibold text-gray-900">高级创建与自动策略</h2><p class="mt-1 text-sm text-gray-500">评论、自定义平台、普通人工工单及按任务自动策略保留在此。</p></div><a href="{{ route('admin.manual-publications.advanced-create') }}" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">创建普通工单</a></div>
            <div class="mt-5 space-y-2">@forelse($tasks as $task)<details class="rounded-lg border border-gray-200 p-4"><summary class="cursor-pointer text-sm font-semibold text-gray-900">{{ $task->name }}</summary><form method="POST" action="{{ route('admin.manual-publications.self-media.policies.update', ['taskId' => $task->id]) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf @method('PUT')<label class="flex items-center gap-2 text-sm"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($task->selfMediaPolicy?->enabled)>启用自动发布策略</label><select name="content_intent" class="rounded-md border-gray-300 text-sm">@foreach($intents as $intent)<option value="{{ $intent }}" @selected($task->selfMediaPolicy?->content_intent === $intent)>{{ str_replace('_', ' ', $intent) }}</option>@endforeach</select><input type="hidden" name="daily_source_limit" value="1"><input type="hidden" name="pending_batch_limit" value="{{ $task->selfMediaPolicy?->pending_batch_limit ?? 2 }}"><button class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700">保存策略</button></form></details>@empty<p class="text-sm text-gray-500">暂无可配置任务。</p>@endforelse</div>
        </section>
    @else
        <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-semibold text-gray-900">待处理任务</h2><p class="mt-1 text-sm text-gray-500">生成、审核、草稿同步和待核验状态集中在这里。</p></div><form method="GET" action="{{ route('admin.manual-publications.index') }}" class="flex gap-2"><input type="hidden" name="view" value="pending"><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="搜索文章或账号" class="w-48 rounded-md border-gray-300 text-sm"><button class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white">搜索</button></form></div>
            @include('admin.manual-publications.partials.batch-list', ['batches' => $batches, 'statusLabels' => $statusLabels, 'historyMode' => false])
            @include('admin.manual-publications.partials.work-order-list', ['publications' => $publications])
        </section>
    @endif
</div>

@if($activeDrawer === 'accounts')
    <div class="fixed inset-0 z-40 bg-gray-950/30" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-2xl overflow-y-auto bg-gray-50 p-5 shadow-2xl" aria-label="账号中心">
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-gray-900">账号中心</h2><p class="mt-1 text-sm text-gray-600">批量建号，编辑入口和适配器会自动配置。</p></div><a href="{{ route('admin.manual-publications.index', request()->except('drawer')) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">关闭</a></div>
        <form method="POST" action="{{ route('admin.manual-publications.settings.personas.store') }}" class="mt-6 rounded-xl border border-gray-200 bg-white p-4">@csrf<h3 class="font-semibold text-gray-900">新建发布身份</h3><div class="mt-3 grid gap-3 sm:grid-cols-2"><input name="name" required maxlength="120" placeholder="身份名称" class="rounded-md border-gray-300 text-sm"><input name="tone" maxlength="120" placeholder="语气（选填）" class="rounded-md border-gray-300 text-sm"><input name="domain" maxlength="255" placeholder="领域（选填）" class="rounded-md border-gray-300 text-sm"><input type="hidden" name="is_active" value="1"><button class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white">保存身份</button></div></form>
        <form method="POST" action="{{ route('admin.manual-publications.settings.accounts.store') }}" class="mt-4 rounded-xl border border-blue-200 bg-white p-4">@csrf<input type="hidden" name="bulk_mode" value="1"><input type="hidden" name="browser_adapter_enabled" value="1"><input type="hidden" name="is_active" value="1"><h3 class="font-semibold text-gray-900">批量创建平台账号</h3><p class="mt-1 text-sm text-gray-500">先创建账号占位；在桌面助手登录后检测公开账号标识，由你确认后绑定。</p><div class="mt-3 grid gap-3 sm:grid-cols-2"><select name="persona_id" required class="rounded-md border-gray-300 text-sm"><option value="">选择身份</option>@foreach($personas as $persona)<option value="{{ $persona->id }}">{{ $persona->name }}</option>@endforeach</select><input name="account_name" required maxlength="160" placeholder="统一账号名称" class="rounded-md border-gray-300 text-sm"></div><div class="mt-4 rounded-lg border border-gray-200 p-3"><label class="flex items-center gap-2 text-sm font-semibold text-blue-700"><input id="drawer-select-all" type="checkbox" class="rounded border-gray-300">全选 {{ count($draftSyncPlatforms) }} 个平台</label><div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">@foreach($draftSyncPlatforms as $platform)<label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="platforms[]" value="{{ $platform }}" class="drawer-platform rounded border-gray-300">{{ __('admin.manual_publications.platform.'.$platform) }}</label>@endforeach</div></div><button class="mt-4 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">创建所选账号</button></form>
        <div class="mt-5 space-y-2"><h3 class="font-semibold text-gray-900">现有账号</h3>@forelse($accounts as $account)@php $ready = $account->browser_adapter_enabled && filled($account->editor_url) && (filled($account->profile_url) || filled($account->account_uid) || filled($account->homepage_identifier)); $desktopLoginUrl = 'geoflow-publisher://login?instance='.rawurlencode(url('/')).'&account='.$account->id; @endphp<div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div><div class="text-sm font-semibold text-gray-900">{{ $account->account_name }}</div><div class="mt-1 text-xs text-gray-500">{{ __('admin.manual_publications.platform.'.$account->platform) }} · {{ $account->persona?->name }}</div></div><div class="flex items-center gap-3"><span class="text-xs font-semibold {{ $ready ? 'text-emerald-700' : 'text-amber-700' }}">{{ $ready ? '就绪' : '等待登录绑定' }}</span><a href="{{ $desktopLoginUrl }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700">在桌面助手登录</a></div></div>@empty<p class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">尚未创建账号。</p>@endforelse</div>
    </aside>
@elseif($activeDrawer === 'settings')
    <div class="fixed inset-0 z-40 bg-gray-950/30" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-xl overflow-y-auto bg-white p-6 shadow-2xl" aria-label="高级设置">
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-gray-900">高级设置</h2><p class="mt-1 text-sm text-gray-600">低频选项默认收起，避免干扰日常发布。</p></div><a href="{{ route('admin.manual-publications.index', request()->except('drawer')) }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700">关闭</a></div>
        <div class="mt-6 space-y-3"><a href="{{ route('admin.manual-publications.index', ['view' => 'advanced']) }}" class="block rounded-lg border border-gray-200 p-4 hover:bg-gray-50"><div class="font-semibold text-gray-900">自动发布策略</div><div class="mt-1 text-sm text-gray-500">按任务配置自动选稿与平台范围。</div></a><a href="{{ route('admin.manual-publications.advanced-create') }}" class="block rounded-lg border border-gray-200 p-4 hover:bg-gray-50"><div class="font-semibold text-gray-900">高级创建</div><div class="mt-1 text-sm text-gray-500">创建评论、自定义平台或普通人工工单。</div></a></div>
        <div class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4"><div class="font-semibold text-blue-950">Windows 发布助手 {{ $desktopPublisherVersion }}</div><p class="mt-1 text-sm leading-6 text-blue-800">Windows x64 独立客户端；更新包在启动安装前校验签名和 SHA-256。</p>@if($desktopPublisherAvailable)<a href="{{ route('admin.manual-publications.settings.desktop.download') }}" class="mt-3 inline-flex rounded-md bg-blue-700 px-3 py-2 text-sm font-semibold text-white">下载安装包</a>@else<span class="mt-3 inline-flex rounded-md bg-white px-3 py-2 text-xs font-semibold text-blue-700">安装包等待签名发布</span>@endif</div>
        <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4"><div class="font-semibold text-gray-900">GEOFlow Chrome 草稿助手 {{ $extensionVersion }}</div><p class="mt-1 text-sm text-gray-600">协议 v1 兼容入口，10 平台真实账号验收通过前继续保留。</p>@if($extensionSha256)<p class="mt-2 break-all font-mono text-xs text-gray-500">SHA-256：{{ $extensionSha256 }}</p><a href="{{ route('admin.manual-publications.settings.extension.download') }}" class="mt-3 inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">下载兼容扩展</a>@endif</div>
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">Chrome 扩展仍作为 v1 兼容入口保留。10 个平台完成真实账号草稿验收前，不会从电脑或仓库中移除。</div>
    </aside>
@endif

@if($selectedBatch)
    <div class="fixed inset-0 z-40 bg-gray-950/30" aria-hidden="true"></div>
    <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-3xl overflow-y-auto bg-white p-6 shadow-2xl" aria-label="发布任务详情">
        <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-gray-900">任务 #{{ $selectedBatch->id }}</h2><p class="mt-1 text-sm text-gray-600">{{ $selectedBatch->article?->title }}</p></div><a href="{{ route('admin.manual-publications.index', ['view' => $view]) }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700">关闭</a></div>
        <div class="mt-6 space-y-3">
            @foreach($selectedBatch->publications as $publication)
                @php
                    $batchAccount = $publication->account;
                    $batchReadinessGaps = [];
                    if ($batchAccount === null) {
                        $batchReadinessGaps[] = __('admin.manual_publications.readiness.unbound');
                    } else {
                        if (! $batchAccount->is_active) { $batchReadinessGaps[] = __('admin.manual_publications.readiness.gap_inactive'); }
                        if (! $batchAccount->browser_adapter_enabled) { $batchReadinessGaps[] = __('admin.manual_publications.readiness.gap_adapter'); }
                        if (blank($batchAccount->editor_url)) { $batchReadinessGaps[] = __('admin.manual_publications.readiness.gap_editor_url'); }
                        if (blank($batchAccount->profile_url) && blank($batchAccount->account_uid) && blank($batchAccount->homepage_identifier)) { $batchReadinessGaps[] = __('admin.manual_publications.readiness.gap_identity'); }
                    }
                @endphp
                <details class="rounded-lg border border-gray-200 p-4" open>
                    <summary class="cursor-pointer font-semibold text-gray-900">
                        {{ __('admin.manual_publications.platform.'.$publication->platform) }} · {{ $batchAccount?->account_name ?? '未绑定账号' }}
                        <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold {{ $batchReadinessGaps === [] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $batchReadinessGaps === [] ? __('admin.manual_publications.readiness.ready') : implode('、', $batchReadinessGaps) }}</span>
                    </summary>
                    @if($selectedBatch->status === \App\Models\ManualPublicationBatch::STATUS_PENDING_REVIEW && $publication->status === \App\Models\ManualPublication::STATUS_DRAFT)
                        <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.publications.update', ['batchId' => $selectedBatch->id, 'manualPublicationId' => $publication->id]) }}" class="mt-4 space-y-3">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="revision" value="{{ $publication->revision }}">
                            <label class="block text-xs font-semibold text-gray-600">平台标题<input name="platform_title" required maxlength="500" value="{{ $publication->platform_title }}" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                            <label class="block text-xs font-semibold text-gray-600">平台摘要<textarea name="platform_summary" maxlength="2000" rows="2" class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ $publication->platform_summary }}</textarea></label>
                            <label class="block text-xs font-semibold text-gray-600">平台正文<textarea name="body_markdown" required maxlength="100000" rows="12" class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm">{{ $publication->body_markdown }}</textarea></label>
                            <button class="rounded-md border border-blue-300 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700">保存此账号稿件</button>
                        </form>
                    @else
                        <div class="mt-3 text-sm text-gray-600"><div class="font-medium text-gray-900">{{ $publication->platform_title }}</div><div class="mt-2 whitespace-pre-wrap rounded-md bg-gray-50 p-3">{{ $publication->body_markdown }}</div></div>
                    @endif
                </details>
            @endforeach
        </div>
        @if($selectedBatch->status === \App\Models\ManualPublicationBatch::STATUS_PENDING_REVIEW)<form method="POST" action="{{ route('admin.manual-publications.self-media.batches.approve', ['batchId' => $selectedBatch->id]) }}" class="mt-5">@csrf<button class="w-full rounded-lg bg-emerald-700 px-4 py-3 text-sm font-semibold text-white">审核并交给发布助手</button></form>@endif
    </aside>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const persona = document.getElementById('launch-persona');
    const rows = [...document.querySelectorAll('.launch-account')];
    const visibleReadyInputs = () => rows.filter((row) => !row.hidden && row.dataset.ready === '1').map((row) => row.querySelector('input'));
    const syncAccounts = () => {
        rows.forEach((row) => {
            row.hidden = !persona?.value || row.dataset.persona !== persona.value;
            if (row.hidden) row.querySelector('input').checked = false;
        });
        const candidates = visibleReadyInputs();
        if (candidates.length === 1) candidates[0].checked = true;
    };
    persona?.addEventListener('change', syncAccounts);
    document.getElementById('select-ready-accounts')?.addEventListener('click', () => visibleReadyInputs().forEach((input) => { input.checked = true; }));
    const drawerAll = document.getElementById('drawer-select-all');
    const drawerPlatforms = [...document.querySelectorAll('.drawer-platform')];
    drawerAll?.addEventListener('change', () => drawerPlatforms.forEach((input) => { input.checked = drawerAll.checked; }));
    syncAccounts();
});
</script>
@endpush
