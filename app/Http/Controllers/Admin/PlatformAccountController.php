<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePlatformAccountRequest;
use App\Http\Requests\Admin\VerifyPlatformAccountRequest;
use App\Models\DistributionChannel;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\PlatformAdapter;
use App\Services\Content\PlatformAccountService;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PlatformAccountController extends Controller
{
    public function index(): View
    {
        return view('admin.hengjia-content.accounts', [
            'pageTitle' => __('hengjia_content.pages.accounts.title'),
            'activeMenu' => 'hengjia_content',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => 'accounts',
            'accounts' => ManualPublicationAccount::query()
                ->with(['adapter:id,key,name,version,execution_mode', 'persona:id,name', 'distributionChannel:id,name,domain,status'])
                ->withCount(['publications', 'channelVariants'])
                ->orderByDesc('is_active')
                ->orderBy('account_name')
                ->paginate(30),
            'adapters' => PlatformAdapter::query()->where('status', 'active')->orderBy('id')->get(),
            'personas' => ManualPublicationPersona::query()->where('is_active', true)->orderBy('name')->get(),
            'distributionChannels' => DistributionChannel::query()->where('status', DistributionChannel::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }

    public function store(SavePlatformAccountRequest $request, PlatformAccountService $accounts): RedirectResponse
    {
        $account = $accounts->create($request->validated(), $request->user('admin'));
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'hengjia_content:platform_account_created', [
            'platform_account_id' => $account->getKey(),
            'platform_adapter_id' => $account->platform_adapter_id,
            'connection_mode' => $account->connection_mode,
            'input_hash' => $this->hashPayload($request->validated()),
        ]);

        return back()->with('message', __('hengjia_content.messages.account_created', ['id' => $account->id]));
    }

    public function update(SavePlatformAccountRequest $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): RedirectResponse
    {
        $account = $accounts->update($platformAccount, $request->validated());
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'hengjia_content:platform_account_updated', [
            'platform_account_id' => $account->getKey(),
            'platform_adapter_id' => $account->platform_adapter_id,
            'connection_mode' => $account->connection_mode,
            'input_hash' => $this->hashPayload($request->validated()),
        ]);

        return back()->with('message', __('hengjia_content.messages.account_updated'));
    }

    public function disable(Request $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): RedirectResponse
    {
        abort_unless($request->user('admin')?->isSuperAdmin(), 403);
        $account = $accounts->disable($platformAccount);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'hengjia_content:platform_account_disabled', [
            'platform_account_id' => $account->getKey(),
        ]);

        return back()->with('message', __('hengjia_content.messages.account_disabled'));
    }

    public function verify(VerifyPlatformAccountRequest $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): RedirectResponse
    {
        $result = $accounts->verifyConnection($platformAccount, $request->validated());
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'hengjia_content:platform_account_verified', [
            'platform_account_id' => $platformAccount->getKey(),
            'ok' => (bool) $result['ok'],
            'blocker_codes' => array_values(array_filter(array_column((array) ($result['blockers'] ?? []), 'code'))),
            'input_hash' => $this->hashPayload($request->validated()),
        ]);

        return back()->with(
            $result['ok'] ? 'message' : 'error',
            __('hengjia_content.messages.'.($result['ok'] ? 'account_verified' : 'account_verification_failed')),
        );
    }

    public function refresh(Request $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): RedirectResponse
    {
        abort_unless($request->user('admin')?->isSuperAdmin(), 403);
        $account = $accounts->refreshCapabilities($platformAccount);
        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'hengjia_content:platform_account_capabilities_refreshed', [
            'platform_account_id' => $account->getKey(),
            'adapter_version' => $account->adapter_version,
        ]);

        return back()->with('message', __('hengjia_content.messages.account_capabilities_refreshed'));
    }

    /** @param array<string,mixed> $payload */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
