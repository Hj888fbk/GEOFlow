@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0 space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">按需自媒体发布</h1>
            <p class="mt-1 text-sm text-gray-600">官网上线回读是硬门禁；候选列表不会生成平台稿，也不会调用模型。</p>
        </div>
        <a href="{{ route('admin.manual-publications.index') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">返回人工工作单</a>
    </div>

    @if(session('message'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('message') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach(['eligible' => '可分发文章', 'candidates' => '自媒体候选', 'batches' => '发布批次', 'work_orders' => '平台工作单'] as $key => $label)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold">{{ $counts[$key] }}</p></div>
        @endforeach
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900">任务级自动策略</h2>
        <p class="mt-1 text-sm text-gray-500">全局默认关闭；每日自动来源固定最多 1 篇，待审批次最多 2 个。</p>
        <div class="mt-5 space-y-4">
            @foreach($tasks as $task)
                @php($policy = $task->selfMediaPolicy)
                <form method="POST" action="{{ route('admin.manual-publications.self-media.policies.update', ['taskId' => $task->id]) }}" class="grid grid-cols-1 gap-3 rounded-lg border border-gray-200 p-4 lg:grid-cols-8 lg:items-end">
                    @csrf @method('PUT')
                    <div class="lg:col-span-2"><div class="font-medium text-gray-900">{{ $task->name }}</div><div class="text-xs text-gray-500">任务 #{{ $task->id }} · {{ $task->status }}</div></div>
                    <label class="text-sm">内容意图<select name="content_intent" required class="mt-1 w-full rounded-md border-gray-300">@foreach($intents as $intent)<option value="{{ $intent }}" @selected(($policy?->content_intent ?? '') === $intent)>{{ $intent }}</option>@endforeach</select></label>
                    <div class="lg:col-span-2"><div class="text-sm">自动路由覆盖（不选则按意图）</div><div class="mt-2 flex flex-wrap gap-2">@foreach($platforms as $platform)<label class="text-xs"><input type="checkbox" name="platform_override[]" value="{{ $platform }}" @checked(in_array($platform, $policy?->platform_override ?? [], true))> {{ __('admin.manual_publications.platform.'.$platform) }}</label>@endforeach</div></div>
                    <label class="text-sm">待审上限<input type="number" name="pending_batch_limit" min="1" max="2" value="{{ $policy?->pending_batch_limit ?? 2 }}" class="mt-1 w-full rounded-md border-gray-300"></label>
                    <input type="hidden" name="daily_source_limit" value="1">
                    <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($policy?->enabled)>明确启用自动计划</label>
                    <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">保存策略</button>
                </form>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900">可分发文章与候选</h2>
        <p class="mt-1 text-sm text-gray-500">只有 HJ-WEB 提供正式 URL、HTTP 200 且母稿与回读哈希一致的文章才会出现。</p>
        <div class="mt-5 space-y-4">
            @forelse($eligibleReceipts as $receipt)
                <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.store') }}" class="rounded-lg border border-gray-200 p-4">
                    @csrf
                    <input type="hidden" name="website_publication_receipt_id" value="{{ $receipt->id }}">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between"><div><div class="font-semibold">{{ $receipt->article->title }}</div><a class="text-xs text-blue-600" href="{{ $receipt->formal_url }}" target="_blank" rel="noopener">{{ $receipt->formal_url }}</a></div><div class="text-xs text-gray-500">回读 {{ $receipt->verified_at?->format('Y-m-d H:i') }}</div></div>
                    <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-4">
                        <label class="text-sm">分发意图<select name="content_intent" required class="mt-1 w-full rounded-md border-gray-300">@foreach($intents as $intent)<option value="{{ $intent }}">{{ $intent }}</option>@endforeach</select></label>
                        <div class="lg:col-span-2"><div class="text-sm">只生成所选平台</div><div class="mt-2 flex flex-wrap gap-3">@foreach($platforms as $platform)<label class="text-sm"><input type="checkbox" name="platforms[]" value="{{ $platform }}"> {{ __('admin.manual_publications.platform.'.$platform) }}</label>@endforeach</div></div>
                        <button class="h-fit self-end rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">加入自媒体计划</button>
                    </div>
                </form>
            @empty
                <div class="rounded-lg bg-gray-50 p-5 text-sm text-gray-600">暂无通过官网在线回读的可分发文章。</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900">发布批次</h2>
        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="py-2 pr-4">批次</th><th class="py-2 pr-4">母稿</th><th class="py-2 pr-4">路由</th><th class="py-2 pr-4">状态</th><th class="py-2">操作</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($batches as $batch)
                        <tr><td class="py-3 pr-4">#{{ $batch->id }}<div class="text-xs text-gray-500">{{ $batch->trigger }}</div></td><td class="py-3 pr-4">{{ $batch->article?->title }}<div class="text-xs text-gray-500">{{ count($batch->target_platforms ?? []) }} 个平台 / {{ $batch->publications->count() }} 个工作单</div></td><td class="py-3 pr-4"><div>{{ $batch->content_intent }}</div><div class="text-xs text-gray-500">{{ $batch->routing_version }}</div></td><td class="py-3 pr-4">{{ $batch->status }}@if($batch->invalidated_at)<div class="text-xs text-red-600">母稿已变化</div>@endif</td><td class="py-3"><div class="flex flex-wrap gap-2">
                            @if(in_array($batch->status, [\App\Models\ManualPublicationBatch::STATUS_PLANNED, \App\Models\ManualPublicationBatch::STATUS_FAILED], true))<form method="POST" action="{{ route('admin.manual-publications.self-media.batches.generate', ['batchId' => $batch->id]) }}">@csrf<button class="rounded border px-3 py-1">{{ $batch->status === \App\Models\ManualPublicationBatch::STATUS_FAILED ? '重试缺失平台' : '生成平台稿' }}</button></form>@endif
                            @if($batch->status === \App\Models\ManualPublicationBatch::STATUS_PENDING_REVIEW)<form method="POST" action="{{ route('admin.manual-publications.self-media.batches.approve', ['batchId' => $batch->id]) }}">@csrf<button class="rounded border border-emerald-300 px-3 py-1 text-emerald-700">审核通过</button></form>@endif
                            @if(!in_array($batch->status, [\App\Models\ManualPublicationBatch::STATUS_COMPLETED, \App\Models\ManualPublicationBatch::STATUS_CANCELLED, \App\Models\ManualPublicationBatch::STATUS_INVALIDATED], true))<form method="POST" action="{{ route('admin.manual-publications.self-media.batches.cancel', ['batchId' => $batch->id]) }}">@csrf<button class="rounded border border-red-200 px-3 py-1 text-red-700">取消</button></form>@endif
                        </div>@if(!empty($batch->generation_errors))<div class="mt-2 text-xs text-red-600">{{ collect($batch->generation_errors)->map(fn($message, $platform) => $platform.'：'.$message)->implode('；') }}</div>@endif</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $batches->links() }}</div>
    </section>
</div>
@endsection
