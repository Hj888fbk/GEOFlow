@php
    $isCreate = ($mode ?? 'create') === 'create';
    $promptModel = $isCreate ? null : $prompt;
    $normalizeScalar = static function (mixed $value, string $fallback = ''): string {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : $fallback;
    };
    $promptNameFallback = $isCreate ? '' : (string) $prompt->name;
    $promptContentFallback = $isCreate ? '' : (string) $prompt->content;
    $promptName = $normalizeScalar(old('name', $promptNameFallback), $promptNameFallback);
    $promptContent = $normalizeScalar(old('content', $promptContentFallback), $promptContentFallback);
    $promptTypeFallback = $isCreate ? 'content' : (string) $prompt->type;
    $promptType = $normalizeScalar(old('type', $promptTypeFallback), $promptTypeFallback);
    $isSystemPrompt = ! $isCreate && filled($prompt->system_key ?? null);
    $storedBuilderConfig = is_array($builderConfig ?? null) ? $builderConfig : [];
    $oldBuilderConfig = old('builder_config');
    $formBuilderConfig = is_array($oldBuilderConfig) ? $oldBuilderConfig : $storedBuilderConfig;
    $guidedFallback = $isCreate ? 'guided' : (($promptModel?->isGuidedContentPrompt() ?? false) ? 'guided' : 'traditional');
    $builderMode = $normalizeScalar(old('builder_mode', $guidedFallback), $guidedFallback);
    $useGuidedBuilder = $promptType === 'content' && $builderMode === 'guided' && ! $isSystemPrompt;
    $builderStatus = ! $isCreate && ($promptModel?->isGuidedContentPrompt() ?? false) ? $promptModel->builderStatus() : null;
    $builderValue = static function (string $key, string $fallback = '') use ($formBuilderConfig, $normalizeScalar): string {
        $value = $formBuilderConfig[$key] ?? $fallback;
        if (is_array($value)) {
            return implode("\n", array_values(array_filter(array_map(
                static fn (mixed $item): string => is_string($item) || is_numeric($item) ? (string) $item : '',
                $value,
            ))));
        }

        return $normalizeScalar($value, $fallback);
    };
@endphp

<div
    class="px-5 py-6 sm:px-6"
    data-prompt-builder
    data-suggestion-url="{{ route('admin.ai-prompts.builder-suggest') }}"
    data-initial-mode="{{ $builderMode }}"
