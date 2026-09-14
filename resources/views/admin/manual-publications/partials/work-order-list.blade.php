@if($publications->isNotEmpty())
    <div class="border-t border-gray-100">
        <div class="bg-gray-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">账号进度与高级工单</div>
        <div class="divide-y divide-gray-100">
            @foreach($publications as $publication)
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $publication->id]) }}" class="text-sm font-semibold text-blue-700">工单 #{{ $publication->id }}</a>
                        <div class="mt-1 truncate text-sm text-gray-800">{{ $publication->article?->title ?? \Illuminate\Support\Str::limit((string) $publication->content, 80) }}</div>
                        @if(filled($publication->content) && $publication->article)
                            <div class="mt-1 truncate text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $publication->content, 120) }}</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">{{ $publication->platformDisplayName() }} · {{ $publication->account?->account_name ?? '未绑定账号' }} · {{ __('admin.manual_publications.status.'.$publication->status) }}</div>
                    </div>
                    <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $publication->id]) }}" class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">查看</a>
                </div>
            @endforeach
        </div>
        <div class="border-t border-gray-100 px-5 py-4">{{ $publications->links() }}</div>
    </div>
@endif
