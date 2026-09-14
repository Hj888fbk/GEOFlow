<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\BrowserOperatorClient;
use App\Services\BrowserOperations\DeviceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BrowserDeviceAuthorizationController extends BaseApiController
{
    public function store(Request $request, DeviceAuthorizationService $authorizations): JsonResponse
    {
        $this->ensureTrustedInstance($request);
        $validator = Validator::make($request->all(), [
            'client_name' => ['nullable', 'string', 'max:80'],
            'client_type' => ['nullable', Rule::in(BrowserOperatorClient::TYPES)],
            'capabilities' => ['nullable', 'array', 'max:50'],
            'capabilities.*' => ['required', 'string', 'max:80', 'distinct'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '客户端信息格式无效', 422, [
                'field_errors' => $validator->errors()->toArray(),
            ]);
        }
        $data = $validator->validated();
        $clientType = (string) ($data['client_type'] ?? BrowserOperatorClient::TYPE_EXTENSION);
        $clientName = trim((string) ($data['client_name'] ?? ''))
            ?: ($clientType === BrowserOperatorClient::TYPE_DESKTOP ? 'GEOFlow Desktop Publisher' : 'GEOFlow Chrome');

        $authorization = $authorizations->create(
            $clientName,
            $clientType,
            array_values((array) ($data['capabilities'] ?? [])),
            (int) $request->attributes->get('browser_protocol_version', 1),
        );
        $verificationUri = route('admin.manual-publications.browser-connect.show');
        $authorization['verification_uri'] = $verificationUri;
        $authorization['verification_uri_complete'] = $verificationUri.'?'.http_build_query([
            'user_code' => $authorization['user_code'],
        ]);

        return $this->success($request, $authorization);
    }

    public function token(Request $request, DeviceAuthorizationService $authorizations): JsonResponse
    {
        $this->ensureTrustedInstance($request);
        $deviceCode = trim((string) $request->input('device_code'));
        if ($deviceCode === '' || strlen($deviceCode) > 128) {
            throw new ApiException('validation_failed', '设备码格式无效', 422);
        }

        return $this->success($request, $authorizations->exchange(
            $deviceCode,
            (string) $request->attributes->get('browser_client_version'),
        ));
    }

    private function ensureTrustedInstance(Request $request): void
    {
        $host = strtolower($request->getHost());
        if (! $request->isSecure() && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new ApiException('insecure_instance', '远程 GEOFlow 实例必须使用 HTTPS', 400);
        }
    }
}
