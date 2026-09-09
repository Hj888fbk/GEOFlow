<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['account_uid', 'homepage_identifier', 'notes'] as $field) {
                if ($this->containsCredential((string) $this->input($field))) {
                    $validator->errors()->add($field, '这里只允许填写公开账号信息，禁止保存 Cookie、密码或 Token。');
                }
            }
            foreach (['profile_url', 'editor_url'] as $field) {
                if ($this->urlContainsCredential((string) $this->input($field))) {
                    $validator->errors()->add($field, 'URL 中不得包含用户名、密码、Cookie 或 Token 参数。');
                }
            }
            if (! $this->boolean('browser_adapter_enabled')) {
                return;
            }
            $platform = (string) $this->input('platform');
            $url = trim((string) $this->input('editor_url'));
            $allowed = match ($platform) {
                ManualPublicationAccount::PLATFORM_BAIJIAHAO => ['baijiahao.baidu.com'],
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA => ['mp.sohu.com'],
                ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN => ['zhihu.com'],
                ManualPublicationAccount::PLATFORM_CSDN => ['csdn.net'],
                ManualPublicationAccount::PLATFORM_TOUTIAO => ['mp.toutiao.com'],
                ManualPublicationAccount::PLATFORM_NETEASE_MEDIA => ['mp.163.com'],
                ManualPublicationAccount::PLATFORM_QQ_PENGUIN => ['om.qq.com', 'mp.qq.com'],
                ManualPublicationAccount::PLATFORM_DAYU => ['mp.dayu.com'],
                ManualPublicationAccount::PLATFORM_JIANSHU => ['jianshu.com'],
                ManualPublicationAccount::PLATFORM_DOUYIN => ['creator.douyin.com'],
                default => [],
            };
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($allowed !== [] && ! collect($allowed)->contains(static fn (string $item): bool => $host === $item || str_ends_with($host, '.'.$item))) {
                $validator->errors()->add('editor_url', '平台编辑入口域名与所选平台不一致。');
            }
        });
    }

    private function containsCredential(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/(?:\b(?:cookie|password|passwd|token|authorization|bearer|session(?:id)?|csrf)\b|密码|令牌|验证码)\s*[:：=]/iu', $value) === 1
            || preg_match('/\beyJ[A-Za-z0-9_-]{30,}\.[A-Za-z0-9_-]{10,}/', $value) === 1
            || preg_match_all('/(?:^|;)\s*[A-Za-z_][A-Za-z0-9_-]{2,}\s*=\s*[^;]{8,}/', $value) >= 2;
    }

    private function urlContainsCredential(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return true;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/(?:cookie|pass(?:word|wd)?|token|secret|auth|session|csrf)/i', (string) $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
