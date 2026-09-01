@extends('admin.layouts.app')

@php
    $currentAdmin = auth('admin')->user();
    $canManageAccounts = $currentAdmin instanceof \App\Models\Admin && $currentAdmin->isSuperAdmin();
    $selectedAdapterKey = old('adapter_key', (string) ($adapters->first()?->key ?? ''));
    $selectedAdapter = $adapters->firstWhere('key', $selectedAdapterKey) ?? $adapters->first();
    $oldContentTypes = collect((array) old('content_types', (array) ($selectedAdapter?->supported_content_types ?? [])));
    $allContentTypes = $adapters->flatMap(static fn ($adapter) => (array) $adapter->supported_content_types)->filter()->unique()->values();
    $wordpressChannels = $distributionChannels->filter(static fn ($channel): bool => $channel->isWordPressRest())->values();
    $adapterCatalog = $adapters->mapWithKeys(static fn ($adapter): array => [
        $adapter->key => [
            'key' => $adapter->key,
            'execution_mode' => $adapter->execution_mode,
            'connection_modes' => array_values((array) $adapter->connection_modes),
            'content_types' => array_values((array) $adapter->supported_content_types),
            'is_custom' => $adapter->key === \App\Models\PlatformAdapter::CUSTOM_MANUAL,
            'is_wordpress' => $adapter->key === \App\Models\PlatformAdapter::WORDPRESS,
        ],
    ]);
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'accounts',
            'title' => __('hengjia_content.pages.accounts.title'),
            'subtitle' => __('hengjia_content.pages.accounts.subtitle'),
        ])

        <section class="mb-6 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50/60 px-5 py-4" aria-labelledby="credential-boundary-title">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-blue-700 shadow-sm" aria-hidden="true"><i data-lucide="key-round" class="h-5 w-5"></i></span>
            <div>
                <h2 id="credential-boundary-title" class="text-base font-semibold text-blue-950">{{ __('hengjia_content.accounts.security_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('hengjia_content.accounts.security_body') }}</p>
            </div>
        </section>

        @if ($canManageAccounts)
            <details class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" @if($accounts->isEmpty() || old()) open @endif>
                <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 rounded-sm px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:px-6 [&::-webkit-details-marker]:hidden">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.accounts.add_title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.accounts.add_help') }}</p>
                    </div>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-700" aria-hidden="true"><i data-lucide="user-round-plus" class="h-5 w-5"></i></span>
                </summary>

                @if ($adapters->isEmpty() || $personas->isEmpty())
                    <div class="border-t border-gray-100 p-5 sm:p-6">
                        @include('admin.hengjia-content._empty-state', [
                            'icon' => 'badge-alert',
                            'title' => $adapters->isEmpty() ? __('hengjia_content.accounts.no_adapters') : __('hengjia_content.accounts.no_personas'),
                        ])
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.hengjia-content.accounts.store') }}" class="border-t border-gray-100 px-5 py-5 sm:px-6" data-account-form>
                        @csrf
                        <input type="hidden" name="is_active" value="1">
                        <div class="grid gap-5 lg:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.adapter') }}</span>
                                <select name="adapter_key" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white" data-adapter-select>
                                    @foreach ($adapters as $adapter)
                                        <option value="{{ $adapter->key }}" @selected($selectedAdapterKey === $adapter->key)>{{ $adapter->name }} · v{{ $adapter->version }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.persona') }}</span>
                                <select name="persona_id" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                                    @foreach ($personas as $persona)
                                        <option value="{{ $persona->id }}" @selected((string) old('persona_id') === (string) $persona->id)>{{ $persona->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block" data-wordpress-channel-field>
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.distribution_channel') }}</span>
                                <select name="distribution_channel_id" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white" data-wordpress-channel>
                                    <option value="">{{ __('hengjia_content.common.none') }}</option>
                                    @foreach ($wordpressChannels as $channel)
                                        <option value="{{ $channel->id }}" @selected((string) old('distribution_channel_id') === (string) $channel->id)>{{ $channel->name }} · {{ $channel->domain }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs leading-5 text-gray-500">{{ __('hengjia_content.accounts.wordpress_channel_help') }}</span>
                            </label>

                            <label class="block" data-custom-platform-field>
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.custom_platform') }}</span>
                                <input type="text" name="custom_platform" value="{{ old('custom_platform') }}" maxlength="120" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white" data-custom-platform-input>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.account_name') }}</span>
                                <input type="text" name="account_name" value="{{ old('account_name') }}" maxlength="160" required autocomplete="off" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.subject_name') }}</span>
                                <input type="text" name="subject_name" value="{{ old('subject_name') }}" maxlength="160" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.profile_url') }}</span>
                                <input type="url" name="profile_url" value="{{ old('profile_url') }}" maxlength="1000" inputmode="url" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.connection_mode') }}</span>
                                <select name="connection_mode" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white" data-connection-mode data-old-value="{{ old('connection_mode') }}"></select>
                            </label>

                            <label class="block lg:col-span-2">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.brand_voice') }}</span>
                                <textarea name="brand_voice" rows="3" maxlength="5000" class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('brand_voice') }}</textarea>
                            </label>

                            <label class="block lg:col-span-2">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.person_voice') }}</span>
                                <textarea name="person_voice" rows="3" maxlength="5000" class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('person_voice') }}</textarea>
                            </label>

                            <fieldset class="lg:col-span-2">
                                <legend class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.content_types') }}</legend>
                                <div class="mt-2 flex flex-wrap gap-2" data-content-types>
                                    @foreach ($allContentTypes as $contentType)
                                        <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border border-gray-300 bg-gray-50 px-3 text-sm font-medium text-gray-700" data-content-type-option="{{ $contentType }}">
                                            <input type="checkbox" name="content_types[]" value="{{ $contentType }}" class="h-4 w-4 rounded border-gray-300 text-blue-600" @checked($oldContentTypes->contains($contentType))>
                                            {{ $contentType }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-2 hidden text-sm font-medium text-red-700" role="alert" data-content-type-error>{{ __('hengjia_content.accounts.select_content_type') }}</p>
                            </fieldset>

                            <label class="block lg:col-span-2">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.accounts.fields.notes') }}</span>
                                <textarea name="notes" rows="2" maxlength="5000" class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('notes') }}</textarea>
                            </label>
                        </div>

                        <div class="mt-6 flex justify-end border-t border-gray-100 pt-5">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-600 px-5 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                <i data-lucide="user-round-plus" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ __('hengjia_content.accounts.create') }}
                            </button>
                        </div>
                    </form>
                @endif
            </details>
        @else
            <div class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm text-gray-600 shadow-sm">{{ __('hengjia_content.common.requires_super_admin') }}</div>
        @endif

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="configured-accounts-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="configured-accounts-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.accounts.list_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.accounts.list_help') }}</p>
            </div>

            @if ($accounts->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'badge-plus', 'title' => __('hengjia_content.accounts.empty')])</div>
            @else
                <div class="grid gap-4 p-5 lg:grid-cols-2 sm:p-6">
                    @foreach ($accounts as $account)
                        @php
                            $isManualExport = $account->connection_mode === \App\Models\PlatformAdapter::EXECUTION_MANUAL_EXPORT;
                            $needsBrowserIdentity = ! $isManualExport && $account->adapter?->key !== \App\Models\PlatformAdapter::WORDPRESS;
                        @endphp
                        <article class="flex flex-col rounded-lg border {{ $account->isEnabled() ? 'border-gray-200' : 'border-gray-200 bg-gray-50 opacity-80' }}">
                            <div class="border-b border-gray-100 px-4 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-xs text-gray-400">#{{ $account->id }}</span>
                                            @include('admin.hengjia-content._status-badge', ['status' => $account->connection_mode, 'kind' => 'connection'])
                                        </div>
                                        <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $account->account_name }}</h3>
                                        <p class="mt-1 text-sm text-gray-500">{{ $account->adapter?->name ?? $account->platform }} · {{ $account->persona?->name ?? __('hengjia_content.common.not_recorded') }}</p>
                                    </div>
                                    <span class="rounded-md bg-gray-100 px-2 py-1 font-mono text-[11px] font-semibold text-gray-600">v{{ $account->adapter_version ?: $account->adapter?->version }}</span>
                                </div>
                            </div>

                            <div class="flex-1 space-y-4 px-4 py-4">
                                <div class="flex flex-wrap gap-2">
                                    @include('admin.hengjia-content._status-badge', ['status' => $account->authorization_status])
                                    @include('admin.hengjia-content._status-badge', ['status' => $account->login_status])
                                    @if (! $account->isEnabled()) @include('admin.hengjia-content._status-badge', ['status' => 'disabled']) @endif
                                </div>

                                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.accounts.fields.subject_name') }}</dt><dd class="mt-1 text-gray-700">{{ $account->subject_name ?: __('hengjia_content.common.not_recorded') }}</dd></div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.last_verified') }}</dt><dd class="mt-1 text-gray-700">{{ $account->last_verified_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</dd></div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.accounts.fields.distribution_channel') }}</dt><dd class="mt-1 text-gray-700">{{ $account->distributionChannel?->name ?? __('hengjia_content.common.none') }}</dd></div>
                                    <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.actions') }}</dt><dd class="mt-1 text-gray-700">{{ __('hengjia_content.accounts.publication_count', ['count' => $account->publications_count]) }} · {{ __('hengjia_content.accounts.variant_count', ['count' => $account->channel_variants_count]) }}</dd></div>
                                </dl>

                                <div class="flex flex-wrap gap-2">
                                    @foreach ((array) $account->content_types as $contentType)
                                        <span class="rounded-md border border-gray-200 bg-gray-50 px-2 py-1 font-mono text-[11px] font-semibold text-gray-600">{{ $contentType }}</span>
                                    @endforeach
                                </div>

                                @if ($account->profile_url)
                                    <a href="{{ $account->profile_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-9 items-center break-all text-sm font-semibold text-blue-700 hover:text-blue-800">{{ $account->profile_url }}<i data-lucide="external-link" class="ml-1.5 h-4 w-4 shrink-0" aria-hidden="true"></i></a>
                                @endif

                                @if ($account->last_error_message)
                                    <div class="rounded-md border border-red-100 bg-red-50/60 p-3 text-sm leading-6 text-red-800">
                                        <p class="text-xs font-semibold uppercase tracking-wide">{{ __('hengjia_content.accounts.last_error') }} · {{ $account->last_error_code }}</p>
                                        <p class="mt-1">{{ $account->last_error_message }}</p>
                                    </div>
                                @endif
                            </div>

                            @if ($canManageAccounts && $account->isEnabled())
                                <div class="space-y-3 border-t border-gray-100 px-4 py-4">
                                    <form method="POST" action="{{ route('admin.hengjia-content.accounts.verify', $account) }}" class="space-y-3">
                                        @csrf
                                        @if ($needsBrowserIdentity)
                                            <label class="block">
                                                <span class="text-xs font-semibold text-gray-600">{{ __('hengjia_content.accounts.observed_account') }}</span>
                                                <input type="text" name="observed_account_name" maxlength="160" required autocomplete="off" class="mt-1 min-h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-900">
                                            </label>
                                            <label class="flex min-h-10 cursor-pointer items-start gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-700">
                                                <input type="checkbox" name="browser_session_local" value="1" required class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600">
                                                <span>{{ __('hengjia_content.accounts.local_session_confirm') }}</span>
                                            </label>
                                            <p class="text-xs leading-5 text-gray-500">{{ __('hengjia_content.accounts.browser_help') }}</p>
                                        @elseif ($isManualExport)
                                            <p class="text-xs leading-5 text-gray-500">{{ __('hengjia_content.accounts.manual_help') }}</p>
                                        @endif
                                        <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                                            <i data-lucide="badge-check" class="mr-2 h-4 w-4" aria-hidden="true"></i>{{ $isManualExport ? __('hengjia_content.accounts.verify_manual') : __('hengjia_content.accounts.verify_identity') }}
                                        </button>
                                    </form>

                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <form method="POST" action="{{ route('admin.hengjia-content.accounts.capabilities.refresh', $account) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">{{ __('hengjia_content.accounts.refresh_capabilities') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.hengjia-content.accounts.disable', $account) }}" onsubmit="return confirm(@js(__('hengjia_content.accounts.disable_confirm')))">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-10 w-full items-center justify-center rounded-md border border-red-200 bg-white px-3 text-sm font-semibold text-red-700 transition duration-[120ms] hover:bg-red-50 active:scale-[.98]">{{ __('hengjia_content.accounts.disable_account') }}</button>
                                        </form>
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($accounts->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $accounts->links() }}</div>@endif
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="adapter-inventory-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6"><h2 id="adapter-inventory-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.accounts.adapter_inventory') }}</h2></div>
            @if ($adapters->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'unplug', 'title' => __('hengjia_content.accounts.no_adapters')])</div>
            @else
                <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3 sm:p-6">
                    @foreach ($adapters as $adapter)
                        <article class="rounded-md border border-gray-200 bg-gray-50/60 p-4">
                            <div class="flex items-start justify-between gap-3"><h3 class="text-sm font-semibold text-gray-900">{{ $adapter->name }}</h3><span class="font-mono text-xs text-gray-500">v{{ $adapter->version }}</span></div>
                            <p class="mt-1 font-mono text-xs text-gray-500">{{ $adapter->key }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @include('admin.hengjia-content._status-badge', ['status' => $adapter->execution_mode, 'kind' => 'connection'])
                                @foreach ((array) $adapter->supported_content_types as $contentType)<span class="rounded-md bg-white px-2 py-1 font-mono text-[11px] text-gray-600">{{ $contentType }}</span>@endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-account-form]');
            if (!form) return;

            const catalog = @json($adapterCatalog);
            const labels = @json(collect(['authenticated_api', 'browser_assisted', 'manual_export'])->mapWithKeys(fn ($mode) => [$mode => __('hengjia_content.connection_modes.'.$mode)]));
            const adapterSelect = form.querySelector('[data-adapter-select]');
            const modeSelect = form.querySelector('[data-connection-mode]');
            const wordpressField = form.querySelector('[data-wordpress-channel-field]');
            const wordpressInput = form.querySelector('[data-wordpress-channel]');
            const customField = form.querySelector('[data-custom-platform-field]');
            const customInput = form.querySelector('[data-custom-platform-input]');
            const typeOptions = Array.from(form.querySelectorAll('[data-content-type-option]'));
            const typeError = form.querySelector('[data-content-type-error]');

            const sync = () => {
                const adapter = catalog[adapterSelect.value] || null;
                if (!adapter) return;

                const oldMode = modeSelect.dataset.oldValue;
                modeSelect.replaceChildren(...adapter.connection_modes.map((mode) => {
                    const option = document.createElement('option');
                    option.value = mode;
                    option.textContent = labels[mode] || mode;
                    option.selected = oldMode ? oldMode === mode : adapter.execution_mode === mode;
                    return option;
                }));
                modeSelect.dataset.oldValue = '';

                wordpressField.hidden = !adapter.is_wordpress;
                wordpressInput.disabled = !adapter.is_wordpress;
                wordpressInput.required = adapter.is_wordpress;
                customField.hidden = !adapter.is_custom;
                customInput.disabled = !adapter.is_custom;

                typeOptions.forEach((wrapper) => {
                    const input = wrapper.querySelector('input');
                    const supported = adapter.content_types.includes(wrapper.dataset.contentTypeOption);
                    wrapper.hidden = !supported;
                    input.disabled = !supported;
                    if (!supported) input.checked = false;
                });

                const enabled = typeOptions.filter((wrapper) => !wrapper.hidden).map((wrapper) => wrapper.querySelector('input'));
                if (enabled.length > 0 && !enabled.some((input) => input.checked)) enabled.forEach((input) => { input.checked = true; });
            };

            adapterSelect.addEventListener('change', sync);
            typeOptions.forEach((wrapper) => wrapper.querySelector('input').addEventListener('change', () => typeError?.classList.add('hidden')));
            form.addEventListener('submit', (event) => {
                const checked = typeOptions.some((wrapper) => !wrapper.hidden && wrapper.querySelector('input').checked);
                if (!checked) {
                    event.preventDefault();
                    typeError?.classList.remove('hidden');
                    typeOptions.find((wrapper) => !wrapper.hidden)?.querySelector('input')?.focus();
                }
            });
            sync();
        });
    </script>
@endpush