>
    <div class="space-y-6">
        @if ($isSystemPrompt)
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                系统内置质检方案 · v{{ $prompt->system_version ?: '1.0.0' }}。该方案保持只读，可复制内容创建自定义方案。
            </div>
        @elseif ($builderStatus === \App\Models\Prompt::BUILDER_STATUS_ACTIVE)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                这是已启用的向导提示词。保存修改时会创建一个新的候选版本，原版本和已绑定任务不会被覆盖。
            </div>
        @elseif ($builderStatus === \App\Models\Prompt::BUILDER_STATUS_CANDIDATE)
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">
                当前为候选版本，尚不会出现在任务的生产提示词下拉框中。请检查后在提示词列表显式启用。
            </div>
        @endif

        @if ($isCreate)
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="prompt_type" class="block text-sm font-semibold text-gray-800">提示词类型</label>
                    <select name="type" id="prompt_type" data-prompt-type class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="content" @selected($promptType === 'content')>正文生成提示词</option>
                        <option value="quality_check" @selected($promptType === 'quality_check')>AI 质检方案</option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-gray-500">AI 质检方案继续使用传统模板编辑；中文向导只用于正文生成。</p>
                </div>
                <div data-builder-mode-field>
                    <label for="builder_mode" class="block text-sm font-semibold text-gray-800">创建方式</label>
                    <select name="builder_mode" id="builder_mode" data-builder-mode class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="guided" @selected($builderMode === 'guided')>中文提示词向导（推荐）</option>
                        <option value="traditional" @selected($builderMode === 'traditional')>传统模板编辑</option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-gray-500">向导先保存为候选，不会自动替换正在使用的提示词。</p>
                </div>
            </div>
        @else
            <input type="hidden" name="type" value="{{ $promptType }}">
            @if (! $isSystemPrompt && $promptType === 'content')
                <div class="max-w-xl">
                    <label for="builder_mode" class="block text-sm font-semibold text-gray-800">编辑方式</label>
                    <select name="builder_mode" id="builder_mode" data-builder-mode class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="guided" @selected($builderMode === 'guided')>中文提示词向导</option>
                        <option value="traditional" @selected($builderMode === 'traditional')>传统模板编辑</option>
                    </select>
                    @if (! ($promptModel?->isGuidedContentPrompt() ?? false))
                        <p class="mt-2 text-xs leading-5 text-gray-500">把传统提示词改为向导时，会另建候选版本，不覆盖当前提示词。</p>
                    @endif
                </div>
            @else
                <input type="hidden" name="builder_mode" value="traditional">
            @endif
        @endif

        <div>
            <label for="prompt_name" class="block text-sm font-semibold text-gray-800">{{ __('admin.ai_prompts.field_name') }}</label>
            <input
                type="text"
                name="name"
                id="prompt_name"
                value="{{ $promptName }}"
                required
                autofocus
                @readonly($isSystemPrompt)
                @error('name') aria-invalid="true" aria-describedby="prompt_name_error" @enderror
                class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500"
                placeholder="例如：橡胶软接头采购选型 · 证据型正文"
            >
            @error('name')
                <p id="prompt_name_error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <section data-builder-guided @class(['space-y-6', 'hidden' => ! $useGuidedBuilder]) aria-labelledby="guided-builder-heading">
            <div class="rounded-xl border border-green-200 bg-green-50 p-5">
                <h2 id="guided-builder-heading" class="text-base font-semibold text-green-950">用采购问题组织提示词</h2>
                <p class="mt-1 text-sm leading-6 text-green-800">填写业务语言即可。系统会编排稳定结构，AI 只建议遗漏问题；模板变量和编译源码不会在这里展示。</p>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <label for="builder_procurement_problem" class="block text-sm font-semibold text-gray-800">想解决的采购问题</label>
                    <textarea data-builder-input name="builder_config[procurement_problem]" id="builder_procurement_problem" rows="3" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="例如：采购人员如何根据介质、口径、压力、温度和连接方式确认橡胶软接头选型？">{{ $builderValue('procurement_problem') }}</textarea>
                    @error('builder_config.procurement_problem')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_product_line" class="block text-sm font-semibold text-gray-800">产品或产品线</label>
                    <input data-builder-input type="text" name="builder_config[product_line]" id="builder_product_line" value="{{ $builderValue('product_line') }}" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="橡胶软接头 / KXT、JGD 等已确认型号">
                    @error('builder_config.product_line')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_target_user" class="block text-sm font-semibold text-gray-800">目标用户</label>
                    <input data-builder-input type="text" name="builder_config[target_user]" id="builder_target_user" value="{{ $builderValue('target_user') }}" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="工业采购、设计院、设备工程师、维修负责人">
                    @error('builder_config.target_user')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_audience_profile" class="block text-sm font-semibold text-gray-800">用户画像</label>
                    <textarea data-builder-input name="builder_config[audience_profile]" id="builder_audience_profile" rows="3" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="他们掌握哪些信息、最担心什么、需要向谁交付选型依据？">{{ $builderValue('audience_profile') }}</textarea>
                    @error('builder_config.audience_profile')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_decision_stage" class="block text-sm font-semibold text-gray-800">采购决策阶段</label>
                    <select data-builder-input name="builder_config[decision_stage]" id="builder_decision_stage" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="">请选择</option>
                        @foreach ($builderDecisionStages as $value => $label)
                            <option value="{{ $value }}" @selected($builderValue('decision_stage') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('builder_config.decision_stage')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="builder_buyer_questions" class="block text-sm font-semibold text-gray-800">用户常见提问</label>
                    <textarea data-builder-input name="builder_config[buyer_questions]" id="builder_buyer_questions" rows="5" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="每行一个问题，例如：需要提供哪些工况参数？">{{ $builderValue('buyer_questions') }}</textarea>
                    @error('builder_config.buyer_questions')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="builder_procurement_direction" class="block text-sm font-semibold text-gray-800">采购关注方向</label>
                    <textarea data-builder-input name="builder_config[procurement_direction]" id="builder_procurement_direction" rows="3" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="适用条件、禁用条件、参数边界、检测证据、非标、报价输入等">{{ $builderValue('procurement_direction') }}</textarea>
                    @error('builder_config.procurement_direction')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_page_role" class="block text-sm font-semibold text-gray-800">页面职责</label>
                    <select data-builder-input name="builder_config[page_role]" id="builder_page_role" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="">请选择</option>
                        @foreach ($builderPageRoles as $value => $label)
                            <option value="{{ $value }}" @selected($builderValue('page_role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('builder_config.page_role')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_tone" class="block text-sm font-semibold text-gray-800">表达语气</label>
                    <select data-builder-input name="builder_config[tone]" id="builder_tone" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500">
                        <option value="">请选择</option>
                        @foreach ($builderTones as $value => $label)
                            <option value="{{ $value }}" @selected($builderValue('tone') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('builder_config.tone')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="builder_desired_action" class="block text-sm font-semibold text-gray-800">希望读者完成的动作</label>
                    <input data-builder-input type="text" name="builder_config[desired_action]" id="builder_desired_action" value="{{ $builderValue('desired_action') }}" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="整理介质、DN、PN、温度、法兰标准和安装空间后提交询价">
                    @error('builder_config.desired_action')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_allowed_evidence" class="block text-sm font-semibold text-gray-800">允许使用的证据</label>
                    <textarea data-builder-input name="builder_config[allowed_evidence]" id="builder_allowed_evidence" rows="4" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="已审核知识库、恒佳内部确认且允许公开的资料、公共记录等">{{ $builderValue('allowed_evidence') }}</textarea>
                    @error('builder_config.allowed_evidence')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="builder_forbidden_claims" class="block text-sm font-semibold text-gray-800">禁止出现的主张</label>
                    <textarea data-builder-input name="builder_config[forbidden_claims]" id="builder_forbidden_claims" rows="4" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="未核验资质、近似型号参数、固定价格、库存、交期、排名、市场份额等">{{ $builderValue('forbidden_claims') }}</textarea>
                    @error('builder_config.forbidden_claims')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="builder_target_channels" class="block text-sm font-semibold text-gray-800">目标发布渠道</label>
                    <input data-builder-input type="text" name="builder_config[target_channels]" id="builder_target_channels" value="{{ $builderValue('target_channels') }}" @disabled(! $useGuidedBuilder) class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="官网、百度爱采购、1688、搜狐号、百家号">
                    @error('builder_config.target_channels')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5" data-builder-ai-panel>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                    <div class="min-w-0 flex-1">
                        <label for="builder_ai_model" class="block text-sm font-semibold text-gray-800">让 AI 检查遗漏（可选）</label>
                        <select id="builder_ai_model" data-builder-ai-model class="mt-2 block min-h-11 w-full rounded-lg border-gray-300 px-3 py-2.5 text-sm shadow-sm focus:border-green-500 focus:ring-green-500">
                            <option value="">请选择一个已启用模型</option>
                            @foreach ($builderAiModels as $model)
                                <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->model_id }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" data-builder-suggest class="inline-flex min-h-11 items-center justify-center rounded-lg border border-blue-300 bg-white px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">
                        检查遗漏问题
                    </button>
                </div>
                <p class="mt-3 text-xs leading-5 text-gray-600">AI 不生成文章，也不会补写公司事实、资质或参数；建议不会自动保存。</p>
                <div class="mt-4 hidden" data-builder-suggestion-result>
                    <label for="builder_suggestion_text" class="block text-sm font-semibold text-gray-800">候选建议</label>
                    <textarea id="builder_suggestion_text" data-builder-suggestion-text readonly rows="7" class="mt-2 block w-full rounded-lg border-gray-300 bg-white px-3 py-3 text-sm leading-6 text-gray-800"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" data-builder-adopt class="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">采用建议</button>
                        <span class="text-xs text-gray-600" data-builder-notice></span>
                    </div>
                </div>
                <p class="mt-3 hidden text-sm text-red-600" data-builder-error role="alert"></p>
            </div>

            <div>
                <label for="builder_accepted_ai_notes" class="block text-sm font-semibold text-gray-800">已采纳的 AI 建议</label>
                <textarea data-builder-input data-builder-accepted-notes name="builder_config[accepted_ai_notes]" id="builder_accepted_ai_notes" rows="5" @disabled(! $useGuidedBuilder) class="mt-2 block w-full rounded-lg border-gray-300 px-3 py-3 text-sm leading-6 shadow-sm focus:border-green-500 focus:ring-green-500" placeholder="只有点击“采用建议”后才会写入；你也可以删改。">{{ $builderValue('accepted_ai_notes') }}</textarea>
            </div>
        </section>

        <section data-builder-traditional @class(['grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]', 'hidden' => $useGuidedBuilder])>
            <div>
                <label for="prompt_content" class="block text-sm font-semibold text-gray-800">{{ __('admin.ai_prompts.field_content') }}</label>
                <textarea
                    name="content"
                    id="prompt_content"
                    rows="17"
                    @readonly($isSystemPrompt)
                    @disabled($useGuidedBuilder)
                    @error('content') aria-invalid="true" aria-describedby="prompt_content_error" @enderror
                    class="mt-2 block min-h-96 w-full resize-y rounded-lg border-gray-300 px-3 py-3 text-sm leading-7 text-gray-900 shadow-sm focus:border-green-500 focus:ring-green-500"
                    placeholder="{{ __('admin.ai_prompts.placeholder_content') }}"
                >{{ $promptContent }}</textarea>
                @error('content')
                    <p id="prompt_content_error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <aside class="h-fit min-w-0 rounded-xl bg-blue-50 p-5 text-blue-900 ring-1 ring-inset ring-blue-200" aria-labelledby="prompt-variables-title">
                <h2 id="prompt-variables-title" class="text-sm font-semibold">传统模板变量</h2>
                <div class="mt-4 space-y-3 break-words text-sm leading-6">
                    @if ($promptType === 'quality_check')
                        <div><code>&#123;&#123;article_title&#125;&#125;</code>、<code>&#123;&#123;article_content&#125;&#125;</code>、<code>&#123;&#123;knowledge&#125;&#125;</code></div>
                    @else
                        <div>{!! __('admin.ai_prompts.variable_title_label') !!}</div>
                        <div>{!! __('admin.ai_prompts.variable_keyword_label') !!}</div>
                        <div>{!! __('admin.ai_prompts.variable_knowledge_label') !!}</div>
                    @endif
                </div>
                <p class="mt-5 border-t border-blue-200 pt-4 text-xs leading-6 text-blue-800">传统编辑适合已有模板维护；新建恒佳正文提示词优先使用中文向导。</p>
            </aside>
        </section>
    </div>
</div>
