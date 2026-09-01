@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'previews',
            'title' => __('hengjia_content.pages.previews.title'),
            'subtitle' => __('hengjia_content.pages.previews.subtitle'),
        ])

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="channel-preview-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="channel-preview-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.previews.title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.previews.help') }}</p>
            </div>

            @if ($variants->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'panels-top-left', 'title' => __('hengjia_content.previews.empty')])</div>
            @else
                @php $seenMasterIds = []; @endphp
                <div class="grid gap-4 p-5 lg:grid-cols-2 sm:p-6">
                    @foreach ($variants as $variant)
                        @php
                            $payload = (array) $variant->payload;
                            $payloadTitle = collect([
                                $payload['title'] ?? null,
                                $payload['product_title'] ?? null,
                                $payload['subject'] ?? null,
                                $payload['name'] ?? null,
                            ])->first(static fn (mixed $value): bool => trim((string) $value) !== '');
                            $payloadSummary = collect([
                                $payload['summary'] ?? null,
                                $payload['abstract'] ?? null,
                                $payload['description'] ?? null,
                                $payload['excerpt'] ?? null,
                                $payload['meta_description'] ?? null,
                                $payload['detail_markdown'] ?? null,
                                $payload['description_markdown'] ?? null,
                            ])->first(static fn (mixed $value): bool => trim((string) $value) !== '');
                            $showRefresh = $variant->master && ! in_array((int) $variant->master->id, $seenMasterIds, true);
                            if ($showRefresh) {
                                $seenMasterIds[] = (int) $variant->master->id;
                            }
                        @endphp
                        <article class="flex min-w-0 flex-col rounded-lg border {{ (array) $variant->blockers !== [] ? 'border-red-200' : 'border-gray-200' }} bg-white">
                            <div class="border-b border-gray-100 px-4 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-xs text-gray-400">#{{ $variant->id }}</span>
                                            @include('admin.hengjia-content._status-badge', ['status' => $variant->status])
                                        </div>
                                        <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $variant->adapter?->name ?? $variant->channel_key }}</h3>
                                        <p class="mt-1 text-sm leading-5 text-gray-500">{{ $variant->master?->title ?? __('hengjia_content.common.not_recorded') }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-md bg-gray-100 px-2 py-1 font-mono text-[11px] font-semibold text-gray-600">{{ $variant->content_type }}</span>
                                </div>
                            </div>

                            <div class="flex-1 space-y-4 px-4 py-4">
                                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.account') }}</dt>
                                        <dd class="mt-1 text-gray-800">
                                            @if ($variant->account)
                                                {{ $variant->account->account_name }}
                                            @else
                                                <span class="font-semibold text-red-700">{{ __('hengjia_content.previews.account_missing') }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.adapter') }}</dt><dd class="mt-1 text-gray-800">{{ $variant->adapter?->key ?? $variant->channel_key }} · v{{ $variant->adapter_version ?: $variant->adapter?->version }}</dd></div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.scheduled_at') }}</dt><dd class="mt-1 text-gray-800">{{ $variant->scheduled_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.hash') }}</dt><dd class="mt-1 break-all font-mono text-[11px] leading-5 text-gray-500">{{ $variant->payload_hash ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                </dl>

                                <div class="rounded-md bg-gray-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('hengjia_content.previews.title_field') }}</p>
                                    <p class="mt-1 text-sm font-semibold leading-6 text-gray-900">{{ $payloadTitle ?: __('hengjia_content.common.not_recorded') }}</p>
                                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('hengjia_content.previews.summary_field') }}</p>
                                    <p class="mt-1 text-sm leading-6 text-gray-700">{{ $payloadSummary ? \Illuminate\Support\Str::limit((string) $payloadSummary, 420) : __('hengjia_content.common.not_recorded') }}</p>
                                </div>

                                @if ((array) $variant->blockers !== [])
                                    <div class="rounded-md border border-red-100 bg-red-50/60 p-3">@include('admin.hengjia-content._blockers', ['blockers' => $variant->blockers])</div>
                                @endif

                                <details class="rounded-md border border-gray-200 bg-white px-4 py-3">
                                    <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.previews.payload_details') }}</summary>
                                    @if ($payload === [])
                                        <p class="mt-3 text-sm text-gray-500">{{ __('hengjia_content.previews.no_payload') }}</p>
                                    @else
                                        <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-950 p-4 font-mono text-[11px] leading-5 text-gray-100">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                </details>
                            </div>

                            @if ($showRefresh)
                                <div class="border-t border-gray-100 px-4 py-3">
                                    <form method="POST" action="{{ route('admin.hengjia-content.masters.variants', $variant->master) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                            <i data-lucide="refresh-cw" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.previews.refresh_master') }}
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($variants->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $variants->links() }}</div>@endif
            @endif
        </section>
    </div>
@endsection
