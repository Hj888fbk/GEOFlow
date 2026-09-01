@extends('admin.layouts.app')

@php
    $manualByVariant = $manualPublications->keyBy('channel_variant_id');
    $currentAdmin = auth('admin')->user();
    $canManageProtectedWorkflows = $currentAdmin instanceof \App\Models\Admin && $currentAdmin->canManageProtectedWorkflows();
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'publishing',
            'title' => __('hengjia_content.pages.publishing.title'),
            'subtitle' => __('hengjia_content.pages.publishing.subtitle'),
        ])

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="publishing-queue-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="publishing-queue-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.publishing.title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.publishing.help') }}</p>
            </div>

            @if ($variants->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'send', 'title' => __('hengjia_content.publishing.empty')])</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($variants as $variant)
                        @php
                            $article = $variant->master?->article;
                            $isWordPress = $variant->adapter?->key === \App\Models\PlatformAdapter::WORDPRESS;
                            $canApproveVariant = $canManageProtectedWorkflows
                                && $variant->status === 'draft'
                                && $variant->master?->status === 'promoted'
                                && (array) $variant->blockers === [];
                            $articlePassesPrepareGate = $isWordPress
                                ? $article && in_array((string) $article->status, ['published', 'private'], true)
                                : $article && in_array((string) $article->review_status, ['approved', 'auto_approved'], true);
                            $canPrepareVariant = $canManageProtectedWorkflows
                                && $variant->status === 'approved'
                                && $variant->account
                                && $articlePassesPrepareGate;
                            $canReadback = $canManageProtectedWorkflows
                                && $variant->account
                                && in_array((string) $variant->status, ['queued', 'awaiting_manual_confirmation', 'published', 'failed', 'outcome_unknown'], true);
                            $manualPublication = $manualByVariant->get($variant->id);
                            $receipt = (array) $variant->receipt;
                        @endphp
                        <article class="px-5 py-5 sm:px-6">
                            <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $variant->id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $variant->status])
                                        <span class="rounded-md bg-gray-100 px-2 py-1 font-mono text-[11px] font-semibold text-gray-600">{{ $variant->content_type }}</span>
                                    </div>
                                    <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $variant->adapter?->name ?? $variant->channel_key }} · {{ $variant->master?->title ?? __('hengjia_content.common.not_recorded') }}</h3>

                                    <dl class="mt-4 grid gap-3 rounded-md bg-gray-50 p-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.account') }}</dt><dd class="mt-1 text-gray-800">{{ $variant->account?->account_name ?? __('hengjia_content.previews.account_missing') }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.adapter') }}</dt><dd class="mt-1 text-gray-800">{{ $variant->adapter?->key ?? $variant->channel_key }} · v{{ $variant->adapter_version }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">Article</dt><dd class="mt-1 text-gray-800">@if($article)#{{ $article->id }} · {{ __('hengjia_content.status.'.$article->status) }} / {{ __('hengjia_content.status.'.$article->review_status) }}@else{{ __('hengjia_content.common.not_recorded') }}@endif</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.last_readback') }}</dt><dd class="mt-1 text-gray-800">{{ $variant->last_readback_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                    </dl>

                                    @if ((array) $variant->blockers !== [])
                                        <div class="mt-4 rounded-md border border-red-100 bg-red-50/60 p-3">@include('admin.hengjia-content._blockers', ['blockers' => $variant->blockers])</div>
                                    @endif

                                    @if ($variant->status === 'approved' && ! $articlePassesPrepareGate)
                                        <div class="mt-4 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm leading-6 text-amber-800" role="status">
                                            <i data-lucide="lock-keyhole" class="mt-1 h-4 w-4 shrink-0" aria-hidden="true"></i>
                                            <span>{{ $isWordPress ? __('hengjia_content.publishing.wordpress_gate') : __('hengjia_content.publishing.external_gate') }}</span>
                                        </div>
                                    @endif

                                    @if (! $isWordPress && in_array((string) $variant->status, ['approved', 'awaiting_manual_confirmation'], true))
                                        <div class="mt-4 flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm leading-6 text-blue-800" role="note">
                                            <i data-lucide="user-check" class="mt-1 h-4 w-4 shrink-0" aria-hidden="true"></i>
                                            <span>{{ __('hengjia_content.publishing.manual_required') }}</span>
                                        </div>
                                    @endif

                                    @if ($variant->remote_url)
                                        <a href="{{ $variant->remote_url }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex min-h-9 items-center break-all text-sm font-semibold text-blue-700 hover:text-blue-800">
                                            {{ $variant->remote_url }}<i data-lucide="external-link" class="ml-1.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                                        </a>
                                    @endif

                                    <details class="mt-4 rounded-md border border-gray-200 bg-white px-4 py-3">
                                        <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.publishing.receipt') }}</summary>
                                        @if ($receipt === [])
                                            <p class="mt-3 text-sm text-gray-500">{{ __('hengjia_content.publishing.no_receipt') }}</p>
                                        @else
                                            <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-950 p-4 font-mono text-[11px] leading-5 text-gray-100">{{ json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                    </details>
                                </div>

                                <div class="w-full shrink-0 space-y-3 xl:w-64">
                                    @if ($canApproveVariant)
                                        <form method="POST" action="{{ route('admin.hengjia-content.variants.approve', $variant) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                                <i data-lucide="badge-check" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.publishing.approve_variant') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($canPrepareVariant)
                                        <form method="POST" action="{{ route('admin.hengjia-content.variants.prepare', $variant) }}" onsubmit="return confirm(@js($isWordPress ? __('hengjia_content.publishing.prepare_confirm_wordpress') : __('hengjia_content.publishing.prepare_confirm_external')))">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                                <i data-lucide="send" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.publishing.prepare_variant') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($canReadback)
                                        <form method="POST" action="{{ route('admin.hengjia-content.variants.readback', $variant) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                                <i data-lucide="refresh-cw" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.publishing.readback') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($manualPublication)
                                        <a href="{{ route('admin.manual-publications.show', ['manualPublicationId' => $manualPublication->id]) }}" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                            <i data-lucide="clipboard-check" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.publishing.manual_work_order') }}
                                        </a>
                                        <div class="text-center">@include('admin.hengjia-content._status-badge', ['status' => $manualPublication->status])</div>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($variants->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $variants->links() }}</div>@endif
            @endif
        </section>
    </div>
@endsection
