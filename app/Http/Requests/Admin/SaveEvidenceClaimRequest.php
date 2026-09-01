<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentSourceFile;
use App\Models\EvidenceClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEvidenceClaimRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        if (! $admin instanceof Admin) {
            return false;
        }

        return $this->input('public_permission') !== ContentSourceFile::PERMISSION_PUBLISHABLE
            || $admin->canManageProtectedWorkflows();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_task_id' => ['nullable', 'integer', Rule::exists('content_tasks', 'id')],
            'content_source_file_id' => ['nullable', 'integer', Rule::exists('content_source_files', 'id')],
            'source_id' => ['required', 'string', 'max:160'],
            'claim_type' => ['required', Rule::in(EvidenceClaim::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'predicate' => ['required', 'string', 'max:160'],
            'claim_value' => ['required', 'string', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'scope' => ['required', 'string', 'max:5000'],
            'evidence_status' => ['required', Rule::in(ContentSourceFile::EVIDENCE_STATUSES)],
            'public_permission' => ['required', Rule::in(ContentSourceFile::PUBLIC_PERMISSIONS)],
            'official_lookup_url' => ['nullable', 'url:https', 'max:1000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'conflict_notes' => ['nullable', 'string', 'max:5000'],
            'qualification' => [Rule::requiredIf(fn (): bool => $this->input('claim_type') === EvidenceClaim::TYPE_QUALIFICATION), 'nullable', 'array'],
            'qualification.qualification_name' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'string', 'max:255'],
            'qualification.certificate_number' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'string', 'max:255'],
            'qualification.issuer' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'string', 'max:255'],
            'qualification.certification_scope' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'string', 'max:2000'],
            'qualification.applicable_products' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'string', 'max:2000'],
            'qualification.issued_at' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'date'],
            'qualification.valid_until' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'date', 'after_or_equal:qualification.issued_at'],
            'qualification.official_lookup_url' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'url:https', 'max:1000'],
            'qualification.file_sha256' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, 'regex:/^[a-f0-9]{64}$/'],
            'qualification.public_permission' => ['nullable', 'required_if:claim_type,'.EvidenceClaim::TYPE_QUALIFICATION, Rule::in(ContentSourceFile::PUBLIC_PERMISSIONS)],
        ];
    }
}
