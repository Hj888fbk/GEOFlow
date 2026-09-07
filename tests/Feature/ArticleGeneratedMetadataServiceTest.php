<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleGeneratedMetadataService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleGeneratedMetadataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_prompts_generate_keywords_and_description_while_preserving_focus_keyword(): void
    {
        Http::fakeSequence()
            ->push($this->completion('恒佳，恒佳橡胶软接头，可曲挠橡胶接头'))
            ->push($this->completion('描述：恒佳依据产品资料说明橡胶软接头的作用、选型条件与使用边界，具体参数以项目和订单书面确认为准。'));

        $model = AiModel::query()->create([
            'name' => 'Metadata Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'metadata-test-model',
            'model_type' => 'chat',
            'api_url' => 'https://metadata.test',
            'daily_limit' => 10,
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Metadata task',
            'auto_keywords' => 1,
            'auto_description' => 1,
        ]);
        Prompt::query()->create([
            'name' => 'Keyword prompt',
            'type' => 'keyword',
            'content' => '标题：{{title}} 主关键词：{{keyword}} 正文：{{content}}',
        ]);
        Prompt::query()->create([
            'name' => 'Description prompt',
            'type' => 'description',
            'content' => '请根据 {{title}}、{{keyword}} 和 {{content}} 生成描述。',
        ]);

        $result = app(ArticleGeneratedMetadataService::class)->generate(
            $task,
            $model,
            '橡胶软接头能解决什么问题',
            '橡胶软接头作用',
            '恒佳产品资料用于说明选型条件。',
            '回退描述',
        );

        $this->assertSame('橡胶软接头作用，恒佳，恒佳橡胶软接头，可曲挠橡胶接头', $result['keywords']);
        $this->assertSame(
            '恒佳依据产品资料说明橡胶软接头的作用、选型条件与使用边界，具体参数以项目和订单书面确认为准。',
            $result['meta_description'],
        );
        $this->assertSame('generated', $result['keyword_status']);
        $this->assertSame('generated', $result['description_status']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $decoded = json_decode($request->body(), true);
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($body)
                && str_contains($body, '橡胶软接头作用')
                && str_contains($body, '恒佳产品资料用于说明选型条件');
        });
    }

    /** @return array<string,mixed> */
    private function completion(string $content): array
    {
        return [
            'model' => 'metadata-test-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }
}
