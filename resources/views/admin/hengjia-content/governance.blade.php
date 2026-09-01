@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'governance',
            'title' => __('hengjia_content.pages.governance.title'),
            'subtitle' => __('hengjia_content.pages.governance.subtitle'),
        ])

        <section class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-5 shadow-sm sm:px-6" aria-labelledby="governance-principles-title">
            <h2 id="governance-principles-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.governance.principles_title') }}</h2>
            <div class="mt-4 grid gap-3 lg:grid-cols-3">
                @foreach ([['facts', 'shield-check'], ['learning', 'scan-search'], ['versions', 'git-compare-arrows']] as [$principle, $icon])
                    <article class="flex items-start gap-3 rounded-md border border-gray-200 bg-gray-50/60 p-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-white text-blue-700 shadow-sm" aria-hidden="true"><i data-lucide="{{ $icon }}" class="h-4 w-4"></i></span>
                        <p class="text-sm leading-6 text-gray-700">{{ __('hengjia_content.governance.principles.'.$principle) }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="prompt-recipes-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="prompt-recipes-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.governance.prompts_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.governance.prompts_help') }}</p>
            </div>

            @if ($prompts->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'message-square-dashed', 'title' => __('hengjia_content.governance.no_prompts')])</div>
            @else
                <div class="grid gap-4 p-5 lg:grid-cols-2 sm:p-6">
                    @foreach ($prompts as $prompt)
                        <article class="min-w-0 rounded-lg border {{ $prompt->status === \App\Models\PromptRecipeVersion::STATUS_ACTIVE ? 'border-blue-200' : 'border-gray-200' }} bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $prompt->id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $prompt->status])
                                    </div>
                                    <h3 class="mt-2 break-words text-base font-semibold text-gray-950">{{ $prompt->recipe_key }}</h3>
                                </div>
                                <span class="shrink-0 rounded-md bg-gray-100 px-2 py-1 font-mono text-xs font-semibold text-gray-600">v{{ $prompt->version }}</span>
                            </div>

                            <dl class="mt-4 space-y-3 text-sm">
                                <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.governance.change_notes') }}</dt><dd class="mt-1 whitespace-pre-line leading-6 text-gray-700">{{ $prompt->change_notes ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                <div><dt class="text-xs font-medium text-gray-400">activated_at</dt><dd class="mt-1 text-gray-700">{{ $prompt->activated_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                            </dl>

                            <details class="mt-4 rounded-md border border-gray-200 bg-gray-50/40 px-4 py-3">
                                <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.governance.template') }}</summary>
                                <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-950 p-4 font-mono text-[11px] leading-5 text-gray-100">{{ $prompt->template }}</pre>
                            </details>
                            <details class="mt-3 rounded-md border border-gray-200 bg-gray-50/40 px-4 py-3">
                                <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.governance.contracts') }}</summary>
                                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-950 p-3 font-mono text-[11px] leading-5 text-gray-100">{{ json_encode($prompt->input_contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-950 p-3 font-mono text-[11px] leading-5 text-gray-100">{{ json_encode($prompt->output_contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </details>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="governed-sources-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="governed-sources-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.governance.sources_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.governance.sources_help') }}</p>
            </div>

            @if ($sources->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'database-zap', 'title' => __('hengjia_content.governance.no_sources')])</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3 sm:px-6">{{ __('hengjia_content.governance.source_path') }}</th>
                                <th class="px-5 py-3">{{ __('hengjia_content.evidence.fields.evidence_status') }}</th>
                                <th class="px-5 py-3">{{ __('hengjia_content.evidence.fields.public_permission') }}</th>
                                <th class="px-5 py-3">{{ __('hengjia_content.common.hash') }}</th>
                                <th class="px-5 py-3 sm:px-6">{{ __('hengjia_content.governance.last_synced') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($sources as $source)
                                <tr class="align-top">
                                    <td class="min-w-72 px-5 py-4 sm:px-6"><p class="break-all font-medium leading-6 text-gray-900">{{ $source->relative_path }}</p><p class="mt-1 font-mono text-[11px] text-gray-400">{{ $source->source_root_key }} · {{ $source->source_version ?: __('hengjia_content.common.not_recorded') }}</p></td>
                                    <td class="whitespace-nowrap px-5 py-4">@if($source->evidence_status) @include('admin.hengjia-content._status-badge', ['status' => $source->evidence_status, 'kind' => 'evidence']) @else {{ __('hengjia_content.common.not_recorded') }} @endif</td>
                                    <td class="whitespace-nowrap px-5 py-4">@include('admin.hengjia-content._status-badge', ['status' => $source->public_permission, 'kind' => 'permission'])</td>
                                    <td class="max-w-60 break-all px-5 py-4 font-mono text-[11px] leading-5 text-gray-500">{{ $source->sha256 }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-gray-500 sm:px-6">{{ $source->last_synced_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($sources->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $sources->links() }}</div>@endif
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="generation-runs-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="generation-runs-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.governance.runs_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.governance.runs_help') }}</p>
            </div>

            @if ($runs->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'history', 'title' => __('hengjia_content.governance.no_runs')])</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($runs as $run)
                        <article class="px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $run->id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $run->status])
                                        <span class="font-mono text-[11px] text-gray-500">{{ $run->run_key }}</span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold text-gray-900">Task #{{ $run->content_task_id }} · Master #{{ $run->content_master_id ?: '—' }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ collect([$run->provider, $run->model])->filter()->join(' / ') ?: __('hengjia_content.common.not_recorded') }}</p>
                                </div>
                                <div class="shrink-0 text-xs text-gray-500 lg:text-right">
                                    <p>{{ $run->started_at?->format('Y-m-d H:i:s') ?? __('hengjia_content.common.not_recorded') }}</p>
                                    @if ($run->completed_at)<p class="mt-1">{{ $run->completed_at->format('Y-m-d H:i:s') }}</p>@endif
                                </div>
                            </div>
                            <dl class="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 text-xs lg:grid-cols-2">
                                <div><dt class="font-medium text-gray-400">input_hash</dt><dd class="mt-1 break-all font-mono text-gray-600">{{ $run->input_hash ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                <div><dt class="font-medium text-gray-400">output_hash</dt><dd class="mt-1 break-all font-mono text-gray-600">{{ $run->output_hash ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                            </dl>
                            @if ($run->error_message)
                                <div class="mt-3 rounded-md border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-800"><span class="font-semibold">{{ __('hengjia_content.governance.error') }} {{ $run->error_code }}:</span> {{ $run->error_message }}</div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
