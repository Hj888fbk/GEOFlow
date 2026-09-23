<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrowserOperatorClient;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationAccountSession;
use App\Models\ManualPublicationPersona;
use App\Services\Api\IdempotencyService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BrowserPublicationAccountController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        [$admin, $client] = $this->actor($request);
        $accounts = ManualPublicationAccount::query()
            ->with(['persona:id,name', 'desktopSessions' => fn ($query) => $query->where('browser_operator_client_id', $client->id)])
            ->where('is_active', true)
            ->whereIn('platform', ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)
            ->orderBy('persona_id')
            ->orderBy('platform')
            ->orderBy('id')
            ->get();

        $client->forceFill(['last_seen_at' => now()])->save();

        return $this->success($request, [
            'protocol_version' => 2,
            'client' => $this->clientResource($client),
            'accounts' => $accounts->map(fn (ManualPublicationAccount $account): array => $this->accountResource($account))->all(),
        ]);
    }

    public function personas(Request $request): JsonResponse
    {
        [$admin, $client] = $this->actor($request);
        $personas = ManualPublicationPersona::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ManualPublicationPersona $persona): array => ['id' => (int) $persona->id, 'name' => (string) $persona->name])
            ->all();

        return $this->success($request, ['personas' => $personas]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $validator = Validator::make($request->all(), [
            'persona_id' => ['required', 'integer', Rule::exists('manual_publication_personas', 'id')->where('is_active', true)],
            'platform' => ['required', Rule::in(ManualPublicationAccount::DRAFT_SYNC_PLATFORMS)],
            'account_name' => ['required', 'string', 'max:160'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '账号信息格式无效', 422, [
                'field_errors' => $validator->errors()->toArray(),
            ]);
        }
        $data = $validator->validated();
        [$admin, $client] = $this->actor($request);
        $this->requireSuperAdmin($admin);

        return IdempotencyService::executeJson($request, 'browser-accounts.store', function () use ($request, $admin, $data): JsonResponse {
            $account = DB::transaction(function () use ($admin, $data): ManualPublicationAccount {
                $name = trim((string) $data['account_name']);
                if ($name === '') {
                    throw new ApiException('validation_failed', '账号名称不能为空', 422, [
                        'field_errors' => ['account_name' => ['账号名称不能为空。']],
                    ]);
                }
                ManualPublicationPersona::query()
                    ->whereKey((int) $data['persona_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $exists = ManualPublicationAccount::query()
                    ->where('persona_id', (int) $data['persona_id'])
                    ->where('platform', (string) $data['platform'])
                    ->where('account_name', $name)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();
                if ($exists) {
                    throw new ApiException('account_exists', '该身份下同名同平台账号已存在', 409);
                }

                return ManualPublicationAccount::query()->create([
                    'persona_id' => (int) $data['persona_id'],
                    'platform' => (string) $data['platform'],
                    'custom_platform' => null,
                    'account_name' => $name,
                    'profile_url' => null,
                    'editor_url' => ManualPublicationAccount::editorUrlPresets()[(string) $data['platform']] ?? null,
                    'account_uid' => null,
                    'homepage_identifier' => null,
                    'browser_adapter_enabled' => true,
                    'notes' => null,
                    'is_active' => true,
                    'created_by_admin_id' => $admin->getKey(),
                ]);
            }, 3);
            AdminActivityLogger::log($admin, 'browser_publication_account.created', [
                'request_method' => $request->method(),
                'page' => $request->path(),
                'target_type' => 'manual_publication_account',
                'target_id' => (int) $account->id,
                'ip_address' => (string) ($request->ip() ?? ''),
                'details' => ['platform' => (string) $account->platform],
            ]);

            return $this->success($request, ['account' => $this->accountResource($account->fresh(['persona']))], 201);
        });
    }

    public function destroy(Request $request, int $accountId): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        [$admin, $client] = $this->actor($request);
        $this->requireSuperAdmin($admin);

        return IdempotencyService::executeJson($request, 'browser-accounts.'.$accountId.'.destroy', function () use ($request, $admin, $client, $accountId): JsonResponse {
            DB::transaction(function () use ($accountId): void {
                $account = ManualPublicationAccount::query()
                    ->whereKey($accountId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $account instanceof ManualPublicationAccount) {
                    throw new ApiException('account_not_found', '发布账号不存在或已停用', 404);
                }
                $activeStatuses = [
                    ManualPublication::STATUS_DRAFT,
                    ManualPublication::STATUS_READY,
                    ManualPublication::STATUS_IN_PROGRESS,
                    ManualPublication::STATUS_DRAFT_FILLED,
                    ManualPublication::STATUS_OUTCOME_UNKNOWN,
                ];
                $inFlight = ManualPublication::query()
                    ->where('account_id', $account->id)
                    ->whereIn('status', $activeStatuses)
                    ->lockForUpdate()
                    ->exists();
                if ($inFlight) {
                    throw new ApiException('account_has_active_publications', '该账号还有进行中的发布工单，请到发布中心处理完再删除', 409);
                }
                $account->forceFill(['is_active' => false])->save();
                ManualPublicationAccountSession::query()
                    ->where('manual_publication_account_id', $account->id)
                    ->delete();
            }, 3);
            AdminActivityLogger::log($admin, 'browser_publication_account.deleted', [
                'request_method' => $request->method(),
                'page' => $request->path(),
                'target_type' => 'manual_publication_account',
                'target_id' => $accountId,
                'ip_address' => (string) ($request->ip() ?? ''),
                'details' => ['client_id' => (int) $client->id],
            ]);

            return $this->success($request, ['deleted' => true]);
        });
    }

    public function bind(Request $request, int $accountId): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $data = $this->validateSessionPayload($request, binding: true);
        [$admin, $client] = $this->actor($request);
        if ((bool) ($data['replace_existing'] ?? false)) {
            $this->requireSuperAdmin($admin);
        }

        return IdempotencyService::executeJson($request, 'browser-accounts.'.$accountId.'.bind', function () use ($request, $admin, $client, $accountId, $data): JsonResponse {
            $session = DB::transaction(function () use ($client, $accountId, $data): ManualPublicationAccountSession {
                $account = ManualPublicationAccount::query()
                    ->whereKey($accountId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $account instanceof ManualPublicationAccount) {
                    throw new ApiException('account_not_found', '发布账号不存在或已停用', 404);
                }
                $this->bindObservedIdentity($account, $data);
                $this->assertObservedAccount($account, (string) $data['observed_account_hash']);

                return ManualPublicationAccountSession::query()->updateOrCreate([
                    'browser_operator_client_id' => (int) $client->id,
                    'manual_publication_account_id' => (int) $account->id,
                ], [
                    'status' => ManualPublicationAccountSession::STATUS_AUTHORIZED,
                    'observed_account_hash' => strtolower((string) $data['observed_account_hash']),
                    'last_error_code' => null,
                    'checked_at' => now(),
                ])->refresh();
            }, 3);
            AdminActivityLogger::log($admin, 'browser_publication_account.bound', [
                'request_method' => $request->method(),
                'page' => $request->path(),
                'target_type' => 'manual_publication_account',
                'target_id' => $accountId,
                'ip_address' => (string) ($request->ip() ?? ''),
                'details' => [
                    'client_id' => (int) $client->id,
                    'identity_replaced' => (bool) ($data['replace_existing'] ?? false),
                ],
            ]);

            return $this->success($request, ['session' => $this->sessionResource($session)]);
        });
    }

    public function status(Request $request, int $accountId): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $data = $this->validateSessionPayload($request);
        [$admin, $client] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-accounts.'.$accountId.'.status', function () use ($request, $admin, $client, $accountId, $data): JsonResponse {
            $session = DB::transaction(function () use ($client, $accountId, $data): ManualPublicationAccountSession {
                $account = ManualPublicationAccount::query()->whereKey($accountId)->where('is_active', true)->lockForUpdate()->first();
                if (! $account instanceof ManualPublicationAccount) {
                    throw new ApiException('account_not_found', '发布账号不存在或已停用', 404);
                }
                if ($data['status'] === ManualPublicationAccountSession::STATUS_AUTHORIZED) {
                    $this->assertObservedAccount($account, (string) ($data['observed_account_hash'] ?? ''));
                }

                return ManualPublicationAccountSession::query()->updateOrCreate([
                    'browser_operator_client_id' => (int) $client->id,
                    'manual_publication_account_id' => (int) $account->id,
                ], [
                    'status' => (string) $data['status'],
                    'observed_account_hash' => trim((string) ($data['observed_account_hash'] ?? '')) ?: null,
                    'last_error_code' => trim((string) ($data['last_error_code'] ?? '')) ?: null,
                    'checked_at' => now(),
                ])->refresh();
            }, 3);
            AdminActivityLogger::log($admin, 'browser_publication_account.status_reported', [
                'request_method' => $request->method(),
                'page' => $request->path(),
                'target_type' => 'manual_publication_account',
                'target_id' => $accountId,
                'ip_address' => (string) ($request->ip() ?? ''),
                'details' => ['client_id' => (int) $client->id, 'status' => (string) $data['status']],
            ]);

            return $this->success($request, ['session' => $this->sessionResource($session)]);
        });
    }

    /** @return array{Admin,BrowserOperatorClient} */
    private function actor(Request $request): array
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->whereKey($auth->auditAdminId)->where('status', 'active')->first();
        if (! $admin instanceof Admin) {
            throw new ApiException('unauthorized', '管理员账号不可用', 401);
        }
        $client = BrowserOperatorClient::query()->where('personal_access_token_id', (int) $auth->token['id'])->first();
        if (! $client instanceof BrowserOperatorClient) {
            throw new ApiException('client_repair_required', '请重新配对发布助手以启用账号会话', 409);
        }

        return [$admin, $client];
    }

    private function requireSuperAdmin(Admin $admin): void
    {
        if (! $admin->isSuperAdmin()) {
            throw new ApiException('forbidden', '只有超级管理员可以维护发布账号', 403);
        }
    }

    /** @return array<string,mixed> */
    private function validateSessionPayload(Request $request, bool $binding = false): array
    {
        $validator = Validator::make($request->all(), [
            'confirmed' => [$binding ? 'accepted' : 'nullable'],
            'status' => [$binding ? 'nullable' : 'required', Rule::in(ManualPublicationAccountSession::STATUSES)],
            'observed_account_hash' => [$binding ? 'required' : 'nullable', 'regex:/\A[a-f0-9]{64}\z/D'],
            'observed_account' => ['nullable', 'array:type,value'],
            'observed_account.type' => ['required_with:observed_account', Rule::in(['profile_url', 'account_uid', 'homepage_identifier'])],
            'observed_account.value' => ['required_with:observed_account', 'string', 'max:1000'],
            'replace_existing' => [$binding ? 'sometimes' : 'nullable', 'boolean'],
            'last_error_code' => ['nullable', 'string', 'max:80'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '账号会话状态格式无效', 422, [
                'field_errors' => $validator->errors()->toArray(),
            ]);
        }
        $data = $validator->validated();
        if ($binding) {
            $data['status'] = ManualPublicationAccountSession::STATUS_AUTHORIZED;
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    private function bindObservedIdentity(ManualPublicationAccount $account, array $data): void
    {
        $hasExistingIdentity = trim((string) $account->profile_url) !== ''
            || trim((string) $account->account_uid) !== ''
            || trim((string) $account->homepage_identifier) !== '';
        if ($hasExistingIdentity && ! (bool) ($data['replace_existing'] ?? false)) {
            return;
        }
        $observed = $data['observed_account'] ?? null;
        if (! is_array($observed)) {
            throw new ApiException('account_identity_required', '账号尚未绑定，请确认助手识别到的公开账号标识', 409);
        }
        $type = (string) ($observed['type'] ?? '');
        $value = trim((string) ($observed['value'] ?? ''));
        if ($value === ''
            || preg_match('/[\p{L}\p{N}]/u', $value) !== 1
            || preg_match('/password|cookie|session|bearer|token|secret/i', $value) === 1) {
            throw new ApiException('invalid_account_identity', '公开账号标识无效', 422);
        }
        if ($type === 'profile_url') {
            $value = $this->validatedProfileUrl($account, $value);
            $proof = $this->normalizeProfileUrl($value);
        } elseif ($type === 'account_uid') {
            $value = mb_substr($value, 0, 255);
            $proof = 'uid:'.strtolower($value);
        } else {
            $type = 'homepage_identifier';
            $value = mb_substr($value, 0, 255);
            $proof = 'homepage:'.strtolower($value);
        }
        if (! hash_equals(hash('sha256', $proof), strtolower((string) $data['observed_account_hash']))) {
            throw new ApiException('account_mismatch', '确认的公开账号标识与检测结果不一致', 409);
        }
        $account->forceFill([
            'profile_url' => null,
            'account_uid' => null,
            'homepage_identifier' => null,
            $type => $value,
        ])->save();
    }

    private function validatedProfileUrl(ManualPublicationAccount $account, string $value): string
    {
        $url = parse_url($value);
        $host = strtolower((string) ($url['host'] ?? ''));
        $editorHost = strtolower((string) parse_url((string) (ManualPublicationAccount::editorUrlPresets()[$account->platform] ?? ''), PHP_URL_HOST));
        $baseHost = implode('.', array_slice(explode('.', $editorHost), -2));
        if (strtolower((string) ($url['scheme'] ?? '')) !== 'https' || $host === '' || $baseHost === ''
            || ($host !== $baseHost && ! str_ends_with($host, '.'.$baseHost))) {
            throw new ApiException('invalid_account_identity', '账号主页地址不属于当前平台', 422);
        }

        return $this->normalizeProfileUrl($value);
    }

    private function assertObservedAccount(ManualPublicationAccount $account, string $observedHash): void
    {
        $expected = array_values(array_filter([
            trim((string) $account->profile_url) === '' ? null : hash('sha256', $this->normalizeProfileUrl((string) $account->profile_url)),
            trim((string) $account->account_uid) === '' ? null : hash('sha256', 'uid:'.strtolower(trim((string) $account->account_uid))),
            trim((string) $account->homepage_identifier) === '' ? null : hash('sha256', 'homepage:'.strtolower(trim((string) $account->homepage_identifier))),
        ]));
        if ($expected === [] || ! collect($expected)->contains(
            static fn (string $hash): bool => hash_equals($hash, strtolower($observedHash)),
        )) {
            throw new ApiException('account_mismatch', '检测到的登录账号与所选账号不一致', 409);
        }
    }

    private function normalizeProfileUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port).strtolower($path);
    }

    private function requireIdempotencyKey(Request $request): void
    {
        if (trim((string) $request->header('X-Idempotency-Key')) === '') {
            throw new ApiException('idempotency_key_required', '缺少 X-Idempotency-Key', 422);
        }
    }

    /** @return array<string,mixed> */
    private function accountResource(ManualPublicationAccount $account): array
    {
        $session = $account->desktopSessions->first();

        return [
            'id' => (int) $account->id,
            'platform' => (string) $account->platform,
            'account_name' => (string) $account->account_name,
            'profile_url' => $account->profile_url,
            'editor_url' => $account->editor_url,
            'account_uid' => $account->account_uid,
            'homepage_identifier' => $account->homepage_identifier,
            'persona' => $account->persona ? ['id' => (int) $account->persona->id, 'name' => (string) $account->persona->name] : null,
            'session' => $session instanceof ManualPublicationAccountSession ? $this->sessionResource($session) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function sessionResource(ManualPublicationAccountSession $session): array
    {
        return [
            'account_id' => (int) $session->manual_publication_account_id,
            'status' => (string) $session->status,
            'last_error_code' => $session->last_error_code,
            'checked_at' => $session->checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function clientResource(BrowserOperatorClient $client): array
    {
        return [
            'id' => (int) $client->id,
            'type' => (string) $client->client_type,
            'name' => (string) $client->client_name,
            'version' => (string) $client->client_version,
            'capabilities' => array_values((array) $client->capabilities),
        ];
    }
}
