@extends('admin.layouts.app')

@php
    $stats = (array) ($dashboard['stats'] ?? []);
    $tasks = collect($dashboard['tasks'] ?? []);
    $evidenceGaps = collect($dashboard['evidence_gaps'] ?? []);
    $recentRuns = collect($dashboard['recent_runs'] ?? []);
    $pipeline = (array) ($dashboard['pipeline'] ?? []);
    $governance = (array) ($dashboard['governance'] ?? []);
    $statCards = [
        ['key' => 'today_tasks', 'icon' => 'calendar-clock', 'tone' => 'text-blue-700 bg-blue-50'],
        ['key' => 'blocked_tasks', 'icon' => 'shield-alert', 'tone' => 'text-red-700 bg-red-50'],
        ['key' => 'review_masters', 'icon' => 'scan-text', 'tone' => 'text-amber-700 bg-amber-50'],
        ['key' => 'awaiting_manual', 'icon' => 'mouse-pointer-click', 'tone' => 'text-amber-700 bg-amber-50'],
        ['key' => 'account_attention', 'icon' => 'badge-alert', 'tone' => 'text-red-700 bg-red-50'],
        ['key' => 'approved_sources', 'icon' => 'file-check-2', 'tone' => 'text-emerald-700 bg-emerald-50'],
    ];
    $pipelineSteps = [
        'candidates', 'generated', 'approved', 'promoted_to_article', 'remote_published',
        'crawl_confirmed', 'indexed_confirmed', 'ai_mentioned', 'leads_attributed',
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'today',
            'title' => __('hengjia_content.pages.today.title'),
            'subtitle' => __('hengjia_content.pages.today.subtitle'),
        ])

        <section class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6" aria-label="{{ __('hengjia_content.pages.today.title') }}">
            @foreach ($statCards as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-xs font-semibold leading-5 text-gray-500">{{ __('hengjia_content.today.stats.'.$card['key']) }}</p>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md {{ $card['tone'] }}" aria-hidden="true">
                            <i data-lucide="{{ $card['icon'] }}" class="h-4 w-4"></i>
                        </span>
                    </div>
                    <p class="mt-4 font-mono text-2xl font-semibold tabular-nums text-gray-950">{{ number_format((int) ($stats[$card['key']] ?? 0)) }}</p>
                </article>
            @endforeach
        </section>

        <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(20rem,.85fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="today-queue-title">
                <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div>
                        <h2 id="today-queue-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.today.decision_queue') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-gray-500">{{ __('hengjia_content.today.decision_queue_hint') }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.hengjia-content.plan-daily') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98] motion-reduce:transition-none motion-reduce:active:scale-100">
                            <i data-lucide="calendar-plus" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                            {{ __('hengjia_content.today.plan_daily') }}
                        </button>
                    </form>
                </div>
                <p class="border-b border-gray-100 bg-blue-50/50 px-5 py-2.5 text-xs leading-5 text-blue-800 sm:px-6">{{ __('hengjia_content.today.plan_daily_hint') }}</p>

                @if ($tasks->isEmpty())
                    <div class="p-5 sm:p-6">
                        @include('admin.hengjia-content._empty-state', [
                            'icon' => 'clipboard-check',
                            'title' => __('hengjia_content.today.no_tasks'),
                        ])
                    </div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($tasks as $task)
                            @php
                                $taskBlockers = collect((array) $task->blockers);
                                $channelKeys = collect((array) $task->target_channels)
                                    ->map(static fn (mixed $target): string => is_array($target) ? (string) ($target['channel_key'] ?? '') : (string) $target)
                                    ->filter()
                                    ->values();
                            @endphp
                            <article class="px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-xs text-gray-400">#{{ $task->id }}</span>
                                            @include('admin.hengjia-content._status-badge', ['status' => $task->status])
                                            <span class="text-xs font-semibold text-gray-500">{{ __('hengjia_content.tasks.priority_value', ['priority' => $task->priority]) }}</span>
                                        </div>
                                        <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $task->title }}</h3>
                                        <p class="mt-1 text-sm text-gray-600">{{ $task->primary_keyword }} · {{ $task->page_role }}</p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <a href="{{ route('admin.hengjia-content.evidence') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                            {{ __('hengjia_content.tasks.open_evidence') }}
                                        </a>
                                        <a href="{{ route('admin.hengjia-content.studio') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                            {{ __('hengjia_content.tasks.open_studio') }}
                                        </a>
                                    </div>
                                </div>
                                <dl class="mt-3 grid gap-2 text-xs text-gray-500 sm:grid-cols-3">
                                    <div><dt class="sr-only">{{ __('hengjia_content.tasks.fields.due_on') }}</dt><dd>{{ __('hengjia_content.tasks.due_value', ['date' => $task->due_on?->format('Y-m-d') ?? __('hengjia_content.common.not_recorded')]) }}</dd></div>
                                    <div><dt class="sr-only">{{ __('hengjia_content.common.account') }}</dt><dd>{{ $task->assignee?->display_name ?: $task->assignee?->username ?: __('hengjia_content.common.unassigned') }}</dd></div>
                                    <div><dt class="sr-only">{{ __('hengjia_content.common.channel') }}</dt><dd>{{ $channelKeys->isEmpty() ? __('hengjia_content.common.none') : $channelKeys->join(' · ') }}</dd></div>
                                </dl>
                                @if ($taskBlockers->isNotEmpty())
                                    <div class="mt-3 rounded-md border border-red-100 bg-red-50/60 p-3">
                                        @include('admin.hengjia-content._blockers', ['blockers' => $taskBlockers])
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <aside class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="evidence-gaps-title">
                <div class="border-b border-gray-100 px-5 py-5">
                    <h2 id="evidence-gaps-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.today.evidence_gaps') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.today.evidence_gaps_hint') }}</p>
                </div>
                @if ($evidenceGaps->isEmpty())
                    <div class="p-5">
                        @include('admin.hengjia-content._empty-state', [
                            'icon' => 'shield-check',
                            'title' => __('hengjia_content.today.no_gaps'),
                        ])
                    </div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($evidenceGaps as $gap)
                            <div class="px-5 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="text-sm font-semibold leading-5 text-gray-900">{{ $gap->title }}</h3>
                                    <span class="font-mono text-xs text-gray-400">#{{ $gap->id }}</span>
                                </div>
                                <div class="mt-3">
                                    @include('admin.hengjia-content._blockers', ['blockers' => $gap->blockers])
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </aside>
        </div>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-5 shadow-sm sm:px-6" aria-labelledby="pipeline-title">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="pipeline-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.today.pipeline_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.today.pipeline_hint') }}</p>
                </div>
                <span class="inline-flex items-center gap-2 text-xs font-medium text-gray-500">
                    <span class="h-2 w-2 rounded-full bg-gray-300"></span>{{ __('hengjia_content.common.not_available') }}
                </span>
            </div>
            <ol class="mt-5 grid gap-2 sm:grid-cols-3 xl:grid-cols-9" aria-label="{{ __('hengjia_content.today.pipeline_title') }}">
                @foreach ($pipelineSteps as $index => $step)
                    @php $value = $pipeline[$step] ?? null; @endphp
                    <li class="relative rounded-md border {{ $value === null ? 'border-dashed border-gray-300 bg-gray-50' : 'border-gray-200 bg-white' }} px-3 py-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-[11px] font-semibold text-gray-400">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="font-mono text-lg font-semibold tabular-nums {{ $value === null ? 'text-gray-400' : 'text-gray-950' }}">
                                {{ $value === null ? '—' : number_format((int) $value) }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-5 text-gray-600">{{ __('hengjia_content.today.pipeline.'.$step) }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,.65fr)]">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="recent-runs-title">
                <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                    <h2 id="recent-runs-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.today.recent_runs') }}</h2>
                </div>
                @if ($recentRuns->isEmpty())
                    <div class="p-5 sm:p-6">
                        @include('admin.hengjia-content._empty-state', ['icon' => 'history', 'title' => __('hengjia_content.today.no_runs')])
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-3 sm:px-6">{{ __('hengjia_content.common.id') }}</th>
                                    <th class="px-5 py-3">{{ __('hengjia_content.navigation.tasks') }}</th>
                                    <th class="px-5 py-3">{{ __('hengjia_content.common.status') }}</th>
                                    <th class="px-5 py-3">{{ __('hengjia_content.studio.model') }}</th>
                                    <th class="px-5 py-3 sm:px-6">{{ __('hengjia_content.common.created_at') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach ($recentRuns as $run)
                                    <tr>
                                        <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-gray-500 sm:px-6">#{{ $run->id }}</td>
                                        <td class="min-w-56 px-5 py-3 font-medium text-gray-900">{{ $run->task?->title ?: __('hengjia_content.common.not_recorded') }}</td>
                                        <td class="whitespace-nowrap px-5 py-3">@include('admin.hengjia-content._status-badge', ['status' => $run->status])</td>
                                        <td class="whitespace-nowrap px-5 py-3 text-gray-600">{{ collect([$run->provider, $run->model])->filter()->join(' / ') ?: __('hengjia_content.common.not_recorded') }}</td>
                                        <td class="whitespace-nowrap px-5 py-3 text-gray-500 sm:px-6">{{ $run->started_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <aside class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="governance-title">
                <h2 id="governance-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.today.governance') }}</h2>
                <dl class="mt-5 space-y-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm font-medium text-gray-600">{{ __('hengjia_content.today.active_prompt_recipes') }}</dt>
                        <dd class="mt-2 font-mono text-2xl font-semibold tabular-nums text-gray-950">{{ number_format((int) ($governance['active_prompt_recipes'] ?? 0)) }}</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm font-medium text-gray-600">{{ __('hengjia_content.today.publishable_claims') }}</dt>
                        <dd class="mt-2 font-mono text-2xl font-semibold tabular-nums text-gray-950">{{ number_format((int) ($governance['publishable_claims'] ?? 0)) }}</dd>
                    </div>
                </dl>
                <a href="{{ route('admin.hengjia-content.governance') }}" class="mt-5 inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                    {{ __('hengjia_content.navigation.governance') }}
                    <i data-lucide="arrow-right" class="ml-2 h-4 w-4" aria-hidden="true"></i>
                </a>
            </aside>
        </div>
    </div>
@endsection
