<?php

namespace App\Http\Requests\Api;

class UpdatePlatformAccountRequest extends StorePlatformAccountRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as $field => $fieldRules) {
            if (! str_contains($field, '.')) {
                $rules[$field] = array_merge(['sometimes'], (array) $fieldRules);
            }
        }

        return $rules;
    }
}
