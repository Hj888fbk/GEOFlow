<div class="divide-y divide-gray-100">
    @forelse($batches as $batch)
        @php
            $status = (string) $batch->status;
            $canTrash = in_array($status, ['planned', 'pending_review', 'failed', 'cancelled', 'invalidated'], true)
                && $batch->publications->whereIn('status', ['ready', 'in_progress', 'draft_filled', 'outcome_unknown'])->isEmpty();
        @endphp
        <article class="p-5 hover:bg-gray-50/60">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.manual-publications.index', ['view' => $historyMode ? 'history' : 'pending', 'batch' => $batch->id]) }}" class="font-semibold text-blue-700 hover:text-blue-900">任务 #{{ $batch->id }}</a>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ $statusLabels[$status] ?? $status }}</span>
                        @if($batch->archived_at)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">已归档</span>@endif
                    </div>
                    <h3 class="mt-2 truncate text-sm font-semibold text-gray-900">{{ $batch->article?->title ?? '文章已删除' }}</h3>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($batch->publications as $publication)
                            <span class="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600">{{ __('admin.manual_publications.platform.'.$publication->platform) }} · {{ $publication->account?->account_name ?? '待绑定' }}</span>
                        @endforeach
                        @if($batch->publications->isEmpty())<span class="text-xs text-gray-500">目标账号 {{ count((array) $batch->target_account_ids) ?: count((array) $batch->target_platforms) }} 个，等待生成</span>@endif
                    </div>
                </div>
                @if($canCreate)
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <a href="{{ route('admin.manual-publications.index', ['view' => $historyMode ? 'history' : 'pending', 'batch' => $batch->id]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">查看</a>
                        @if(in_array($status, ['planned', 'failed'], true))
                            <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.generate', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-blue-300 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700">{{ $status === 'failed' ? '重试缺失项' : '开始生成' }}</button></form>
                        @endif
                        @if($status === 'pending_review')
                            <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.approve', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white">批量审核</button></form>
                        @endif
                        @if($status === 'completed')
                            <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.'.($batch->archived_at ? 'unarchive' : 'archive'), ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">{{ $batch->archived_at ? '取消归档' : '归档' }}</button></form>
                        @elseif($canTrash)
                            <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.trash', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">移入回收站</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.manual-publications.self-media.batches.cancel', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">安全取消</button></form>
                        @endif
                    </div>
                @endif
            </div>
        </article>
    @empty
        <div class="p-12 text-center text-sm text-gray-500">当前视图没有发布任务。</div>
    @endforelse
</div>
<div class="border-t border-gray-100 px-5 py-4">{{ $batches->links() }}</div>
