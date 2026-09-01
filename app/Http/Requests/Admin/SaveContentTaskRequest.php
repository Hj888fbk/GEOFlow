<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentTask;
use App\Models\ManualPublicationAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveContentTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_key' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'audience' => ['required', 'string', 'max:160'],
            'intent' => ['required', 'string', 'max:160'],
            'page_role' => ['required', 'string', 'max:80'],
            'primary_keyword' => ['required', 'string', 'max:160'],
            'secondary_keywords' => ['nullable', 'array', 'max:20'],
            'secondary_keywords.*' => ['string', 'max:160', 'distinct'],
            'target_channels' => ['required', 'array', 'min:1', 'max:12'],
            'target_channels.*.channel_key' => ['required', 'string', Rule::exists('platform_adapters', 'key')->where('status', 'active')],
            'target_channels.*.account_id' => ['nullable', 'integer', Rule::exists((new ManualPublicationAccount)->getTable(), 'id')->where('is_active', true)],
            'target_channels.*.content_type' => ['nullable', 'string', 'max:60'],
            'target_channels.*.scheduled_at' => ['nullable', 'date'],
            'priority' => ['required', 'integer', 'between:1,5'],
            'due_on' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(ContentTask::STATUSES)],
            'assigned_admin_id' => ['nullable', 'integer', Rule::exists('admins', 'id')->where('status', 'active')],
        ];
    }
}
