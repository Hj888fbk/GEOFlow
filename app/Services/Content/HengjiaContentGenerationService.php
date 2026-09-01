<?php

namespace App\Services\Content;

use App\Ai\Agents\HengjiaRubberJointContentAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentGenerationRun;
use App\Models\ContentMaster;
use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\EvidenceClaim;
use App\Models\PromptRecipeVersion;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class HengjiaContentGenerationService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly HengjiaContentPackageValidator $validator,
    ) {}

    public function generate(ContentTask $task, AiModel $aiModel, Admin $admin): ContentMaster
    {
        if ((string) $aiModel->status !== 'active') {
            throw new \DomainException('所选 AI 模型未启用。');
        }

        $recipes = PromptRecipeVersion::query()
            ->where('status', PromptRecipeVersion::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->keyBy('recipe_key');
        $primaryRecipe = $recipes->get('master_content_generation');
        if (! $primaryRecipe instanceof PromptRecipeVersion || $recipes->count() < 8) {
            throw new \DomainException('八段生产 Prompt Recipe 尚未完整启用。');
        }

        [$task, $input, $inputHash, $run] = DB::transaction(function () use (
            $task,
            $recipes,
            $primaryRecipe,
            $admin,
            $aiModel,
        ): array {
            $lockedTask = ContentTask::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedTask->isBlocked()) {
                throw new \DomainException('任务存在证据阻断，不能进入内容生成。');
            }
            if ($lockedTask->status !== ContentTask::STATUS_READY) {
                throw new \DomainException('内容任务当前状态不能开始生成，可能已有运行正在处理。');
            }

            $input = $this->inputSnapshot($lockedTask, $recipes->values()->all());
            $inputHash = $this->hash($input);
            $run = ContentGenerationRun::query()->create([
                'run_key' => (string) Str::uuid(),
                'content_task_id' => $lockedTask->getKey(),
                'prompt_recipe_version_id' => $primaryRecipe->getKey(),
                'created_by_admin_id' => $admin->getKey(),
                'status' => ContentGenerationRun::STATUS_RUNNING,
                'input_hash' => $inputHash,
                'provider' => null,
                'model' => (string) $aiModel->model_id,
                'input_snapshot' => $input,
                'started_at' => now(),
            ]);
            $lockedTask->forceFill(['status' => ContentTask::STATUS_GENERATING])->save();

            return [$lockedTask, $input, $inputHash, $run];
        });

        try {
            $reservation = $this->usageQuota->reserveModel($aiModel);
        } catch (Throwable $exception) {
            $this->failRun($run, $task, 'ai_quota_reservation_failed', mb_substr($exception->getMessage(), 0, 1000));

            throw $exception;
        }
        if ($reservation === null) {
            $this->failRun($run, $task, 'ai_quota_unavailable', 'AI 模型不可用或已达到今日调用上限。');

            throw new RuntimeException('AI 模型不可用或已达到今日调用上限。');
        }

        try {
            [$provider, $providerUrl, $modelId] = $this->resolveRuntime($aiModel);
            $pipeline = $recipes->map(fn (PromptRecipeVersion $recipe): string => "## {$recipe->recipe_key} v{$recipe->version}\n{$recipe->template}")->implode("\n\n");
            $agent = new HengjiaRubberJointContentAgent(
                $pipeline,
                $inputHash,
                (string) $primaryRecipe->version,
                $modelId,
            );
            $response = $agent->prompt(
                prompt: "请根据以下受信输入生成内容包。输入 JSON：\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                attachments: [],
                provider: $provider,
                model: $modelId,
                timeout: 180,
            );
            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('AI 未返回结构化内容包。');
            }

            $package = $response->toArray();
            $package['schema_version'] = ContentMaster::SCHEMA_VERSION;
            $package['prompt_meta'] = [
                'recipe_version' => (string) $primaryRecipe->version,
                'input_hash' => $inputHash,
                'model' => $modelId,
                'generated_at' => now()->toAtomString(),
                'pipeline_versions' => $recipes->map(fn (PromptRecipeVersion $recipe): string => $recipe->recipe_key.'@'.$recipe->version)->values()->all(),
            ];
            $validation = $this->validator->validate($package, $task);
            $blockingIssues = array_values(array_merge($validation['errors'], $validation['blockers']));
            $packageHash = $this->validator->hash($package);
            $seo = is_array($package['seo'] ?? null) ? $package['seo'] : [];

            $master = DB::transaction(function () use (
                $task, $primaryRecipe, $package, $validation, $blockingIssues, $packageHash, $provider, $modelId, $run, $response, $seo,
            ): ContentMaster {
                $lockedRun = ContentGenerationRun::query()
                    ->whereKey($run->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedTask = ContentTask::query()
                    ->whereKey($task->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedRun->status !== ContentGenerationRun::STATUS_RUNNING || $lockedTask->status !== ContentTask::STATUS_GENERATING) {
                    throw new \DomainException('生成运行状态已变化，已停止保存模型结果。');
                }
                $master = ContentMaster::query()->create([
                    'content_task_id' => $lockedTask->getKey(),
                    'prompt_recipe_version_id' => $primaryRecipe->getKey(),
                    'schema_version' => ContentMaster::SCHEMA_VERSION,
                    'title' => (string) ($seo['title'] ?? $task->title),
                    'h1' => (string) ($seo['h1'] ?? $task->title),
                    'slug' => (string) ($seo['slug'] ?? Str::slug($task->primary_keyword)),
                    'summary' => (string) ($seo['summary'] ?? ''),
                    'meta_description' => (string) ($seo['meta_description'] ?? ''),
                    'package' => $package,
                    'status' => $validation['valid'] ? ContentMaster::STATUS_IN_REVIEW : ContentMaster::STATUS_BLOCKED,
                    'blockers' => $blockingIssues,
                    'package_hash' => $packageHash,
                    'provider' => $provider,
                    'model' => $modelId,
                    'generated_at' => now(),
                ]);

                $lockedRun->forceFill([
                    'content_master_id' => $master->getKey(),
                    'status' => $validation['valid'] ? ContentGenerationRun::STATUS_COMPLETED : ContentGenerationRun::STATUS_BLOCKED,
                    'output_hash' => $packageHash,
                    'provider' => $provider,
                    'model' => $modelId,
                    'validation_result' => $validation,
                    'token_usage' => $response->usage->toArray(),
                    'completed_at' => now(),
                ])->save();

                $lockedTask->forceFill([
                    'status' => $validation['valid'] ? ContentTask::STATUS_IN_REVIEW : ContentTask::STATUS_BLOCKED,
                    'blockers' => $blockingIssues,
                ])->save();

                return $master;
            });

        } catch (Throwable $exception) {
            $this->usageQuota->releaseModel($reservation);
            $message = OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl ?? '');
            $this->failRun($run, $task, 'content_generation_failed', mb_substr($message, 0, 1000));

            throw new RuntimeException('恒佳内容生成失败：'.$message, 0, $exception);
        }

        try {
            $this->usageQuota->recordModelSuccess($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $master;
    }

    /** @param list<PromptRecipeVersion> $recipes @return array<string,mixed> */
    private function inputSnapshot(ContentTask $task, array $recipes): array
    {
        $claims = EvidenceClaim::query()
            ->with('sourceFile:id,source_root_key,relative_path,sha256,evidence_status,public_permission,is_approved')
            ->where(function ($query) use ($task): void {
                $query->whereNull('content_task_id')
                    ->orWhere('content_task_id', $task->getKey());
            })
            ->whereIn('evidence_status', [
                ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
            ])
            ->where('public_permission', ContentSourceFile::PERMISSION_PUBLISHABLE)
            ->whereNotNull('reviewed_at')
            ->whereHas('sourceFile', fn ($query) => $query->approvedForPublication())
            ->get()
            ->filter(function (EvidenceClaim $claim): bool {
                if (! $claim->isPublishableFact() || ! $claim->hasCompleteQualificationPayload()) {
                    return false;
                }
                if ($claim->claim_type !== EvidenceClaim::TYPE_QUALIFICATION) {
                    return true;
                }

                return hash_equals(
                    (string) ($claim->sourceFile?->sha256 ?? ''),
                    (string) data_get($claim->structured_payload, 'file_sha256', ''),
                );
            })
            ->map(fn (EvidenceClaim $claim): array => [
                'claim_id' => $claim->claim_id,
                'claim_type' => $claim->claim_type,
                'subject' => $claim->subject,
                'predicate' => $claim->predicate,
                'value' => $claim->claim_value,
                'unit' => $claim->unit,
                'scope' => $claim->scope,
                'evidence_status' => $claim->evidence_status,
                'public_permission' => $claim->public_permission,
                'source_ids' => array_values(array_filter([(string) $claim->source_id])),
                'qualification' => $claim->claim_type === EvidenceClaim::TYPE_QUALIFICATION ? (array) $claim->structured_payload : null,
                'source_file' => $claim->sourceFile ? [
                    'relative_path' => $claim->sourceFile->relative_path,
                    'sha256' => $claim->sourceFile->sha256,
                    'evidence_status' => $claim->sourceFile->evidence_status,
                    'public_permission' => $claim->sourceFile->public_permission,
                ] : null,
            ])->values()->all();

        return [
            'task' => [
                'id' => $task->getKey(),
                'product_key' => $task->product_key,
                'title' => $task->title,
                'audience' => $task->audience,
                'intent' => $task->intent,
                'page_role' => $task->page_role,
                'primary_keyword' => $task->primary_keyword,
                'secondary_keywords' => (array) $task->secondary_keywords,
                'target_channels' => (array) $task->target_channels,
            ],
            'approved_claims' => $claims,
            'prompt_versions' => collect($recipes)->map(fn (PromptRecipeVersion $recipe): string => $recipe->recipe_key.'@'.$recipe->version)->values()->all(),
            'hard_boundaries' => [
                'competitor_materials_are_structure_only',
                'no_unverified_qualifications_or_parameters',
                'no_fixed_price_stock_lead_time_ranking_or_market_share',
                'block_when_evidence_is_missing',
            ],
        ];
    }

    /** @return array{string,string,string} */
    private function resolveRuntime(AiModel $model): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        $modelId = trim((string) ($model->model_id ?? ''));
        if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
            throw new RuntimeException('AI 模型 URL、密钥或模型标识不完整。');
        }
        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
        $provider = OpenAiRuntimeProvider::registerProvider('hengjia_content', $driver, $providerUrl, $apiKey);

        return [$provider, $providerUrl, $modelId];
    }

    private function failRun(ContentGenerationRun $run, ContentTask $task, string $code, string $message): void
    {
        DB::transaction(function () use ($run, $task, $code, $message): void {
            ContentGenerationRun::query()
                ->whereKey($run->getKey())
                ->where('status', ContentGenerationRun::STATUS_RUNNING)
                ->update([
                    'status' => ContentGenerationRun::STATUS_FAILED,
                    'error_code' => $code,
                    'error_message' => mb_substr($message, 0, 1000),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            ContentTask::query()
                ->whereKey($task->getKey())
                ->where('status', ContentTask::STATUS_GENERATING)
                ->update(['status' => ContentTask::STATUS_READY, 'updated_at' => now()]);
        });
    }

    /** @param array<string,mixed> $input */
    private function hash(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
