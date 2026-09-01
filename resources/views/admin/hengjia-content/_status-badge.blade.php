@props([
    'status',
    'kind' => 'status',
])

@php
    $normalizedStatus = trim((string) $status) ?: 'unknown';
    $translationKey = match ($kind) {
        'evidence' => 'hengjia_content.evidence_status.'.$normalizedStatus,
        'permission' => 'hengjia_content.permissions.'.$normalizedStatus,
        'claim' => 'hengjia_content.claim_types.'.$normalizedStatus,
        'connection' => 'hengjia_content.connection_modes.'.$normalizedStatus,
        default => 'hengjia_content.status.'.$normalizedStatus,
    };
    $translated = __($translationKey);
    $label = $translated === $translationKey
        ? \Illuminate\Support\Str::headline($normalizedStatus)
        : $translated;
    $tone = match ($normalizedStatus) {
        'approved', 'completed', 'published', 'connected', 'verified', 'succeeded', 'success',
        'synced', 'public_record_verified', 'internal_confirmed_public', 'publishable' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'blocked', 'failed', 'conflict', 'prohibited', 'outcome_unknown', 'changes_required' => 'border-red-200 bg-red-50 text-red-800',
        'candidate', 'in_review', 'pending', 'awaiting_manual_confirmation', 'attention_required',
        'not_verified', 'restricted', 'third_party_claim', 'competitor_self_claim', 'ai_observation' => 'border-amber-200 bg-amber-50 text-amber-800',
        'ready', 'generating', 'queued', 'running', 'processing', 'draft_ready', 'active', 'promoted' => 'border-blue-200 bg-blue-50 text-blue-800',
        default => 'border-gray-200 bg-gray-50 text-gray-700',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex min-h-6 items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold '.$tone]) }}>
    {{ $label }}
</span>
