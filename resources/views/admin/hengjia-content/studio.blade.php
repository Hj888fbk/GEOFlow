@extends('admin.layouts.app')

@php
    $currentAdmin = auth('admin')->user();
    $canPromote = $currentAdmin instanceof \App\Models\Admin && $currentAdmin->isSuperAdmin();
    $canPromoteWithDependencies = $canPromote && $categories->isNotEmpty() && $authors->isNotEmpty();
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'studio',
            'title' => __('hengjia_content.pages.studio.title'),
            'subtitle' => __('hengjia_content.pages.studio.subtitle'),
        ])

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="ready-generation-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="ready-generation-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.studio.ready_tasks') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.studio.ready_help') }}</p>
            </div>

            @if ($readyTasks->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'wand-sparkles', 'title' => __('hengjia_content.studio.no_ready')])</div>
            @else
                <div class="grid gap-4 p-5 lg:grid-cols-2 sm:p-6">
                    @foreach ($readyTasks as $task)
                        <article class="rounded-lg border border-gray-200 bg-gray-50/60 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs text-gray-400">#{{ $task->id }}</span>
                                @include('admin.hengjia-content._status-badge', ['status' => $task->status])
                                <span class="text-xs font-semibold text-gray-500">{{ __('hengjia_content.tasks.priority_value', ['priority' => $task->priority]) }}</span>
                            </div>
                            <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $task->title }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $task->primary_keyword }} · <span class="font-mono text-xs">{{ $task->page_role }}</span></p>

                            @if ($aiModels->isEmpty())
                                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">{{ __('hengjia_content.studio.no_models') }}</div>
                            @else
                                <form method="POST" action="{{ route('admin.hengjia-content.tasks.generate', $task) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                                    @csrf
                                    <label class="min-w-0 flex-1">
                                        <span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.studio.model') }}</span>
                                        <select name="ai_model_id" required class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-900">
                                            @foreach ($aiModels as $model)
                                                <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->model_id }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit" class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                        <i data-lucide="sparkles" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                                        {{ __('hengjia_content.studio.generate') }}
                                    </button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="master-review-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="master-review-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.studio.masters_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.studio.masters_help') }}</p>
            </div>

            @if ($masters->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'files', 'title' => __('hengjia_content.studio.no_masters')])</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($masters as $master)
                        @php
                            $package = (array) $master->package;
                            $seo = (array) ($package['seo'] ?? []);
                            $masterBlockers = (array) $master->blockers;
                            $canApproveMaster = $canPromote && $master->status === 'in_review' && $masterBlockers === [];
                            $canRefreshVariants = in_array((string) $master->status, ['in_review', 'approved', 'promoted'], true);
                        @endphp
                        <article class="px-5 py-6 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $master->id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $master->status])
                                        <span class="inline-flex min-h-6 items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 text-xs font-semibold text-gray-600">{{ $master->schema_version }}</span>
                                    </div>
                                    <h3 class="mt-2 text-lg font-semibold leading-7 text-gray-950">{{ $master->title ?: __('hengjia_content.common.not_recorded') }}</h3>
                                    <p class="mt-1 text-sm text-gray-600">{{ $master->task?->title ?? __('hengjia_content.common.not_recorded') }}</p>

                                    <dl class="mt-4 grid gap-3 rounded-md bg-gray-50 p-4 text-sm lg:grid-cols-3">
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.studio.h1') }}</dt><dd class="mt-1 leading-6 text-gray-700">{{ $master->h1 ?: data_get($seo, 'h1', __('hengjia_content.common.not_recorded')) }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.studio.slug') }}</dt><dd class="mt-1 break-all font-mono text-xs leading-6 text-gray-700">{{ $master->slug ?: data_get($seo, 'slug', __('hengjia_content.common.not_recorded')) }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.hash') }}</dt><dd class="mt-1 break-all font-mono text-[11px] leading-5 text-gray-500">{{ $master->package_hash ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                        <div class="lg:col-span-3"><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.studio.summary') }}</dt><dd class="mt-1 leading-6 text-gray-700">{{ $master->summary ?: data_get($seo, 'summary', __('hengjia_content.common.not_recorded')) }}</dd></div>
                                    </dl>

                                    @if ($masterBlockers !== [])
                                        <div class="mt-4 rounded-md border border-red-100 bg-red-50/60 p-3">@include('admin.hengjia-content._blockers', ['blockers' => $masterBlockers])</div>
                                    @endif

                                    @if ($master->article)
                                        <a href="{{ route('admin.articles.edit', ['articleId' => $master->article->id]) }}" class="mt-4 inline-flex min-h-9 items-center text-sm font-semibold text-blue-700 hover:text-blue-800">
                                            {{ __('hengjia_content.studio.article_record', [
                                                'id' => $master->article->id,
                                                'status' => __('hengjia_content.status.'.$master->article->status),
                                                'review' => __('hengjia_content.status.'.$master->article->review_status),
                                            ]) }}
                                            <i data-lucide="arrow-up-right" class="ml-1.5 h-4 w-4" aria-hidden="true"></i>
                                        </a>
                                    @endif

                                    <details class="mt-4 rounded-md border border-gray-200 bg-white px-4 py-3">
                                        <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.studio.package_details') }}</summary>
                                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                            <div><dt class="text-xs text-gray-400">body_sections</dt><dd class="mt-1 font-mono text-gray-700">{{ count((array) ($package['body_sections'] ?? [])) }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">parameters</dt><dd class="mt-1 font-mono text-gray-700">{{ count((array) ($package['parameters'] ?? [])) }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">faq</dt><dd class="mt-1 font-mono text-gray-700">{{ count((array) ($package['faq'] ?? [])) }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">sources</dt><dd class="mt-1 font-mono text-gray-700">{{ count((array) ($package['sources'] ?? [])) }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">schema_nodes</dt><dd class="mt-1 font-mono text-gray-700">{{ count((array) ($package['schema_nodes'] ?? [])) }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">provider / model</dt><dd class="mt-1 text-gray-700">{{ collect([$master->provider, $master->model])->filter()->join(' / ') ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">generated_at</dt><dd class="mt-1 text-gray-700">{{ $master->generated_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                            <div><dt class="text-xs text-gray-400">approved_at</dt><dd class="mt-1 text-gray-700">{{ $master->approved_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                        </dl>
                                    </details>
                                </div>

                                <div class="w-full shrink-0 space-y-3 xl:w-80">
                                    @if ($canApproveMaster)
                                        <form method="POST" action="{{ route('admin.hengjia-content.masters.approve', $master) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                                <i data-lucide="badge-check" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.studio.approve_master') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($canRefreshVariants)
                                        <form method="POST" action="{{ route('admin.hengjia-content.masters.variants', $master) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">
                                                <i data-lucide="panels-top-left" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.studio.rebuild_variants') }}
                                            </button>
                                        </form>
                                    @endif

                                    @if ($master->status === 'approved')
                                        @if ($canPromoteWithDependencies)
                                            <details class="rounded-md border border-blue-200 bg-blue-50/50 p-4">
                                                <summary class="cursor-pointer rounded-sm text-sm font-semibold text-blue-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.studio.promote_title') }}</summary>
                                                <p class="mt-2 text-xs leading-5 text-blue-800">{{ __('hengjia_content.studio.promote_help') }}</p>
                                                <form method="POST" action="{{ route('admin.hengjia-content.masters.promote', $master) }}" class="mt-4 space-y-3">
                                                    @csrf
                                                    <label class="block"><span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.studio.category') }}</span><select name="category_id" required class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-2.5 text-sm">@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                                                    <label class="block"><span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.studio.author') }}</span><select name="author_id" required class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-2.5 text-sm">@foreach($authors as $author)<option value="{{ $author->id }}">{{ $author->name }}</option>@endforeach</select></label>
                                                    <label class="block"><span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.studio.existing_task') }}</span><select name="task_id" class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-2.5 text-sm"><option value="">{{ __('hengjia_content.common.none') }}</option>@foreach($existingTasks as $existingTask)<option value="{{ $existingTask->id }}">{{ $existingTask->name }}</option>@endforeach</select></label>
                                                    <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">{{ __('hengjia_content.studio.promote') }}</button>
                                                </form>
                                            </details>
                                        @else
                                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm leading-5 text-amber-800">{{ __('hengjia_content.studio.missing_article_dependencies') }}</div>
                                        @endif
                                    @endif

                                    @if ($canPromote && $master->article_id && (array) $master->article_snapshot !== [] && $master->status === 'promoted')
                                        <form method="POST" action="{{ route('admin.hengjia-content.masters.rollback', $master) }}" onsubmit="return confirm(@js(__('hengjia_content.studio.rollback_confirm')))">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-red-200 bg-white px-4 text-sm font-semibold text-red-700 transition duration-[120ms] hover:bg-red-50 active:scale-[.98]">
                                                <i data-lucide="undo-2" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.studio.rollback') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($masters->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $masters->links() }}</div>@endif
            @endif
        </section>
    </div>
@endsection
