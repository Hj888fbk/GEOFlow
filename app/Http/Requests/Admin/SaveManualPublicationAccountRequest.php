<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveManualPublicationAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'persona_id' => [
                'required',
                'integer',
                Rule::exists((new ManualPublicationPersona)->getTable(), 'id'),
            ],
            'platform' => ['required', Rule::in(ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('platform') === ManualPublicationAccount::PLATFORM_CUSTOM),
                'string',
                'max:120',
            ],
            'account_name' => ['required', 'string', 'max:160'],
            'profile_url' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('browser_adapter_enabled')
                    && trim((string) $this->input('account_uid')) === ''
                    && trim((string) $this->input('homepage_identifier')) === ''),
                'url:http,https',
                'max:1000',
            ],
            'editor_url' => ['nullable', 'required_if:browser_adapter_enabled,1', 'url:http,https', 'max:1000'],
            'account_uid' => ['nullable', 'string', 'max:255'],
            'homepage_identifier' => ['nullable', 'string', 'max:255'],
            'browser_adapter_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
