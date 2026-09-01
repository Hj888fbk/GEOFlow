@props([
    'blockers' => [],
])

@php
    $blockerItems = collect((array) $blockers)
        ->map(static function (mixed $blocker): array {
            if (is_array($blocker)) {
                return [
                    'code' => trim((string) ($blocker['code'] ?? '')),
                    'message' => trim((string) ($blocker['message'] ?? json_encode($blocker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
                ];
            }

            return ['code' => '', 'message' => trim((string) $blocker)];
        })
        ->filter(static fn (array $item): bool => $item['message'] !== '')
        ->values();
@endphp

@if ($blockerItems->isNotEmpty())
    <ul {{ $attributes->merge(['class' => 'space-y-2']) }}>
        @foreach ($blockerItems as $blocker)
            <li class="flex items-start gap-2 text-sm leading-5 text-red-800">
                <i data-lucide="octagon-alert" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                <span>
                    {{ $blocker['message'] }}
                    @if ($blocker['code'] !== '')
                        <code class="ml-1 rounded bg-red-100 px-1 py-0.5 text-[11px] text-red-700">{{ $blocker['code'] }}</code>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
