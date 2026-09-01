<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Http\Requests\Admin\SavePlatformAccountRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

class StorePlatformAccountRequest extends SavePlatformAccountRequest
{
    private const SENSITIVE_FIELDS = [
        'cookie',
        'cookies',
        'browser_session',
        'session',
        'session_token',
        'api_key',
        'api_secret',
        'api_credentials',
        'access_token',
        'refresh_token',
        'token',
        'password',
        'credential',
        'credentials',
        'secret',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sensitivePath = $this->findSensitiveFieldPath($this->all());
                if ($sensitivePath === null) {
                    return;
                }

                $rootField = explode('.', $sensitivePath, 2)[0];
                $validator->errors()->add(
                    $rootField,
                    '平台账号配置不得保存 Cookie、会话或 API 凭据：'.$sensitivePath,
                );
            },
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $fieldErrors = collect($validator->errors()->messages())
            ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
            ->all();

        throw new ApiException('validation_failed', '参数校验失败', 422, [
            'field_errors' => $fieldErrors,
        ]);
    }

    private function findSensitiveFieldPath(mixed $value, string $prefix = ''): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $key => $nestedValue) {
            $field = (string) $key;
            $path = $prefix === '' ? $field : $prefix.'.'.$field;
            $normalizedField = Str::lower(trim((string) preg_replace(
                '/[^a-z0-9]+/i',
                '_',
                Str::snake($field),
            ), '_'));

            if (in_array($normalizedField, self::SENSITIVE_FIELDS, true)) {
                return $path;
            }

            $nestedPath = $this->findSensitiveFieldPath($nestedValue, $path);
            if ($nestedPath !== null) {
                return $nestedPath;
            }
        }

        return null;
    }
}
