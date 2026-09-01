<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyPlatformAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'observed_account_name' => ['nullable', 'string', 'max:160'],
            'browser_session_local' => ['nullable', 'boolean'],
            'captcha' => ['nullable', 'boolean'],
            'login_expired' => ['nullable', 'boolean'],
            'page_structure_drift' => ['nullable', 'boolean'],
            'cookie' => ['prohibited'],
            'cookies' => ['prohibited'],
            'browser_session' => ['prohibited'],
        ];
    }
}
