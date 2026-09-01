@extends('admin.layouts.app')

@php
    $oldTargets = collect((array) old('target_channels', []))
        ->filter(static fn (mixed $target): bool => is_array($target) && trim((string) ($target['channel_key'] ?? '')) !== '')
        ->keyBy(static fn (array $target): string => (string) $target['channel_key']);
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'tasks',
            'title' => __('hengjia_content.pages.tasks.title'),
            'subtitle' => __('hengjia_content.pages.tasks.subtitle'),
        ])

        <details class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" @if($tasks->isEmpty() || old()) open @endif>
            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 rounded-sm px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:px-6 [&::-webkit-details-marker]:hidden">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.tasks.form_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.tasks.form_help') }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-700" aria-hidden="true">
                    <i data-lucide="plus" class="h-5 w-5"></i>
                </span>
            </summary>

            <form method="POST" action="{{ route('admin.hengjia-content.tasks.store') }}" class="border-t border-gray-100 px-5 py-5 sm:px-6" data-hengjia-task-form>
                @csrf
                <input type="hidden" name="product_key" value="rubber-joint">

                <div class="grid gap-5 lg:grid-cols-2">
                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.title') }}</span>
                        <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required autocomplete="off" placeholder="{{ __('hengjia_content.tasks.placeholders.title') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.audience') }}</span>
                        <input type="text" name="audience" value="{{ old('audience') }}" maxlength="160" required placeholder="{{ __('hengjia_content.tasks.placeholders.audience') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.intent') }}</span>
                        <input type="text" name="intent" value="{{ old('intent') }}" maxlength="160" required placeholder="{{ __('hengjia_content.tasks.placeholders.intent') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.page_role') }}</span>
                        <input type="text" name="page_role" value="{{ old('page_role') }}" maxlength="80" required placeholder="{{ __('hengjia_content.tasks.placeholders.page_role') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 font-mono text-sm text-gray-950 placeholder:font-sans placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.primary_keyword') }}</span>
                        <input type="text" name="primary_keyword" value="{{ old('primary_keyword') }}" maxlength="160" required placeholder="{{ __('hengjia_content.tasks.placeholders.primary_keyword') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                    </label>

                    <fieldset class="lg:col-span-2">
                        <legend class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.secondary_keywords') }}</legend>
                        <div class="mt-2 grid gap-3 md:grid-cols-3">
                            @for ($keywordIndex = 0; $keywordIndex < 3; $keywordIndex++)
                                <input type="text" name="secondary_keywords[]" value="{{ old('secondary_keywords.'.$keywordIndex) }}" maxlength="160" aria-label="{{ __('hengjia_content.tasks.fields.secondary_keywords') }} {{ $keywordIndex + 1 }}" placeholder="{{ __('hengjia_content.tasks.placeholders.secondary_keyword') }}" class="min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 placeholder:text-gray-400 focus:border-blue-500 focus:bg-white">
                            @endfor
                        </div>
                    </fieldset>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.priority') }}</span>
                        <select name="priority" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            @foreach ([1, 2, 3, 4, 5] as $priority)
                                <option value="{{ $priority }}" @selected((int) old('priority', 3) === $priority)>{{ $priority }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.tasks.fields.due_on') }}</span>
                        <input type="date" name="due_on" value="{{ old('due_on', now()->toDateString()) }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>
                </div>

                <fieldset class="mt-6">
                    <legend class="text-sm font-semibold text-gray-900">{{ __('hengjia_content.tasks.fields.target_channels') }}</legend>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.tasks.channel_help') }}</p>

                    @if ($adapters->isEmpty())
                        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">
                            {{ __('hengjia_content.tasks.no_adapters') }}
                        </div>
                    @else
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            @foreach ($adapters as $adapter)
                                @php
                                    $oldTarget = (array) ($oldTargets->get($adapter->key) ?? []);
                                    $isSelected = $oldTargets->has($adapter->key);
                                    $adapterAccounts = $accounts->where('platform_adapter_id', $adapter->id)->values();
                                    $contentTypes = collect((array) $adapter->supported_content_types)->filter()->values();
                                @endphp
                                <div class="rounded-md border border-gray-200 bg-gray-50/60 p-4" data-channel-card>
                                    <label class="flex min-h-10 cursor-pointer items-center gap-3">
                                        <input type="checkbox" value="{{ $adapter->key }}" class="h-4 w-4 rounded border-gray-300 text-blue-600" data-channel-toggle @checked($isSelected)>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900">{{ $adapter->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $adapter->key }} · v{{ $adapter->version }}</span>
                                        </span>
                                    </label>
                                    <input type="hidden" name="target_channels[{{ $loop->index }}][channel_key]" value="{{ $adapter->key }}" data-channel-field @disabled(!$isSelected)>

                                    <div class="mt-3 grid gap-3 sm:grid-cols-2" data-channel-fields>
                                        <label class="block">
                                            <span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.tasks.fields.account') }}</span>
                                            <select name="target_channels[{{ $loop->index }}][account_id]" class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-2.5 text-sm text-gray-900" data-channel-field @disabled(!$isSelected)>
                                                <option value="">{{ __('hengjia_content.tasks.no_account') }}</option>
                                                @foreach ($adapterAccounts as $account)
                                                    <option value="{{ $account->id }}" @selected((string) ($oldTarget['account_id'] ?? '') === (string) $account->id)>{{ $account->account_name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.tasks.fields.content_type') }}</span>
                                            <select name="target_channels[{{ $loop->index }}][content_type]" class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-white px-2.5 text-sm text-gray-900" data-channel-field @disabled(!$isSelected || $contentTypes->isEmpty())>
                                                @foreach ($contentTypes as $contentType)
                                                    <option value="{{ $contentType }}" @selected((string) ($oldTarget['content_type'] ?? '') === (string) $contentType)>{{ $contentType }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 hidden text-sm font-medium text-red-700" data-channel-error role="alert">{{ __('hengjia_content.tasks.select_one_channel') }}</p>
                    @endif
                </fieldset>

                <div class="mt-6 flex justify-end border-t border-gray-100 pt-5">
                    <button type="submit" @disabled($adapters->isEmpty()) class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-600 px-5 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98] disabled:cursor-not-allowed disabled:bg-gray-300 motion-reduce:transition-none motion-reduce:active:scale-100">
                        <i data-lucide="save" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                        {{ __('hengjia_content.tasks.save_task') }}
                    </button>
                </div>
            </form>
        </details>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="task-ledger-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="task-ledger-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.tasks.list_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.tasks.list_help') }}</p>
            </div>

            @if ($tasks->isEmpty())
                <div class="p-5 sm:p-6">
                    @include('admin.hengjia-content._empty-state', ['icon' => 'clipboard-list', 'title' => __('hengjia_content.tasks.empty')])
                </div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($tasks as $task)
                        @php
                            $taskChannels = collect((array) $task->target_channels)
                                ->map(static fn (mixed $target): string => is_array($target) ? (string) ($target['channel_key'] ?? '') : (string) $target)
                                ->filter()
                                ->values();
                        @endphp
                        <article class="px-5 py-5 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $task->id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $task->status])
                                        @include('admin.hengjia-content._status-badge', ['status' => $task->review_status])
                                    </div>
                                    <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $task->title }}</h3>
                                    <p class="mt-1 text-sm text-gray-600">{{ $task->primary_keyword }} · <span class="font-mono text-xs">{{ $task->page_role }}</span></p>

                                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.tasks.fields.audience') }}</dt><dd class="mt-1 text-gray-700">{{ $task->audience }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.tasks.fields.intent') }}</dt><dd class="mt-1 text-gray-700">{{ $task->intent }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.tasks.fields.target_channels') }}</dt><dd class="mt-1 text-gray-700">{{ $taskChannels->isEmpty() ? __('hengjia_content.common.none') : $taskChannels->join(' · ') }}</dd></div>
                                        <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.tasks.fields.due_on') }}</dt><dd class="mt-1 text-gray-700">{{ $task->due_on?->format('Y-m-d') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                    </dl>

                                    @if ((array) $task->blockers !== [])
                                        <div class="mt-4 rounded-md border border-red-100 bg-red-50/60 p-3">
                                            @include('admin.hengjia-content._blockers', ['blockers' => $task->blockers])
                                        </div>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-col items-start gap-3 xl:items-end">
                                    <div class="flex flex-wrap gap-2 text-xs font-medium text-gray-500">
                                        <span>{{ __('hengjia_content.tasks.priority_value', ['priority' => $task->priority]) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('hengjia_content.tasks.master_count', ['count' => $task->masters->count()]) }}</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="{{ route('admin.hengjia-content.evidence') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">{{ __('hengjia_content.tasks.open_evidence') }}</a>
                                        <a href="{{ route('admin.hengjia-content.studio') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">{{ __('hengjia_content.tasks.open_studio') }}</a>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($tasks->hasPages())
                    <div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $tasks->links() }}</div>
                @endif
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-hengjia-task-form]');
            if (!form) return;

            const toggles = Array.from(form.querySelectorAll('[data-channel-toggle]'));
            const error = form.querySelector('[data-channel-error]');
            const syncCard = (toggle) => {
                const card = toggle.closest('[data-channel-card]');
                if (!card) return;
                card.querySelectorAll('[data-channel-field]').forEach((field) => {
                    field.disabled = !toggle.checked;
                });
                card.classList.toggle('border-blue-300', toggle.checked);
                card.classList.toggle('bg-blue-50/40', toggle.checked);
            };

            toggles.forEach((toggle) => {
                syncCard(toggle);
                toggle.addEventListener('change', () => {
                    syncCard(toggle);
                    if (error) error.classList.toggle('hidden', toggles.some((item) => item.checked));
                });
            });

            form.addEventListener('submit', (event) => {
                if (toggles.length > 0 && !toggles.some((toggle) => toggle.checked)) {
                    event.preventDefault();
                    if (error) error.classList.remove('hidden');
                    toggles[0].focus();
                    return;
                }
                form.querySelectorAll('input[name="secondary_keywords[]"]').forEach((input) => {
                    input.disabled = input.value.trim() === '';
                });
            });
        });
    </script>
@endpush
