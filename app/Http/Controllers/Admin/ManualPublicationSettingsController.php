<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveManualPublicationAccountRequest;
use App\Http\Requests\Admin\SaveManualPublicationPersonaRequest;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\View\View;

class ManualPublicationSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.manual-publications.settings', [
            'pageTitle' => __('admin.manual_publications.settings.title'),
            'activeMenu' => 'publishing',
            'adminSiteName' => AdminWeb::siteName(),
            'personas' => ManualPublicationPersona::query()->withCount('accounts')->orderByDesc('is_active')->orderBy('name')->get(),
            'accounts' => ManualPublicationAccount::query()->with('persona:id,name')->orderByDesc('is_active')->orderBy('account_name')->get(),
            'platforms' => ManualPublicationAccount::PLATFORMS,
            'editorUrlPresets' => ManualPublicationAccount::editorUrlPresets(),
            'platformDraftModes' => [
                ManualPublicationAccount::PLATFORM_BAIJIAHAO => '草稿接口保存 + 回读（待真实账号验收）',
                ManualPublicationAccount::PLATFORM_SOHU_MEDIA => '草稿接口保存 + 回读（待真实账号验收）',
                ManualPublicationAccount::PLATFORM_JIANSHU => '草稿接口保存 + 回读（待真实账号验收）',
                ManualPublicationAccount::PLATFORM_CSDN => '草稿接口保存 + 回读（待真实账号验收）',
                ManualPublicationAccount::PLATFORM_ZHIHU_COLUMN => '编辑器填充（未确认远端保存）',
                ManualPublicationAccount::PLATFORM_TOUTIAO => '编辑器填充（未确认远端保存）',
                ManualPublicationAccount::PLATFORM_NETEASE_MEDIA => '编辑器填充（未确认远端保存）',
                ManualPublicationAccount::PLATFORM_QQ_PENGUIN => '编辑器填充（未确认远端保存）',
                ManualPublicationAccount::PLATFORM_DAYU => '编辑器填充（未确认远端保存）',
                ManualPublicationAccount::PLATFORM_DOUYIN => '长文编辑器填充（需账号长文权限）',
            ],
            'extensionVersion' => '0.3.1',
            'extensionSha256' => $this->extensionSha256(),
        ]);
    }

    public function downloadExtension(): BinaryFileResponse
    {
        $path = $this->extensionPath();
        abort_unless(is_file($path), 404);

        return response()->download($path, 'geoflow-chrome-operator-0.3.1.zip', [
            'Content-Type' => 'application/zip',
            'X-Content-SHA256' => hash_file('sha256', $path) ?: '',
        ]);
    }

    public function storePersona(SaveManualPublicationPersonaRequest $request): RedirectResponse
    {
        ManualPublicationPersona::query()->create($this->personaPayload($request) + [
            'created_by_admin_id' => $request->user('admin')?->getAuthIdentifier(),
        ]);

        return back()->with('message', __('admin.manual_publications.settings.persona_saved'));
    }

    public function updatePersona(SaveManualPublicationPersonaRequest $request, int $personaId): RedirectResponse
    {
        ManualPublicationPersona::query()->whereKey($personaId)->firstOrFail()->update($this->personaPayload($request));

        return back()->with('message', __('admin.manual_publications.settings.persona_saved'));
    }

    public function storeAccount(SaveManualPublicationAccountRequest $request): RedirectResponse
    {
        ManualPublicationAccount::query()->create($this->accountPayload($request) + [
            'created_by_admin_id' => $request->user('admin')?->getAuthIdentifier(),
        ]);

        return back()->with('message', __('admin.manual_publications.settings.account_saved'));
    }

    public function updateAccount(SaveManualPublicationAccountRequest $request, int $accountId): RedirectResponse
    {
        ManualPublicationAccount::query()->whereKey($accountId)->firstOrFail()->update($this->accountPayload($request));

        return back()->with('message', __('admin.manual_publications.settings.account_saved'));
    }

    /** @return array<string, mixed> */
    private function personaPayload(SaveManualPublicationPersonaRequest $request): array
    {
        $data = $request->validated();

        return [
            'name' => trim((string) $data['name']),
            'bio' => trim((string) ($data['bio'] ?? '')) ?: null,
            'tone' => trim((string) ($data['tone'] ?? '')) ?: null,
            'domain' => trim((string) ($data['domain'] ?? '')) ?: null,
            'disclosure_text' => trim((string) ($data['disclosure_text'] ?? '')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return array<string, mixed> */
    private function accountPayload(SaveManualPublicationAccountRequest $request): array
    {
        $data = $request->validated();

        return [
            'persona_id' => (int) $data['persona_id'],
            'platform' => (string) $data['platform'],
            'custom_platform' => trim((string) ($data['custom_platform'] ?? '')) ?: null,
            'account_name' => trim((string) $data['account_name']),
            'profile_url' => trim((string) ($data['profile_url'] ?? '')) ?: null,
            'editor_url' => trim((string) ($data['editor_url'] ?? '')) ?: null,
            'account_uid' => trim((string) ($data['account_uid'] ?? '')) ?: null,
            'homepage_identifier' => trim((string) ($data['homepage_identifier'] ?? '')) ?: null,
            'browser_adapter_enabled' => $request->boolean('browser_adapter_enabled'),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function extensionPath(): string
    {
        return base_path('dist/browser-extension/geoflow-chrome-operator-0.3.1.zip');
    }

    private function extensionSha256(): ?string
    {
        $path = $this->extensionPath();

        return is_file($path) ? (hash_file('sha256', $path) ?: null) : null;
    }
}
