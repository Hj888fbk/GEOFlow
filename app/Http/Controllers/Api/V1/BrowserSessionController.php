<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Admin;
use App\Models\BrowserOperatorClient;
use App\Services\Api\ApiTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BrowserSessionController extends BaseApiController
{
    public function show(Request $request): JsonResponse
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->findOrFail($auth->auditAdminId);
        $client = BrowserOperatorClient::query()
            ->where('personal_access_token_id', (int) $auth->token['id'])
            ->first();
        if ($client instanceof BrowserOperatorClient) {
            $client->forceFill([
                'client_version' => (string) $request->attributes->get('browser_client_version'),
                'last_seen_at' => now(),
            ])->save();
        }

        return $this->success($request, [
            'protocol_version' => (int) $request->attributes->get('browser_protocol_version', 1),
            'admin' => [
                'id' => (int) $admin->getKey(),
                'display_name' => $admin->name,
                'role' => (string) $admin->role,
            ],
            'scopes' => array_values((array) ($auth->token['scopes'] ?? [])),
            'client' => $client instanceof BrowserOperatorClient ? [
                'id' => (int) $client->id,
                'type' => (string) $client->client_type,
                'name' => (string) $client->client_name,
                'version' => (string) $client->client_version,
                'capabilities' => array_values((array) $client->capabilities),
            ] : null,
        ]);
    }

    public function destroy(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->findOrFail($auth->auditAdminId);
        $tokens->revokeToken((int) $auth->token['id']);
        AdminActivityLogger::log($admin, 'browser_client.revoked_self', [
            'request_method' => 'DELETE',
            'page' => $request->path(),
            'target_type' => 'personal_access_token',
            'target_id' => (int) $auth->token['id'],
        ]);

        return $this->success($request, ['revoked' => true]);
    }

    public function desktopUpdate(Request $request): JsonResponse
    {
        $path = $this->desktopPublisherPath();
        $signaturePath = $path.'.sig';
        $available = is_file($path) && is_file($signaturePath);

        return $this->success($request, [
            'protocol_version' => 2,
            'desktop_update' => [
                'available' => $available,
                'version' => '0.1.0',
                'platform' => 'win32-x64',
                'sha256' => $available ? (hash_file('sha256', $path) ?: null) : null,
                'signature' => $available ? trim((string) file_get_contents($signaturePath)) : null,
                'download_url' => $available ? url('/api/v1/browser-operations/desktop-update/package') : null,
            ],
        ]);
    }

    public function desktopUpdatePackage(): BinaryFileResponse
    {
        $path = $this->desktopPublisherPath();
        abort_unless(is_file($path) && is_file($path.'.sig'), 404);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'X-Content-SHA256' => hash_file('sha256', $path) ?: '',
            'X-GEOFlow-Package-Signature' => trim((string) file_get_contents($path.'.sig')),
        ]);
    }

    private function desktopPublisherPath(): string
    {
        return base_path('dist/desktop-publisher/GEOFlow-Desktop-Publisher-0.1.0-win-x64.exe');
    }
}
