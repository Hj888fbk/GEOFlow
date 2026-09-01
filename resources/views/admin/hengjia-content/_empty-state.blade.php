@props([
    'icon' => 'inbox',
    'title',
    'body' => null,
])

<div {{ $attributes->merge(['class' => 'flex min-h-44 flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50/60 px-6 py-10 text-center']) }}>
    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm" aria-hidden="true">
        <i data-lucide="{{ $icon }}" class="h-5 w-5"></i>
    </span>
    <p class="mt-3 text-sm font-semibold text-gray-800">{{ $title }}</p>
    @if ($body)
        <p class="mt-1 max-w-xl text-sm leading-6 text-gray-500">{{ $body }}</p>
    @endif
</div>
