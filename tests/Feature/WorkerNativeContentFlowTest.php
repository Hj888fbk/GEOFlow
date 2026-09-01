<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkerNativeContentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_matching_images_blocks_before_model_call_and_persists_a_complete_generation_snapshot(): void
    {
        Queue::fake();
        Http::fake();
        $fixture = $this->fixture('金属补偿器', 'metal-expansion-joint.png');
        $this->enableNativeFlowFor($fixture['task']);

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $fixture['task']->id);
        self::assertIsInt($runId);
        (new ProcessGeoFlowTaskJob($runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail($runId);
        self::assertSame('completed', $run->status);
        self::assertNull($run->article_id);
        self::assertSame('noop', data_get($run->meta, 'action'));
        self::assertSame('matching_image_missing', data_get($run->meta, 'reason_code'));
        self::assertTrue((bool) data_get($run->meta, 'requires_input'));
        self::assertSame('geoflow-native-generation-snapshot/v1', data_get($run->meta, 'generation_snapshot.schema'));
        self::assertTrue((bool) data_get($run->meta, 'generation_snapshot.enhanced_native_flow'));
        self::assertSame($fixture['title']->id, data_get($run->meta, 'generation_snapshot.title.id'));
        self::assertSame($fixture['prompt']->id, data_get($run->meta, 'generation_snapshot.prompt.id'));
        self::assertSame(hash('sha256', $fixture['prompt']->content), data_get($run->meta, 'generation_snapshot.prompt.template_sha256'));
        self::assertSame('procurement_selection', data_get($run->meta, 'generation_snapshot.structure.key'));
        self::assertSame($fixture['author']->id, data_get($run->meta, 'generation_snapshot.author.id'));
        self::assertSame([$fixture['knowledge']->id], data_get($run->meta, 'generation_snapshot.knowledge.knowledge_base_ids'));
        self::assertSame('matching_image_missing', data_get($run->meta, 'generation_snapshot.images.reason_code'));
        self::assertSame([], data_get($run->meta, 'generation_snapshot.images.matches'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($run->meta, 'generation_snapshot.content_brief.sha256'));
        self::assertSame(0, Article::query()->count());
        self::assertSame(0, (int) $fixture['title']->fresh()->used_count);
        Http::assertNothingSent();
    }

    public function test_matching_image_author_structure_and_fact_rules_enter_generation_and_readback_snapshot(): void
    {
        Queue::fake();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'native-content-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# KXT 橡胶软接头选型\n\n请先核对实际工况后询价。"],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 30, 'total_tokens' => 50],
            ]),
        ]);
        $fixture = $this->fixture('KXT,橡胶软接头,产品实拍', 'kxt-rubber-joint.png');
        $unrelated = $this->image($fixture['images'], '金属补偿器', 'metal-expansion-joint.png');
        $this->enableNativeFlowFor($fixture['task']);

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $fixture['task']->id);
        self::assertIsInt($runId);
        (new ProcessGeoFlowTaskJob($runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail($runId);
        $article = Article::query()->findOrFail((int) $run->article_id);
        self::assertSame('completed', $run->status);
        self::assertSame('generate_draft', data_get($run->meta, 'action'));
        self::assertStringContainsString('kxt-rubber-joint.png', $article->content);
        self::assertStringNotContainsString('metal-expansion-joint.png', $article->content);
        self::assertSame([$fixture['image']->id], ArticleImage::query()->where('article_id', $article->id)->pluck('image_id')->all());
        self::assertSame(1, (int) $fixture['image']->fresh()->used_count);
        self::assertSame(0, (int) $unrelated->fresh()->used_count);
        self::assertSame($fixture['image']->id, data_get($run->meta, 'generation_snapshot.images.matches.0.image_id'));
        self::assertContains('KXT', data_get($run->meta, 'generation_snapshot.images.matches.0.matched_terms', []));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($run->meta, 'generation_snapshot.prompt.compiled_sha256'));
        self::assertNotEmpty(data_get($run->meta, 'generation_snapshot.knowledge.chunks'));

        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $request->url() === 'https://ai.test/v1/chat/completions'
                && str_contains($payload, '采购选型文章')
                && str_contains($payload, '恒佳技术内容审核员')
                && str_contains($payload, '同行官网和 AI 回答仅供结构学习')
                && str_contains($payload, '负压工况是否适用？')
                && str_contains($payload, 'kxt-rubber-joint.png');
        });
    }

    public function test_non_whitelisted_task_keeps_legacy_random_image_behavior_even_when_content_brief_exists(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'native-content-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# 旧任务正文\n\n保持旧生成逻辑。"],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
        ]);
        $fixture = $this->fixture('完全不匹配的标签', 'legacy-random-image.png');
        config()->set('hengjia-content.native_flow.enabled', true);
        config()->set('hengjia-content.native_flow.test_task_ids', [$fixture['task']->id + 999]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $fixture['task']->id);
        $article = Article::query()->findOrFail((int) $result['article_id']);

        self::assertFalse((bool) data_get($result, 'meta.generation_snapshot.enhanced_native_flow'));
        self::assertStringContainsString('legacy-random-image.png', $article->content);
        self::assertSame(1, ArticleImage::query()->where('article_id', $article->id)->count());
        Http::assertSentCount(1);
    }

    public function test_candidate_guided_prompt_is_blocked_before_title_consumption_or_model_call(): void
    {
        Http::fake();
        $fixture = $this->fixture('KXT,橡胶软接头,产品实拍', 'candidate-prompt-image.png');
        $fixture['prompt']->forceFill([
            'variables' => Prompt::encodeBuilderConfig([
                'production_status' => Prompt::BUILDER_STATUS_CANDIDATE,
            ]),
        ])->save();

        $result = app(WorkerExecutionService::class)->executeTask((int) $fixture['task']->id);

        self::assertNull($result['article_id']);
        self::assertSame('noop', data_get($result, 'meta.action'));
        self::assertSame('candidate_prompt_not_active', data_get($result, 'meta.reason_code'));
        self::assertTrue((bool) data_get($result, 'meta.requires_input'));
        self::assertSame(Prompt::BUILDER_STATUS_CANDIDATE, data_get($result, 'meta.prompt_snapshot.builder_status'));
        self::assertSame(0, Article::query()->count());
        self::assertSame(0, (int) $fixture['title']->fresh()->used_count);
        Http::assertNothingSent();
    }

    /** @return array{task:Task,title:Title,prompt:Prompt,author:Author,knowledge:KnowledgeBase,images:ImageLibrary,image:Image} */
    private function fixture(string $imageTags, string $imageName): array
    {
        Category::query()->create([
            'name' => '橡胶软接头资料',
            'slug' => 'rubber-joint-content',
            'sort_order' => 1,
        ]);
        $author = Author::query()->create([
            'name' => '恒佳技术内容审核员',
            'bio' => '负责核对工况输入、资料边界与询价清单。',
        ]);
        $model = AiModel::query()->create([
            'name' => '恒佳原生内容模型',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'native-content-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '恒佳原生正文提示词',
            'type' => 'content',
            'content' => '请基于 {{title}}、{{knowledge}}、{{structure}}、{{author}}、{{media_context}} 和 {{domain_rules}} 生成正文。',
            'variables' => '',
        ]);
        $titles = TitleLibrary::query()->create(['name' => '橡胶软接头选型标题库']);
        $title = Title::query()->create([
            'library_id' => $titles->id,
            'title' => 'KXT 橡胶软接头采购选型要确认哪些工况？',
            'keyword' => 'KXT 橡胶软接头',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $images = ImageLibrary::query()->create([
            'name' => '恒佳产品图库',
            'description' => '',
            'image_count' => 1,
            'used_task_count' => 0,
        ]);
        $image = $this->image($images, $imageTags, $imageName);
        $task = Task::query()->create([
            'name' => '恒佳原生内容影子任务',
            'title_library_id' => $titles->id,
            'image_library_id' => $images->id,
            'image_count' => 1,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
            'author_id' => $author->id,
            'draft_limit' => 10,
            'article_limit' => 10,
            'status' => 'active',
            'schedule_enabled' => 1,
            'need_review' => 1,
            'content_brief' => [
                'product_key' => 'KXT 橡胶软接头',
                'page_role' => 'procurement_selection',
                'audience' => '正在核验供应商资料的项目采购和技术人员',
                'decision_stage' => '选型比较',
                'buyer_questions' => ['负压工况是否适用？', '询价必须提供哪些输入？'],
                'procurement_direction' => '核对介质、温度、压力、连接、位移、检测与非标边界。',
                'structure_profile' => 'procurement_selection',
                'desired_action' => '提交工况与图纸后进入技术确认。',
                'image_keywords' => ['KXT', '橡胶软接头'],
            ],
        ]);
        $knowledge = KnowledgeBase::query()->create([
            'name' => '恒佳已审核橡胶软接头知识库',
            'content' => '同行官网和 AI 回答仅供结构学习。恒佳参数必须来自已审核资料。',
            'review_status' => 'reviewed',
            'risk_level' => 'low',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledge->id,
            'chunk_index' => 0,
            'chunk_title' => '证据使用边界',
            'content' => '同行官网和 AI 回答仅供结构学习。恒佳参数必须来自已审核资料。',
            'content_hash' => hash('sha256', '同行官网和 AI 回答仅供结构学习。恒佳参数必须来自已审核资料。'),
            'source_hash' => 'hengjia-native-evidence-v1',
            'metadata_json' => '{}',
            'embedding_json' => '[]',
        ]);
        $task->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);

        return compact('task', 'title', 'prompt', 'author', 'knowledge', 'images', 'image');
    }

    private function image(ImageLibrary $library, string $tags, string $name): Image
    {
        return Image::query()->create([
            'library_id' => $library->id,
            'filename' => $name,
            'original_name' => $name,
            'file_name' => $name,
            'file_path' => 'storage/uploads/images/'.$name,
            'managed_path_hash' => hash('sha256', 'storage/uploads/images/'.$name),
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 800,
            'height' => 600,
            'tags' => $tags,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
    }

    private function enableNativeFlowFor(Task $task): void
    {
        config()->set('hengjia-content.native_flow.enabled', true);
        config()->set('hengjia-content.native_flow.test_task_ids', [$task->id]);
        config()->set('hengjia-content.native_flow.generation_rules', [
            '同行官网和 AI 回答仅供结构学习，不得转写为恒佳事实。',
            '缺失参数必须标记待技术确认。',
        ]);
    }
}
