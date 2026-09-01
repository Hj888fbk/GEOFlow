<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleAiQualityPromptRenderer;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Services\GeoFlow\ContentStructureProfileCatalog;
use App\Services\GeoFlow\PromptBuilderService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

/**
 * 正文提示词配置控制器。
 *
 * 对齐 bak/admin/ai-prompts.php：
 * 1. 仅管理 type=content 的提示词；
 * 2. 支持创建、编辑、删除；
 * 3. 展示任务引用数量，删除时做引用保护。
 */
class AiPromptController extends Controller
{
    public function __construct(
        private readonly ArticleAiQualityPromptRenderer $qualityPromptRenderer,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
        private readonly PromptBuilderService $promptBuilder,
        private readonly ArticleContentGenerationService $contentGeneration,
    ) {}

    /**
     * 正文提示词列表页。
     */
    public function index(): View
    {
        return view('admin.ai-prompts.index', [
            'pageTitle' => __('admin.ai_prompts.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'prompts' => $this->loadPrompts(),
        ]);
    }

    /**
     * 正文提示词创建页。
     */
    public function create(): View
    {
        return view('admin.ai-prompts.create', [
            'pageTitle' => __('admin.ai_prompts.modal_create'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            ...$this->builderViewData(),
        ]);
    }

    /**
     * 正文提示词编辑页。
     */
    public function edit(int $promptId): View
    {
        $prompt = Prompt::query()
            ->select(['id', 'name', 'type', 'content', 'variables', 'system_key', 'system_version'])
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        return view('admin.ai-prompts.edit', [
            'pageTitle' => __('admin.ai_prompts.modal_edit'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'prompt' => $prompt,
            ...$this->builderViewData($prompt),
        ]);
    }

    /**
     * 创建正文提示词。
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->input('builder_mode') === 'guided' && $request->input('type', 'content') === 'content') {
            $prompt = $this->createGuidedCandidate($request);

            return redirect()
                ->route('admin.ai-prompts.edit', ['promptId' => $prompt->id])
                ->with('message', '候选提示词已保存。完成检查后请显式启用，候选不会自动进入生产任务。');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:content,quality_check'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.required'),
            'content.required' => __('admin.ai_prompts.error.required'),
        ]);

        $type = (string) ($payload['type'] ?? 'content');
        $content = trim((string) $payload['content']);
        if ($type === 'quality_check') {
            $this->validateQualityTemplate($content);
        }

        Prompt::query()->create([
            'name' => trim((string) $payload['name']),
            'type' => $type,
            'content' => $content,
            'variables' => $type === 'quality_check'
                ? json_encode($this->qualityVariables(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : '',
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.create_success'));
    }

    /**
     * 更新正文提示词。
     */
    public function update(Request $request, int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        if (filled($prompt->system_key)) {
            return back()->withErrors('系统内置质检方案为只读，请复制后再修改。');
        }

        if ($request->input('builder_mode') === 'guided') {
            if ($prompt->type !== 'content') {
                return back()->withErrors('AI 质检方案不能切换为正文提示词向导。');
            }

            $config = $this->validatedBuilderConfig($request, $prompt);
            $attributes = [
                'name' => trim((string) $request->input('name')),
                'type' => 'content',
                'content' => $this->promptBuilder->compile($config),
                'variables' => Prompt::encodeBuilderConfig($config),
            ];
            if (! $prompt->isGuidedContentPrompt() || $prompt->builderStatus() === Prompt::BUILDER_STATUS_ACTIVE) {
                $candidate = Prompt::query()->create($attributes);

                return redirect()
                    ->route('admin.ai-prompts.edit', ['promptId' => $candidate->id])
                    ->with('message', '已创建新的候选版本；原提示词和正在使用它的任务保持不变。');
            }

            $prompt->update($attributes);

            return redirect()->route('admin.ai-prompts')->with('message', '候选提示词已更新，尚未启用。');
        }

        if ($prompt->isGuidedContentPrompt()) {
            return back()->withErrors('向导提示词必须通过中文向导修改，不能直接覆盖已编译模板。');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.invalid_fields'),
            'content.required' => __('admin.ai_prompts.error.invalid_fields'),
        ]);

        $content = trim((string) $payload['content']);
        if ($prompt->type === 'quality_check') {
            $this->validateQualityTemplate($content);
        }

        $prompt->update([
            'name' => trim((string) $payload['name']),
            'content' => $content,
        ]);

        if ($prompt->type === 'quality_check') {
            $this->qualityInvalidationService->invalidatePrompt((int) $prompt->id, 'AI 质检方案已更新');
        }

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.update_success'));
    }

    public function copy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();
        $variables = (string) ($prompt->variables ?? '');
        $content = (string) $prompt->content;
        if ($prompt->isGuidedContentPrompt()) {
            $config = $prompt->builderConfig();
            $config['lineage_id'] = $this->promptBuilder->newLineageId();
            $config['production_status'] = Prompt::BUILDER_STATUS_CANDIDATE;
            $variables = Prompt::encodeBuilderConfig($config);
            $content = $this->promptBuilder->compile($config);
        }
        $copy = Prompt::query()->create([
            'name' => mb_substr((string) $prompt->name.'（副本）', 0, 100, 'UTF-8'),
            'type' => (string) $prompt->type,
            'content' => $content,
            'variables' => $variables,
            'system_key' => null,
            'system_version' => null,
        ]);

        return redirect()
            ->route('admin.ai-prompts.edit', ['promptId' => $copy->id])
            ->with('message', '提示词副本已创建，可以直接编辑。');
    }

    /**
     * 显式启用一个向导候选。启用只让它进入任务下拉，不修改任何已有任务绑定。
     */
    public function activate(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()->whereKey($promptId)->where('type', 'content')->firstOrFail();
        if (! $prompt->isGuidedContentPrompt()) {
            return back()->withErrors('传统提示词无需启用，只有向导候选使用此操作。');
        }
        if ($prompt->builderStatus() === Prompt::BUILDER_STATUS_ACTIVE) {
            return back()->with('message', '该提示词已经启用。');
        }

        $config = $prompt->builderConfig();
        $config['production_status'] = Prompt::BUILDER_STATUS_ACTIVE;
        $prompt->update([
            'content' => $this->promptBuilder->compile($config),
            'variables' => Prompt::encodeBuilderConfig($config),
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', '提示词已启用，现在可在原生任务中选择；已有任务未被自动替换。');
    }

    /**
     * AI 只返回遗漏问题候选，不保存、不启用，也不生成企业事实。
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($modelQuery) => $modelQuery
                        ->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat'))),
            ],
            'builder_config' => ['required', 'array'],
        ]);

        try {
            $config = $this->promptBuilder->normalizeConfig((array) $validated['builder_config']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['builder_config' => $exception->getMessage()]);
        }
        $model = AiModel::query()->findOrFail((int) $validated['ai_model_id']);
        $response = $this->contentGeneration->generate($model, $this->promptBuilder->suggestionPrompt($config));
        $suggestions = trim(OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? '')));
        if ($suggestions === '') {
            throw ValidationException::withMessages(['ai_model_id' => '模型未返回可用建议，请稍后重试或更换模型。']);
        }

        return response()->json([
            'suggestions' => mb_substr($suggestions, 0, 4000, 'UTF-8'),
            'notice' => '这些内容只是候选检查项；只有点击“采用建议”并保存候选后才会进入提示词配置。',
        ]);
    }

    /**
     * 删除正文提示词（任务引用保护）。
     */
    public function destroy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', ['content', 'quality_check'])
            ->firstOrFail();

        if (filled($prompt->system_key)) {
            return back()->withErrors('系统内置质检方案不能删除，可以复制为自定义方案。');
        }

        $usageCount = Task::withTrashed()
            ->where($prompt->type === 'quality_check' ? 'ai_quality_prompt_id' : 'prompt_id', $promptId)
            ->count();
        if ($usageCount > 0) {
            return back()->withErrors(__('admin.ai_prompts.error.in_use', ['count' => $usageCount]));
        }

        $prompt->delete();

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.delete_success'));
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   name:string,
     *   content:string,
     *   task_count:int,
     *   created_at:?string
     * }>
     */
    private function loadPrompts(): array
    {
        return Prompt::query()
            ->select(['id', 'name', 'type', 'content', 'variables', 'system_key', 'system_version', 'created_at'])
            ->whereIn('type', ['content', 'quality_check'])
            ->withCount([
                'tasks' => fn ($query) => $query->withTrashed(),
                'qualityTasks' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Prompt $prompt): array {
                $builderConfig = $prompt->builderConfig();

                return [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                    'content' => (string) $prompt->content,
                    'type' => (string) $prompt->type,
                    'task_count' => $prompt->type === 'quality_check'
                        ? (int) ($prompt->quality_tasks_count ?? 0)
                        : (int) ($prompt->tasks_count ?? 0),
                    'system_managed' => filled($prompt->system_key),
                    'system_version' => (string) ($prompt->system_version ?? ''),
                    'guided' => $prompt->isGuidedContentPrompt(),
                    'builder_status' => $prompt->builderStatus(),
                    'builder_summary' => $builderConfig !== [] ? $this->promptBuilder->summary($builderConfig) : '',
                    'created_at' => optional($prompt->created_at)?->format('Y-m-d H:i'),
                ];
            })
            ->all();
    }

    private function validateQualityTemplate(string $content): void
    {
        $variables = array_fill_keys($this->qualityVariables(), 'sample');
        try {
            $this->qualityPromptRenderer->render($content, $variables);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'content' => '质检提示词包含未知变量或模板格式无效：'.$exception->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    private function qualityVariables(): array
    {
        return [
            'article_title', 'article_excerpt', 'article_outline', 'article_content', 'keywords',
            'meta_description', 'fact_candidates', 'knowledge', 'advertising_rules', 'inspection_date',
            'publication_context', 'segment_index', 'segment_count', 'segment_start_offset',
        ];
    }

    /** @return array<string,mixed> */
    private function builderViewData(?Prompt $prompt = null): array
    {
        return [
            'builderConfig' => $prompt?->builderConfig() ?? [],
            'builderPageRoles' => app(ContentStructureProfileCatalog::class)->pageRoles(),
            'builderDecisionStages' => $this->promptBuilder->decisionStages(),
            'builderTones' => $this->promptBuilder->tones(),
            'builderAiModels' => AiModel::query()
                ->select(['id', 'name', 'model_id'])
                ->where('status', 'active')
                ->where(function ($query): void {
                    $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
                })
                ->orderBy('failover_priority')
                ->orderBy('name')
                ->get(),
        ];
    }

    private function createGuidedCandidate(Request $request): Prompt
    {
        $config = $this->validatedBuilderConfig($request);

        return Prompt::query()->create([
            'name' => trim((string) $request->input('name')),
            'type' => 'content',
            'content' => $this->promptBuilder->compile($config),
            'variables' => Prompt::encodeBuilderConfig($config),
        ]);
    }

    /** @return array<string,mixed> */
    private function validatedBuilderConfig(Request $request, ?Prompt $sourcePrompt = null): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'builder_config' => ['required', 'array'],
        ], ['name.required' => __('admin.ai_prompts.error.required')]);

        try {
            $config = $this->promptBuilder->normalizeConfig((array) $request->input('builder_config', []));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['builder_config' => $exception->getMessage()]);
        }

        $requiredFields = [
            'procurement_problem' => '请填写要解决的采购问题。',
            'product_line' => '请填写产品或产品线。',
            'target_user' => '请填写目标用户。',
            'audience_profile' => '请填写用户画像。',
            'decision_stage' => '请选择采购决策阶段。',
            'buyer_questions' => '请至少填写一个用户常见提问。',
            'procurement_direction' => '请填写采购关注方向。',
            'page_role' => '请选择页面职责。',
            'desired_action' => '请填写希望读者完成的动作。',
            'tone' => '请选择表达语气。',
            'allowed_evidence' => '请填写允许使用的证据。',
            'forbidden_claims' => '请填写禁止出现的主张。',
            'target_channels' => '请填写目标发布渠道。',
        ];
        $errors = [];
        foreach ($requiredFields as $field => $message) {
            $value = $config[$field] ?? null;
            if ((is_array($value) && $value === []) || (! is_array($value) && trim((string) $value) === '')) {
                $errors['builder_config.'.$field] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $sourceConfig = $sourcePrompt?->builderConfig() ?? [];
        $config['lineage_id'] = (string) ($sourceConfig['lineage_id'] ?? $config['lineage_id'] ?? $this->promptBuilder->newLineageId());
        $config['production_status'] = Prompt::BUILDER_STATUS_CANDIDATE;

        return $this->promptBuilder->normalizeConfig($config);
    }
}
