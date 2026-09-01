<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\StorePlatformAccountRequest;
use App\Http\Requests\Api\UpdatePlatformAccountRequest;
use App\Models\Admin;
use App\Models\ManualPublicationAccount;
use App\Models\PlatformAdapter;
use App\Services\Api\IdempotencyService;
use App\Services\Content\PlatformAccountService;
use App\Services\Content\PlatformAdapterManager;
use App\Support\AdminActivityLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PlatformAccountController extends BaseApiController
{
    public function platforms(Request $request, PlatformAdapterManager $manager): JsonResponse
    {
        $items = PlatformAdapter::query()->where('status', 'active')->orderBy('id')->get()->map(fn (PlatformAdapter $adapter): array => [
            'key' => $adapter->key,
            'name' => $adapter->name,
            'version' => $adapter->version,
            'execution_mode' => $adapter->execution_mode,
            'capabilities' => $manager->forModel($adapter)->capabilities(),
        ])->all();

        return $this->success($request, ['items' => $items]);
    }

    public function index(Request $request, PlatformAccountService $accounts): JsonResponse
    {
        $paginator = ManualPublicationAccount::query()
            ->with(['adapter', 'persona', 'distributionChannel'])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 30))));

        return $this->success($request, [
            'items' => $paginator->getCollection()->map(fn (ManualPublicationAccount $account): array => $accounts->present($account))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    public function store(StorePlatformAccountRequest $request, PlatformAccountService $accounts): JsonResponse
    {
        $admin = $this->assertWriteAccess($request);

        return IdempotencyService::executeJson(
            $request,
            'POST /platform-accounts',
            function () use ($request, $accounts, $admin): JsonResponse {
                $input = $request->validated();
                $account = $this->performValidatedMutation(
                    fn (): ManualPublicationAccount => $accounts->create($input, $admin),
                );
                $this->logWrite($request, $admin, 'platform_account.created', $account, $input);

                return $this->success($request, $accounts->present($account), 201);
            },
        );
    }

    public function update(UpdatePlatformAccountRequest $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): JsonResponse
    {
        $admin = $this->assertWriteAccess($request);

        return IdempotencyService::executeJson(
            $request,
            'PATCH /platform-accounts/{id}',
            function () use ($request, $platformAccount, $accounts, $admin): JsonResponse {
                $input = $request->validated();
                $account = $this->performValidatedMutation(
                    fn (): ManualPublicationAccount => $accounts->update($platformAccount, $input),
                );
                $this->logWrite($request, $admin, 'platform_account.updated', $account, $input);

                return $this->success($request, $accounts->present($account));
            },
        );
    }

    public function disable(Request $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): JsonResponse
    {
        $admin = $this->assertWriteAccess($request);

        return IdempotencyService::executeJson(
            $request,
            'POST /platform-accounts/{id}/disable',
            function () use ($request, $platformAccount, $accounts, $admin): JsonResponse {
                $account = $accounts->disable($platformAccount);
                $this->logWrite($request, $admin, 'platform_account.disabled', $account, []);

                return $this->success($request, $accounts->present($account));
            },
        );
    }

    public function verify(Request $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): JsonResponse
    {
        $admin = $this->assertWriteAccess($request);

        $data = $request->validate([
            'observed_account_name' => ['nullable', 'string', 'max:160'],
            'browser_session_local' => ['nullable', 'boolean'],
            'captcha' => ['nullable', 'boolean'],
            'login_expired' => ['nullable', 'boolean'],
            'page_structure_drift' => ['nullable', 'boolean'],
            'cookie' => ['prohibited'], 'cookies' => ['prohibited'], 'browser_session' => ['prohibited'],
        ]);

        return IdempotencyService::executeJson(
            $request,
            'POST /platform-accounts/{id}/verify',
            function () use ($request, $platformAccount, $accounts, $admin, $data): JsonResponse {
                $result = $accounts->verifyConnection($platformAccount, $data);
                $account = $platformAccount->refresh();
                $this->logWrite($request, $admin, 'platform_account.verified', $account, $data, [
                    'verification_ok' => (bool) ($result['ok'] ?? false),
                    'error_code' => $account->last_error_code,
                ]);

                return $this->success($request, $result);
            },
        );
    }

    public function refresh(Request $request, ManualPublicationAccount $platformAccount, PlatformAccountService $accounts): JsonResponse
    {
        $admin = $this->assertWriteAccess($request);

        return IdempotencyService::executeJson(
            $request,
            'POST /platform-accounts/{id}/capabilities/refresh',
            function () use ($request, $platformAccount, $accounts, $admin): JsonResponse {
                $account = $accounts->refreshCapabilities($platformAccount);
                $this->logWrite($request, $admin, 'platform_account.capabilities_refreshed', $account, []);

                return $this->success($request, $accounts->present($account));
            },
        );
    }

    private function assertWriteAccess(Request $request): Admin
    {
        $this->normalizeIdempotencyKey($request);

        $admin = Admin::query()->find($this->auth($request)->auditAdminId);
        if (! $admin?->isSuperAdmin()) {
            throw new ApiException('forbidden', '仅超级管理员可以修改平台账号', 403);
        }

        return $admin;
    }

    private function normalizeIdempotencyKey(Request $request): void
    {
        $legacyKey = $request->header('X-Idempotency-Key');
        $standardKey = $request->header('Idempotency-Key');
        $legacyKey = is_string($legacyKey) ? $legacyKey : '';
        $standardKey = is_string($standardKey) ? $standardKey : '';
        $hasLegacyKey = trim($legacyKey) !== '';
        $hasStandardKey = trim($standardKey) !== '';

        if ($hasLegacyKey && $hasStandardKey && ! hash_equals($legacyKey, $standardKey)) {
            throw new ApiException(
                'invalid_idempotency_key',
                'Idempotency-Key 与 X-Idempotency-Key 不一致',
                422,
            );
        }

        $key = $hasLegacyKey ? $legacyKey : $standardKey;
        if (trim($key) === '') {
            throw new ApiException(
                'idempotency_key_required',
                '平台账号写接口必须提供 Idempotency-Key 或 X-Idempotency-Key',
                422,
            );
        }

        $request->headers->set('X-Idempotency-Key', $key);
    }

    /** @param callable(): ManualPublicationAccount $mutation */
    private function performValidatedMutation(callable $mutation): ManualPublicationAccount
    {
        try {
            return $mutation();
        } catch (ValidationException $exception) {
            $fieldErrors = collect($exception->errors())
                ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
                ->all();

            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        } catch (DomainException $exception) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['account' => $exception->getMessage()],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $extra
     */
    private function logWrite(
        Request $request,
        Admin $admin,
        string $action,
        ManualPublicationAccount $account,
        array $input,
        array $extra = [],
    ): void {
        AdminActivityLogger::log($admin, $action, [
            'request_method' => $request->method(),
            'page' => $request->path(),
            'target_type' => 'platform_account',
            'target_id' => (int) $account->getKey(),
            'ip_address' => (string) ($request->ip() ?? ''),
            'details' => array_replace([
                'input_hash' => IdempotencyService::requestHash($input),
                'adapter_key' => $account->adapter?->key,
                'connection_mode' => $account->connection_mode,
                'is_active' => (bool) $account->is_active,
            ], $extra),
        ]);
    }
}
