<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\ManualPublication;
use App\Services\Api\IdempotencyService;
use App\Services\BrowserOperations\ManualPublicationBrowserService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrowserManualPublicationController extends BaseApiController
{
    public function index(Request $request, ManualPublicationBrowserService $publications): JsonResponse
    {
        [$admin, $tokenId] = $this->actor($request);
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $page = $publications->queue($admin, $tokenId, $perPage, (string) $request->attributes->get('browser_client_version'));

        return $this->success($request, [
            'items' => collect($page->items())->map(fn (ManualPublication $item): array => $this->resource($item))->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'protocol_version' => 1,
        ]);
    }

    public function show(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        [$admin, $tokenId] = $this->actor($request);

        return $this->success($request, ['publication' => $this->resource(
            $publications->findVisible($admin, $tokenId, $manualPublicationId),
        )]);
    }

    public function media(Request $request, int $manualPublicationId, string $mediaKey, ManualPublicationBrowserService $publications): StreamedResponse
    {
        [$admin, $tokenId] = $this->actor($request);
        $snapshot = $publications->media($admin, $tokenId, $manualPublicationId, $mediaKey);
        $disk = Storage::disk((string) $snapshot->storage_disk);
        if (! $disk->exists((string) $snapshot->storage_path)) {
            throw new ApiException('media_unavailable', '工作单媒体文件不可用', 410);
        }

        return response()->streamDownload(static function () use ($disk, $snapshot): void {
            $stream = $disk->readStream((string) $snapshot->storage_path);
            if (! is_resource($stream)) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $snapshot->media_key, [
            'Content-Type' => (string) $snapshot->mime_type,
            'Content-Length' => (string) $snapshot->file_size,
            'Cache-Control' => 'private, no-store, max-age=0',
            'ETag' => '"'.(string) $snapshot->sha256.'"',
            'X-Content-SHA256' => (string) $snapshot->sha256,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function claim(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $revision = $this->validatedRevision($request);
        [$admin, $tokenId] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-publications.'.$manualPublicationId.'.claim', function () use ($request, $publications, $admin, $tokenId, $manualPublicationId, $revision): JsonResponse {
            $publication = $publications->claim($admin, $tokenId, $manualPublicationId, $revision, (string) $request->attributes->get('browser_client_version'));
            $this->audit($request, $admin, 'browser_publication.claimed', $publication);

            return $this->success($request, ['publication' => $this->resource($publication)]);
        });
    }

    public function heartbeat(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        [$admin, $tokenId] = $this->actor($request);
        $publication = $publications->heartbeat($admin, $tokenId, $manualPublicationId);

        return $this->success($request, [
            'alive' => true,
            'last_seen_at' => $publication->browser_last_seen_at?->toIso8601String(),
        ]);
    }

    public function release(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $revision = $this->validatedRevision($request);
        [$admin, $tokenId] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-publications.'.$manualPublicationId.'.release', function () use ($request, $publications, $admin, $tokenId, $manualPublicationId, $revision): JsonResponse {
            $publication = $publications->release($admin, $tokenId, $manualPublicationId, $revision);
            $this->audit($request, $admin, 'browser_publication.released', $publication);

            return $this->success($request, ['publication' => $this->resource($publication)]);
        });
    }

    public function receipt(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $payload = $this->validatedReceipt($request);
        [$admin, $tokenId] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-publications.'.$manualPublicationId.'.receipt', function () use ($request, $publications, $admin, $tokenId, $manualPublicationId, $payload): JsonResponse {
            $publication = $publications->recordReceipt(
                $admin,
                $tokenId,
                $manualPublicationId,
                (int) $payload['revision'],
                $payload,
                (string) $request->attributes->get('browser_client_version'),
            );
            $this->audit($request, $admin, 'browser_publication.'.(string) $publication->status, $publication);

            return $this->success($request, ['publication' => $this->resource($publication)]);
        });
    }

    public function draftReceipt(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $validator = Validator::make($request->all(), [
            'revision' => ['required', 'integer', 'min:1'],
            'adapter_version' => ['required', 'string', 'max:64'],
            'target_origin' => ['required', 'url:http,https', 'max:255'],
            'observed_account_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'filled_fields' => ['required', 'array', 'min:1'],
            'filled_fields.*' => ['string', 'in:title,summary,body,tags,images,cover,category'],
            'persistence' => ['nullable', 'in:remote_saved,editor_filled'],
            'draft_id' => ['nullable', 'string', 'max:255'],
            'draft_url' => ['nullable', 'url:http,https', 'max:1000'],
            'rendered_text_hash' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/D'],
            'heading_outline' => ['nullable', 'array'],
            'heading_outline.*.level' => ['required', 'integer', 'min:1', 'max:6'],
            'heading_outline.*.text' => ['required', 'string', 'max:500'],
            'expected_image_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'observed_image_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'media_upload_receipts' => ['nullable', 'array'],
            'media_upload_receipts.*.media_key' => ['required', 'string', 'max:80'],
            'media_upload_receipts.*.source_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            'media_upload_receipts.*.platform_url' => ['required', 'url:http,https', 'max:2000'],
            'finished_at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '草稿回执格式无效', 422, ['field_errors' => $validator->errors()->toArray()]);
        }
        $payload = $validator->validated();
        [$admin, $tokenId] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-publications.'.$manualPublicationId.'.draft-receipt', function () use ($request, $publications, $admin, $tokenId, $manualPublicationId, $payload): JsonResponse {
            $publication = $publications->recordDraftFilled(
                $admin,
                $tokenId,
                $manualPublicationId,
                (int) $payload['revision'],
                $payload,
                (string) $request->attributes->get('browser_client_version'),
            );
            $this->audit($request, $admin, 'browser_publication.draft_filled', $publication);

            return $this->success($request, ['publication' => $this->resource($publication)]);
        });
    }

    public function adapterFailure(Request $request, int $manualPublicationId, ManualPublicationBrowserService $publications): JsonResponse
    {
        $this->requireIdempotencyKey($request);
        $validator = Validator::make($request->all(), [
            'revision' => ['required', 'integer', 'min:1'],
            'adapter_version' => ['required', 'string', 'max:64'],
            'error_code' => ['required', Rule::in(ManualPublicationBrowserService::AUTO_DISABLE_ERROR_CODES)],
            'target_origin' => ['required', 'url:http,https', 'max:255'],
            'finished_at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '适配器失败回执格式无效', 422, ['field_errors' => $validator->errors()->toArray()]);
        }
        $payload = $validator->validated();
        [$admin, $tokenId] = $this->actor($request);

        return IdempotencyService::executeJson($request, 'browser-publications.'.$manualPublicationId.'.adapter-failure', function () use ($request, $publications, $admin, $tokenId, $manualPublicationId, $payload): JsonResponse {
            $publication = $publications->recordAdapterFailure(
                $admin,
                $tokenId,
                $manualPublicationId,
                (int) $payload['revision'],
                $payload,
                (string) $request->attributes->get('browser_client_version'),
            );
            $this->audit($request, $admin, 'browser_publication.adapter_disabled', $publication);

            return $this->success($request, ['publication' => $this->resource($publication)]);
        });
    }

    /** @return array{Admin,int} */
    private function actor(Request $request): array
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->whereKey($auth->auditAdminId)->where('status', 'active')->first();
        if (! $admin instanceof Admin) {
            throw new ApiException('unauthorized', '管理员账号不可用', 401);
        }

        return [$admin, (int) $auth->token['id']];
    }

    private function validatedRevision(Request $request): int
    {
        $validator = Validator::make($request->all(), ['revision' => ['required', 'integer', 'min:1']]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', 'revision 无效', 422, ['field_errors' => $validator->errors()->toArray()]);
        }

        return (int) $validator->validated()['revision'];
    }

    /** @return array<string,mixed> */
    private function validatedReceipt(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'revision' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', 'in:completed,failed,cancelled,outcome_unknown'],
            'completion_url' => ['nullable', 'url:http,https', 'max:1000'],
            'adapter_version' => ['required', 'string', 'max:64'],
            'target_origin' => ['required', 'url:http,https', 'max:255'],
            'observed_account_hash' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/D'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['required', 'date'],
            'error_code' => ['nullable', 'string', 'max:80'],
            'result_note' => ['nullable', 'string', 'max:5000'],
            'public_url_readback_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'public_url_readback_succeeded' => ['nullable', 'boolean'],
            'public_url_readback_url' => ['nullable', 'url:http,https', 'max:1000'],
        ]);
        if ($validator->fails()) {
            throw new ApiException('validation_failed', '执行凭证格式无效', 422, ['field_errors' => $validator->errors()->toArray()]);
        }

        return $validator->validated();
    }

    private function requireIdempotencyKey(Request $request): void
    {
        if (trim((string) $request->header('X-Idempotency-Key')) === '') {
            throw new ApiException('idempotency_key_required', '缺少 X-Idempotency-Key', 422);
        }
    }

    /** @return array<string,mixed> */
    private function resource(ManualPublication $publication): array
    {
        return [
            'id' => (int) $publication->id,
            'type' => (string) $publication->type,
            'platform' => (string) $publication->platform,
            'status' => (string) $publication->status,
            'revision' => (int) $publication->revision,
            'target_url' => $publication->target_url,
            'scheduled_at' => $publication->scheduled_at?->toIso8601String(),
            'publication_payload' => $publication->publication_payload,
            'completion_url' => $publication->completion_url,
            'account_verified' => $publication->status === ManualPublication::STATUS_DRAFT_FILLED
                && is_array($publication->draft_filled_receipt)
                && trim((string) ($publication->draft_filled_receipt['observed_account_hash'] ?? '')) !== '',
            'claim' => [
                'claimed_at' => $publication->browser_claimed_at?->toIso8601String(),
                'last_seen_at' => $publication->browser_last_seen_at?->toIso8601String(),
                'stale' => $publication->browser_last_seen_at?->lt(now()->subMinutes(ManualPublicationBrowserService::STALE_AFTER_MINUTES)) ?? false,
            ],
            'account' => $publication->account ? [
                'id' => (int) $publication->account->id,
                'name' => (string) $publication->account->account_name,
                'profile_url' => (string) $publication->account->profile_url,
                'editor_url' => (string) $publication->account->editor_url,
                'account_uid' => $publication->account->account_uid,
                'homepage_identifier' => $publication->account->homepage_identifier,
                'browser_adapter_enabled' => (bool) $publication->account->browser_adapter_enabled,
            ] : null,
            'persona' => $publication->persona ? [
                'id' => (int) $publication->persona->id,
                'name' => (string) $publication->persona->name,
            ] : null,
        ];
    }

    private function audit(Request $request, Admin $admin, string $action, ManualPublication $publication): void
    {
        AdminActivityLogger::log($admin, $action, [
            'request_method' => $request->method(),
            'page' => $request->path(),
            'target_type' => 'manual_publication',
            'target_id' => (int) $publication->id,
            'ip_address' => (string) ($request->ip() ?? ''),
            'details' => [
                'status' => (string) $publication->status,
                'revision' => (int) $publication->revision,
                'browser_client_version' => (string) $request->attributes->get('browser_client_version'),
            ],
        ]);
    }
}
