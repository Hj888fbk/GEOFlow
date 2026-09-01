@extends('admin.layouts.app')

@php
    $currentAdmin = auth('admin')->user();
    $canApproveSources = $currentAdmin instanceof \App\Models\Admin && $currentAdmin->isSuperAdmin();
    $evidenceStatuses = \App\Models\ContentSourceFile::EVIDENCE_STATUSES;
    $publicPermissions = collect(\App\Models\ContentSourceFile::PUBLIC_PERMISSIONS)
        ->reject(static fn (string $permission): bool => ! $canApproveSources && $permission === \App\Models\ContentSourceFile::PERMISSION_PUBLISHABLE)
        ->values()
        ->all();
    $claimTypes = \App\Models\EvidenceClaim::TYPES;
    $selectedClaimType = old('claim_type', \App\Models\EvidenceClaim::TYPE_COMPANY);
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'evidence',
            'title' => __('hengjia_content.pages.evidence.title'),
            'subtitle' => __('hengjia_content.pages.evidence.subtitle'),
        ])

        <section class="mb-6 flex flex-col gap-4 rounded-lg border border-blue-200 bg-blue-50/60 px-5 py-4 sm:flex-row sm:items-start sm:justify-between" aria-labelledby="evidence-policy-title">
            <div class="flex max-w-4xl items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-blue-700 shadow-sm" aria-hidden="true">
                    <i data-lucide="shield-check" class="h-5 w-5"></i>
                </span>
                <div>
                    <h2 id="evidence-policy-title" class="text-base font-semibold text-blue-950">{{ __('hengjia_content.evidence.policy_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('hengjia_content.evidence.policy_body') }}</p>
                </div>
            </div>
            @if ($canApproveSources)
                <form method="POST" action="{{ route('admin.hengjia-content.sources.sync') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md border border-blue-300 bg-white px-4 text-sm font-semibold text-blue-800 transition duration-[120ms] hover:bg-blue-100 active:scale-[.98]">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                        {{ __('hengjia_content.evidence.sync_sources') }}
                    </button>
                </form>
            @endif
        </section>

        <p class="mb-6 text-xs leading-5 text-gray-500">{{ __('hengjia_content.evidence.sync_help') }}</p>

        <details class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" @if(old()) open @endif>
            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 rounded-sm px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:px-6 [&::-webkit-details-marker]:hidden">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.evidence.new_claim') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.evidence.new_claim_help') }}</p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-blue-700" aria-hidden="true"><i data-lucide="plus" class="h-5 w-5"></i></span>
            </summary>

            <form method="POST" action="{{ route('admin.hengjia-content.evidence.store') }}" class="border-t border-gray-100 px-5 py-5 sm:px-6" data-evidence-form>
                @csrf
                <div class="grid gap-5 lg:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.task') }}</span>
                        <select name="content_task_id" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            <option value="">{{ __('hengjia_content.common.none') }}</option>
                            @foreach ($tasks as $task)
                                <option value="{{ $task->id }}" @selected((string) old('content_task_id') === (string) $task->id)>#{{ $task->id }} · {{ $task->title }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.source_file') }}</span>
                        <select name="content_source_file_id" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            <option value="">{{ __('hengjia_content.evidence.unlinked') }}</option>
                            @foreach ($sourceFiles as $sourceFile)
                                <option value="{{ $sourceFile->id }}" @selected((string) old('content_source_file_id') === (string) $sourceFile->id)>
                                    #{{ $sourceFile->id }} · {{ \Illuminate\Support\Str::limit($sourceFile->relative_path, 90) }} · {{ $sourceFile->is_approved ? __('hengjia_content.evidence.approved_file') : __('hengjia_content.evidence.pending_file') }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.source_id') }}</span>
                        <input type="text" name="source_id" value="{{ old('source_id') }}" maxlength="160" required autocomplete="off" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 font-mono text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.claim_type') }}</span>
                        <select name="claim_type" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white" data-claim-type>
                            @foreach ($claimTypes as $claimType)
                                <option value="{{ $claimType }}" @selected($selectedClaimType === $claimType)>{{ __('hengjia_content.claim_types.'.$claimType) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.subject') }}</span>
                        <input type="text" name="subject" value="{{ old('subject') }}" maxlength="255" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.predicate') }}</span>
                        <input type="text" name="predicate" value="{{ old('predicate') }}" maxlength="160" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.claim_value') }}</span>
                        <textarea name="claim_value" rows="3" maxlength="10000" required class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('claim_value') }}</textarea>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.unit') }}</span>
                        <input type="text" name="unit" value="{{ old('unit') }}" maxlength="40" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.official_lookup_url') }}</span>
                        <input type="url" name="official_lookup_url" value="{{ old('official_lookup_url') }}" maxlength="1000" inputmode="url" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.scope') }}</span>
                        <textarea name="scope" rows="3" maxlength="5000" required class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('scope') }}</textarea>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.evidence_status') }}</span>
                        <select name="evidence_status" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            <option value="" disabled @selected(!old('evidence_status'))>{{ __('hengjia_content.evidence.fields.evidence_status') }}</option>
                            @foreach ($evidenceStatuses as $evidenceStatus)
                                <option value="{{ $evidenceStatus }}" @selected(old('evidence_status') === $evidenceStatus)>{{ __('hengjia_content.evidence_status.'.$evidenceStatus) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.public_permission') }}</span>
                        <select name="public_permission" required class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                            @foreach ($publicPermissions as $permission)
                                <option value="{{ $permission }}" @selected(old('public_permission', \App\Models\ContentSourceFile::PERMISSION_INTERNAL) === $permission)>{{ __('hengjia_content.permissions.'.$permission) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.valid_from') }}</span>
                        <input type="date" name="valid_from" value="{{ old('valid_from') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.valid_until') }}</span>
                        <input type="date" name="valid_until" value="{{ old('valid_until') }}" class="mt-2 min-h-11 w-full rounded-md border border-gray-300 bg-gray-50 px-3 text-sm text-gray-950 focus:border-blue-500 focus:bg-white">
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.fields.conflict_notes') }}</span>
                        <textarea name="conflict_notes" rows="2" maxlength="5000" class="mt-2 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm leading-6 text-gray-950 focus:border-blue-500 focus:bg-white">{{ old('conflict_notes') }}</textarea>
                    </label>
                </div>

                <fieldset class="mt-6 rounded-lg border border-amber-200 bg-amber-50/50 p-4 sm:p-5" data-qualification-fields @if($selectedClaimType !== \App\Models\EvidenceClaim::TYPE_QUALIFICATION) hidden @endif>
                    <legend class="px-2 text-sm font-semibold text-amber-950">{{ __('hengjia_content.evidence.qualification_title') }}</legend>
                    <p class="mb-4 text-sm leading-6 text-amber-800">{{ __('hengjia_content.evidence.qualification_help') }}</p>
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach (['qualification_name', 'certificate_number', 'issuer', 'certification_scope', 'applicable_products', 'issued_at', 'valid_until', 'official_lookup_url', 'file_sha256'] as $field)
                            @php
                                $isTextArea = in_array($field, ['certification_scope', 'applicable_products'], true);
                                $inputType = in_array($field, ['issued_at', 'valid_until'], true) ? 'date' : ($field === 'official_lookup_url' ? 'url' : 'text');
                            @endphp
                            <label class="block {{ $isTextArea ? 'lg:col-span-2' : '' }}">
                                <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.qualification.'.$field) }}</span>
                                @if ($isTextArea)
                                    <textarea name="qualification[{{ $field }}]" rows="2" maxlength="2000" class="mt-2 w-full rounded-md border border-amber-200 bg-white px-3 py-2.5 text-sm text-gray-950 focus:border-blue-500" data-qualification-input>{{ old('qualification.'.$field) }}</textarea>
                                @else
                                    <input type="{{ $inputType }}" name="qualification[{{ $field }}]" value="{{ old('qualification.'.$field) }}" @if($field === 'file_sha256') pattern="[a-f0-9]{64}" maxlength="64" @else maxlength="{{ $field === 'official_lookup_url' ? 1000 : 255 }}" @endif class="mt-2 min-h-11 w-full rounded-md border border-amber-200 bg-white px-3 {{ $field === 'file_sha256' ? 'font-mono text-xs' : 'text-sm' }} text-gray-950 focus:border-blue-500" data-qualification-input>
                                @endif
                            </label>
                        @endforeach

                        <label class="block">
                            <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.qualification.public_permission') }}</span>
                            <select name="qualification[public_permission]" class="mt-2 min-h-11 w-full rounded-md border border-amber-200 bg-white px-3 text-sm text-gray-950 focus:border-blue-500" data-qualification-input>
                                @foreach ($publicPermissions as $permission)
                                    <option value="{{ $permission }}" @selected(old('qualification.public_permission', \App\Models\ContentSourceFile::PERMISSION_INTERNAL) === $permission)>{{ __('hengjia_content.permissions.'.$permission) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="block">
                            <span class="text-sm font-semibold text-gray-800">{{ __('hengjia_content.evidence.qualification.reviewer') }}</span>
                            <p class="mt-2 flex min-h-11 items-center rounded-md border border-amber-200 bg-amber-100/50 px-3 text-sm text-gray-800">
                                {{ $currentAdmin?->name ?? __('hengjia_content.common.not_recorded') }}
                            </p>
                        </div>
                    </div>
                </fieldset>

                <div class="mt-6 flex justify-end border-t border-gray-100 pt-5">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-blue-600 px-5 text-sm font-semibold text-white transition duration-[120ms] hover:bg-blue-700 active:scale-[.98]">
                        <i data-lucide="shield-plus" class="mr-2 h-4 w-4" aria-hidden="true"></i>
                        {{ __('hengjia_content.evidence.save_claim') }}
                    </button>
                </div>
            </form>
        </details>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="claims-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="claims-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.evidence.claims_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.evidence.claims_help') }}</p>
            </div>
            @if ($claims->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'shield-question', 'title' => __('hengjia_content.evidence.no_claims')])</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($claims as $claim)
                        <article class="px-5 py-5 sm:px-6">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">{{ $claim->claim_id }}</span>
                                        @include('admin.hengjia-content._status-badge', ['status' => $claim->claim_type, 'kind' => 'claim'])
                                        @include('admin.hengjia-content._status-badge', ['status' => $claim->evidence_status, 'kind' => 'evidence'])
                                        @include('admin.hengjia-content._status-badge', ['status' => $claim->public_permission, 'kind' => 'permission'])
                                    </div>
                                    <h3 class="mt-2 text-base font-semibold leading-6 text-gray-950">{{ $claim->subject }} · {{ $claim->predicate }}</h3>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">{{ $claim->claim_value }}@if($claim->unit) {{ $claim->unit }}@endif</p>
                                </div>
                                <div class="shrink-0 text-left text-xs text-gray-500 lg:text-right">
                                    <p>{{ $claim->reviewed_at?->format('Y-m-d H:i') ?? __('hengjia_content.common.not_recorded') }}</p>
                                    @if ($claim->valid_until)<p class="mt-1">{{ __('hengjia_content.evidence.fields.valid_until') }} {{ $claim->valid_until->format('Y-m-d') }}</p>@endif
                                </div>
                            </div>

                            <dl class="mt-4 grid gap-3 rounded-md bg-gray-50 p-4 text-sm lg:grid-cols-3">
                                <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.scope') }}</dt><dd class="mt-1 whitespace-pre-line leading-6 text-gray-700">{{ $claim->scope }}</dd></div>
                                <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.common.source') }}</dt><dd class="mt-1 break-all font-mono text-xs leading-5 text-gray-700">{{ $claim->source_id }}<br>{{ $claim->sourceFile?->relative_path ?? __('hengjia_content.evidence.unlinked') }}</dd></div>
                                <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.evidence.fields.task') }}</dt><dd class="mt-1 text-gray-700">{{ $claim->task?->title ?? __('hengjia_content.common.none') }}</dd></div>
                            </dl>

                            @if ($claim->claim_type === \App\Models\EvidenceClaim::TYPE_QUALIFICATION && (array) $claim->structured_payload !== [])
                                <details class="mt-3 rounded-md border border-gray-200 bg-white px-4 py-3">
                                    <summary class="cursor-pointer rounded-sm text-sm font-semibold text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">{{ __('hengjia_content.evidence.qualification_title') }}</summary>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach ((array) $claim->structured_payload as $field => $value)
                                            <div><dt class="text-xs font-medium text-gray-400">{{ __('hengjia_content.evidence.qualification.'.$field) }}</dt><dd class="mt-1 break-words text-gray-700">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd></div>
                                        @endforeach
                                    </dl>
                                </details>
                            @endif
                        </article>
                    @endforeach
                </div>
                @if ($claims->hasPages())<div class="border-t border-gray-100 px-5 py-4 sm:px-6">{{ $claims->links() }}</div>@endif
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="source-approvals-title">
            <div class="border-b border-gray-100 px-5 py-5 sm:px-6">
                <h2 id="source-approvals-title" class="text-lg font-semibold text-gray-950">{{ __('hengjia_content.evidence.sources_title') }}</h2>
                <p class="mt-1 text-sm leading-6 text-gray-500">{{ __('hengjia_content.evidence.sources_help') }}</p>
            </div>
            @if ($sourceFiles->isEmpty())
                <div class="p-5 sm:p-6">@include('admin.hengjia-content._empty-state', ['icon' => 'file-question', 'title' => __('hengjia_content.evidence.no_sources')])</div>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($sourceFiles as $sourceFile)
                        <article class="px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs text-gray-400">#{{ $sourceFile->id }}</span>
                                        @if ($sourceFile->is_approved)
                                            <span class="inline-flex min-h-6 items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-800">{{ __('hengjia_content.evidence.approved_file') }}</span>
                                        @else
                                            <span class="inline-flex min-h-6 items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 text-xs font-semibold text-amber-800">{{ __('hengjia_content.evidence.pending_file') }}</span>
                                        @endif
                                        @if ($sourceFile->evidence_status) @include('admin.hengjia-content._status-badge', ['status' => $sourceFile->evidence_status, 'kind' => 'evidence']) @endif
                                        @if ($sourceFile->public_permission) @include('admin.hengjia-content._status-badge', ['status' => $sourceFile->public_permission, 'kind' => 'permission']) @endif
                                    </div>
                                    <p class="mt-2 break-all text-sm font-medium leading-6 text-gray-900">{{ $sourceFile->relative_path }}</p>
                                    <p class="mt-1 break-all font-mono text-[11px] text-gray-400">{{ $sourceFile->sha256 }}</p>
                                </div>

                                @if ($canApproveSources)
                                    <form method="POST" action="{{ route('admin.hengjia-content.sources.approve', $sourceFile) }}" class="grid shrink-0 gap-2 sm:grid-cols-[minmax(12rem,1fr)_minmax(10rem,1fr)_auto]">
                                        @csrf
                                        <select name="evidence_status" required aria-label="{{ __('hengjia_content.evidence.fields.evidence_status') }}" class="min-h-10 rounded-md border border-gray-300 bg-gray-50 px-2.5 text-sm text-gray-900">
                                            <option value="" disabled @selected(!$sourceFile->evidence_status)>{{ __('hengjia_content.evidence.fields.evidence_status') }}</option>
                                            @foreach ($evidenceStatuses as $evidenceStatus)
                                                <option value="{{ $evidenceStatus }}" @selected($sourceFile->evidence_status === $evidenceStatus)>{{ __('hengjia_content.evidence_status.'.$evidenceStatus) }}</option>
                                            @endforeach
                                        </select>
                                        <select name="public_permission" required aria-label="{{ __('hengjia_content.evidence.fields.public_permission') }}" class="min-h-10 rounded-md border border-gray-300 bg-gray-50 px-2.5 text-sm text-gray-900">
                                            @foreach ($publicPermissions as $permission)
                                                <option value="{{ $permission }}" @selected(($sourceFile->public_permission ?: \App\Models\ContentSourceFile::PERMISSION_INTERNAL) === $permission)>{{ __('hengjia_content.permissions.'.$permission) }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 transition duration-[120ms] hover:border-blue-300 hover:text-blue-700 active:scale-[.98]">{{ __('hengjia_content.evidence.approve_source') }}</button>
                                    </form>
                                @endif
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
            const form = document.querySelector('[data-evidence-form]');
            if (!form) return;
            const type = form.querySelector('[data-claim-type]');
            const fields = form.querySelector('[data-qualification-fields]');
            if (!type || !fields) return;

            const syncQualification = () => {
                const enabled = type.value === 'qualification';
                fields.hidden = !enabled;
                fields.querySelectorAll('[data-qualification-input]').forEach((input) => {
                    input.disabled = !enabled;
                    input.required = enabled;
                });
            };
            type.addEventListener('change', syncQualification);
            syncQualification();
        });
    </script>
@endpush
