<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PromoteContentMasterRequest extends FormRequest
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
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'author_id' => ['required', 'integer', Rule::exists('authors', 'id')],
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('status', 'active')],
        ];
    }
}
