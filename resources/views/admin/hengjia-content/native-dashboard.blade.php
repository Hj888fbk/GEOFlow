@extends('admin.layouts.app')

@php
    $stats = $dashboard['stats'] ?? [];
    $tasks = $dashboard['tasks'] ?? collect();
    $gaps = $dashboard['evidence_gaps'] ?? [];
    $runs = $dashboard['recent_runs'] ?? collect();
    $pipeline = $dashboard['pipeline'] ?? [];
    $assets = $dashboard['assets'] ?? [];
    $legacy = $dashboard['legacy_read_only'] ?? [];
    $currentAdmin = auth('admin')->user();
    $accountRoute = $currentAdmin?->isSuperAdmin()
        ? route('admin.manual-publications.settings.index')
        : route('admin.manual-publications.index');
    $entryCards = [
        ['title' => '生产任务', 'description' => '选择标题库、最多五个知识库、提示词、作者、图库、模型和分发渠道。', 'route' => route('admin.tasks.index'), 'icon' => 'workflow', 'tone' => 'green'],
        ['title' => '知识与证据', 'description' => '维护企业事实、产品参数、资质、适用边界、来源和知识版本。', 'route' => route('admin.knowledge-bases.index'), 'icon' => 'library-big', 'tone' => 'blue'],
        ['title' => '中文提示词向导', 'description' => '通过采购问题编排原生 Prompt；候选需显式启用。', 'route' => route('admin.ai-prompts'), 'icon' => 'message-square-text', 'tone' => 'violet'],
        ['title' => '文章与审核', 'description' => '查看正式 Article、风险扫描、AI 质检、人工审核和多渠道内容。', 'route' => route('admin.articles.index'), 'icon' => 'file-check-2', 'tone' => 'amber'],
        ['title' => '分发与回读', 'description' => '处理人工发布、浏览器辅助、发布回执和结果状态。', 'route' => route('admin.manual-publications.index'), 'icon' => 'send', 'tone' => 'rose'],
        ['title' => '账号与人物口径', 'description' => '使用 GEOFlow 原账号设置，不在驾驶舱保存第二份账号或密码。', 'route' => $accountRoute, 'icon' => 'badge-check', 'tone' => 'slate'],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0" data-hengjia-native-dashboard>
        @include('admin.hengjia-content._workspace-header', [
            'workspace' => 'today',
            'title' => '恒佳原生内容驾驶舱',
            'subtitle' => '这里仅汇总 GEOFlow 原生 Task、KnowledgeBase、Prompt、Article、质检和分发状态。具体操作回到原功能，不再维护第二套内容项目。',
        ])

        <div @class([
            'mb-6 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm leading-6',
            'border-amber-200 bg-amber-50 text-amber-900' => ! $nativeFlowEnabled,
            'border-green-200 bg-green-50 text-green-900' => $nativeFlowEnabled,
        ])>
            <i data-lucide="{{ $nativeFlowEnabled ? 'flask-conical' : 'shield-pause' }}" class="mt-0.5 h-5 w-5 shrink-0"></i>
            <div>
                <p class="font-semibold">原生内容增强：{{ $nativeFlowEnabled ? '影子测试已开启' : '默认关闭' }}</p>
                <p class="mt-0.5">
                    @if ($nativeFlowEnabled)
                        仅 Task ID：{{ $nativeFlowTaskIds === [] ? '尚未配置白名单' : implode('、', $nativeFlowTaskIds) }} 可注入文章结构、作者身份和标签选图；其他旧任务行为不变。
                    @else
                        当前不会改变任何 Worker 生成行为。启用时还必须配置一个测试 Task ID，且本轮不自动发布。
                    @endif
                </p>
            </div>
        </div>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6" aria-label="内容运行概览">
            @foreach ([
                ['label' => '运行任务', 'value' => $stats['active_tasks'] ?? 0, 'icon' => 'play-circle'],
                ['label' => '暂停任务', 'value' => $stats['paused_tasks'] ?? 0, 'icon' => 'pause-circle'],
                ['label' => '待审文章', 'value' => $stats['pending_review_articles'] ?? 0, 'icon' => 'file-clock'],
                ['label' => '资料缺口', 'value' => $stats['evidence_gaps'] ?? 0, 'icon' => 'shield-question'],
                ['label' => '发布待处理', 'value' => $stats['manual_attention'] ?? 0, 'icon' => 'send-horizontal'],
                ['label' => '生产提示词', 'value' => $stats['production_prompts'] ?? 0, 'icon' => 'message-square-text'],
            ] as $item)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs font-semibold text-gray-500">{{ $item['label'] }}</span>
                        <i data-lucide="{{ $item['icon'] }}" class="h-4 w-4 text-gray-400"></i>
                    </div>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-gray-950">{{ number_format((int) $item['value']) }}</p>
                </div>
            @endforeach
        </section>

        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-950">从原生功能继续工作</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">每份任务、知识、提示词、文章和发布回执都只有一个正式来源。</p>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($entryCards as $card)
                    <a href="{{ $card['route'] }}" class="group rounded-xl border border-gray-200 bg-gray-50 p-4 transition hover:border-blue-300 hover:bg-blue-50/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-blue-700 shadow-sm ring-1 ring-gray-200">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-gray-950">{{ $card['title'] }}</h3>
                                    <i data-lucide="arrow-up-right" class="h-4 w-4 text-gray-400 transition group-hover:text-blue-600"></i>
                                </div>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ $card['description'] }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(20rem,0.85fr)]">
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="font-semibold text-gray-950">最近生产任务</h2>
                        <p class="mt-1 text-sm text-gray-600">直接读取原 tasks 表及其标题库、提示词、作者和图库。</p>
                    </div>
                    <a href="{{ route('admin.tasks.create') }}" class="inline-flex min-h-10 shrink-0 items-center rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700">新建任务</a>
                </div>
                @if ($tasks->isEmpty())
                    <div class="px-6 py-10 text-center text-sm text-gray-500">还没有原生任务，请从任务管理创建。</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500">
                                <tr><th class="px-5 py-3">任务</th><th class="px-5 py-3">原生资产</th><th class="px-5 py-3">进度</th><th class="px-5 py-3">状态</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($tasks as $task)
                                    <tr>
                                        <td class="px-5 py-4 align-top">
                                            <a href="{{ route('admin.tasks.edit', ['taskId' => $task->id]) }}" class="font-semibold text-blue-700 hover:text-blue-900">{{ $task->name }}</a>
                                            @if (is_array($task->content_brief) && $task->content_brief !== [])
                                                <div class="mt-1 text-xs text-gray-500">{{ data_get($task->content_brief, 'product_key', '已配置文章结构') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 align-top text-xs leading-5 text-gray-600">
                                            <div>标题：{{ $task->titleLibrary?->name ?: '未配置' }}</div>
                                            <div>提示词：{{ $task->prompt?->name ?: '未配置' }}</div>
                                            <div>作者/图库：{{ $task->author?->name ?: '默认' }} / {{ $task->imageLibrary?->name ?: '未配置' }}</div>
                                        </td>
                                        <td class="px-5 py-4 align-top text-gray-700">文章 {{ $task->articles_count }} · 运行 {{ $task->task_runs_count }}</td>
                                        <td class="px-5 py-4 align-top">
                                            <span @class([
                                                'inline-flex rounded-full px-2 py-1 text-xs font-semibold',
                                                'bg-green-100 text-green-800' => $task->status === 'active',
                                                'bg-gray-100 text-gray-700' => $task->status !== 'active',
                                            ])>{{ $task->status === 'active' ? '运行中' : '已暂停' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-gray-950">资料与图片缺口</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">这里只显示阻断，不把缺失内容补成事实。</p>
                    </div>
                    <a href="{{ route('admin.knowledge-bases.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">知识库</a>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($gaps as $gap)
                        <a href="{{ route('admin.tasks.edit', ['taskId' => $gap['task_id']]) }}" class="block rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 hover:border-amber-300">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-amber-950">{{ $gap['task_name'] }}</p>
                                <span class="shrink-0 text-[11px] text-amber-700">{{ $gap['reason_code'] }}</span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-amber-800">{{ $gap['message'] }}</p>
                        </a>
                    @empty
                        <div class="rounded-lg bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">当前没有已记录的资料阻断。</div>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-semibold text-gray-950">原生流程状态</h2>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ([
                        '任务' => $pipeline['tasks'] ?? 0,
                        '正式文章' => $pipeline['generated_articles'] ?? 0,
                        '待人工审核' => $pipeline['pending_review'] ?? 0,
                        '已审核' => $pipeline['approved'] ?? 0,
                        '官网已发布' => $pipeline['published'] ?? 0,
                        '远端已回读' => $pipeline['remote_synced'] ?? 0,
                    ] as $label => $value)
                        <div class="rounded-lg bg-gray-50 px-3 py-3"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-xl font-semibold text-gray-950">{{ number_format((int) $value) }}</p></div>
                    @endforeach
                </div>
                <p class="mt-4 text-xs leading-5 text-gray-500">发布、抓取、收录、排名、AI 提及和询盘是不同状态；本驾驶舱不会把后四项从“发布成功”推断出来。</p>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="font-semibold text-gray-950">资产覆盖</h2>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['标题库', $assets['title_libraries'] ?? 0, route('admin.title-libraries.index')],
                        ['知识库', $assets['knowledge_bases'] ?? 0, route('admin.knowledge-bases.index')],
                        ['生产提示词', $assets['content_prompts'] ?? 0, route('admin.ai-prompts')],
                        ['作者', $assets['authors'] ?? 0, route('admin.authors.index')],
                        ['图库', $assets['image_libraries'] ?? 0, route('admin.image-libraries.index')],
                        ['图片', $assets['images'] ?? 0, route('admin.image-libraries.index')],
                    ] as [$label, $value, $url])
                        <a href="{{ $url }}" class="rounded-lg border border-gray-200 px-3 py-3 hover:border-blue-300 hover:bg-blue-50/40"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-xl font-semibold text-gray-950">{{ number_format((int) $value) }}</p></a>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-5 py-4 sm:px-6">
                <h2 class="font-semibold text-gray-950">最近运行记录</h2>
                <p class="mt-1 text-sm text-gray-600">TaskRun.meta 保存标题、知识片段、提示词哈希、文章结构、作者和图片选择快照。</p>
            </div>
            @if ($runs->isEmpty())
                <div class="px-6 py-8 text-center text-sm text-gray-500">暂无运行记录。</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500"><tr><th class="px-5 py-3">任务</th><th class="px-5 py-3">状态</th><th class="px-5 py-3">动作/原因</th><th class="px-5 py-3">时间</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($runs as $run)
                                @php($runMeta = is_array($run->meta) ? $run->meta : [])
                                <tr>
                                    <td class="px-5 py-3"><a href="{{ route('admin.tasks.edit', ['taskId' => $run->task_id]) }}" class="font-medium text-blue-700">{{ $run->task?->name ?: '任务 #'.$run->task_id }}</a></td>
                                    <td class="px-5 py-3 text-gray-700">{{ $run->status }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $runMeta['reason_code'] ?? $runMeta['action'] ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-500">{{ optional($run->finished_at ?? $run->created_at)?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if (array_sum(array_map('intval', $legacy)) > 0)
            <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-5 py-4 text-sm leading-6 text-gray-700">
                <p class="font-semibold text-gray-900">兼容数据只读保留</p>
                <p class="mt-1">旧候选对象尚有：ContentTask {{ $legacy['content_tasks'] ?? 0 }}、ContentMaster {{ $legacy['content_masters'] ?? 0 }}、PromptRecipeVersion {{ $legacy['prompt_recipe_versions'] ?? 0 }}、ChannelVariant {{ $legacy['channel_variants'] ?? 0 }}。它们不会再从恒佳驾驶舱写入，待验证稳定后另行清理。</p>
            </div>
        @endif
    </div>
@endsection
