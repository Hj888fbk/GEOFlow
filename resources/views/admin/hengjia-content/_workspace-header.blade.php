@props([
    'workspace',
    'title',
    'subtitle',
])

@php
    $contentNavigation = app(\App\Support\AdminUiRegistry::class)
        ->hengjiaContentNavigation(
            request()->route()?->getName(),
            auth('admin')->user()?->isSuperAdmin() === true,
        );
@endphp

<header class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-5 shadow-sm sm:px-6" data-hengjia-workspace="{{ $workspace }}">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-blue-600">{{ __('hengjia_content.module') }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-950">{{ $title }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ $subtitle }}</p>
        </div>
        <div class="flex max-w-xl items-start gap-3 rounded-md border border-amber-200 bg-amber-50/70 px-4 py-3 text-amber-950" role="note">
            <i data-lucide="shield-alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true"></i>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide">{{ __('hengjia_content.status_guard.title') }}</p>
                <p class="mt-1 text-xs leading-5 text-amber-800">{{ __('hengjia_content.status_guard.body') }}</p>
            </div>
        </div>
    </div>

    <div class="mt-5 border-t border-gray-100 pt-3">
        <x-admin.v3.section-subnav
            :items="$contentNavigation"
            :label="__('hengjia_content.navigation.label')"
            name="hengjia-content"
            embedded
        />
    </div>
</header>

@if (session('error'))
    <div class="mb-6 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <i data-lucide="circle-alert" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true"></i>
        <span>{{ session('error') }}</span>
    </div>
@endif
