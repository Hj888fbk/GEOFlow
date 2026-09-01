<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublicationPersona;
use App\Models\PlatformAdapter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlatformAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'adapter_key' => ['required', 'string', Rule::exists('platform_adapters', 'key')->where('status', 'active')],
            'persona_id' => ['required', 'integer', Rule::exists((new ManualPublicationPersona)->getTable(), 'id')->where('is_active', true)],
            'distribution_channel_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('adapter_key') === PlatformAdapter::WORDPRESS),
                'integer',
                Rule::exists('distribution_channels', 'id')->where('status', 'active'),
            ],
            'account_name' => ['required', 'string', 'max:160'],
            'custom_platform' => ['nullable', 'string', 'max:120'],
            'profile_url' => ['nullable', 'url:http,https', 'max:1000'],
            'connection_mode' => ['required', Rule::in(['authenticated_api', 'browser_assisted', 'manual_export'])],
            'subject_name' => ['nullable', 'string', 'max:160'],
            'brand_voice' => ['nullable', 'string', 'max:5000'],
            'person_voice' => ['nullable', 'string', 'max:5000'],
            'content_types' => ['required', 'array', 'min:1', 'max:10'],
            'content_types.*' => ['string', 'max:60', 'distinct'],
            'publishing_rules' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'cookie' => ['prohibited'],
            'cookies' => ['prohibited'],
            'browser_session' => ['prohibited'],
            'api_credentials' => ['prohibited'],
        ];
    }
}
