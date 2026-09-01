<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerateContentMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'ai_model_id' => ['required', 'integer', Rule::exists('ai_models', 'id')->where('status', 'active')],
        ];
    }
}
