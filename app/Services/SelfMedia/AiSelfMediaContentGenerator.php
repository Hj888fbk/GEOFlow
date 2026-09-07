<?php

namespace App\Services\SelfMedia;

use App\Contracts\SelfMediaContentGenerator;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationBatch;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\WorkerAiModelInvocationGateway;
use Closure;
use DomainException;
use Illuminate\Support\Arr;

final readonly class AiSelfMediaContentGenerator implements SelfMediaContentGenerator
{
    public function __construct(
        private AiExecutionContextFactory $contextFactory,
        private WorkerAiModelInvocationGateway $invocationGateway,
    ) {}

    public function generateAndPersist(
        ManualPublicationBatch $batch,
        string $platform,
        Closure $persistVariant,
    ): mixed {
        $context = $this->contextFactory->fromSelfMediaBatch($batch, $platform);
        $modelId = (int) ($batch->requested_ai_model_id ?? 0);
        if ($modelId <= 0) {
            throw new DomainException('源任务没有可用于自媒体改写的 AI 模型。');
        }

        return $this->invocationGateway->generate(
            $context,
            $modelId,
            $this->prompt($batch, $platform),
            function (array $invocation) use ($batch, $context, $persistVariant): mixed {
                $response = $invocation['response'];
                $text = trim((string) ($response->text ?? ''));
                $decoded = $this->decodeJson($text);
                $source = (array) $batch->source_snapshot;
                $body = trim((string) Arr::get($decoded, 'body_plain', Arr::get($decoded, 'body_markdown', '')));
                if ($body === '') {
                    throw new DomainException('平台改写返回了空正文。');
                }

                return $persistVariant([
                    'title' => trim((string) Arr::get($decoded, 'title', Arr::get($source, 'title', ''))),
                    'summary' => trim((string) Arr::get($decoded, 'summary', Arr::get($source, 'excerpt', ''))),
                    'body_plain' => $body,
                    'body_markdown' => trim((string) Arr::get($decoded, 'body_markdown', $body)),
                    'body_html' => trim((string) Arr::get($decoded, 'body_html', '')) ?: null,
                    'tags' => array_values(array_slice(array_unique(array_filter(array_map(
                        static fn ($tag): string => trim((string) $tag),
                        (array) Arr::get($decoded, 'tags', []),
                    ))), 0, 10)),
                ], [
                    'context' => $context,
                    'receipt' => $invocation['receipt'],
                ]);
            },
            [
                'call_key' => 'platform-'.$platform,
                'operation' => 'self_media.generate',
                'business_source' => 'self_media_platform_generation',
            ],
        );
    }

    private function prompt(ManualPublicationBatch $batch, string $platform): string
    {
        $format = $platform === ManualPublicationAccount::PLATFORM_WEIBO
            ? '普通图文微博，使用短内容结构，不复制长文全文'
            : '适合该平台的完整图文文章';

        return implode("\n", [
            '你是 GEOFlow 的按需自媒体改写器。平台由确定性规则选定，你不得改变平台。',
            '目标平台：'.$platform,
            '内容形式：'.$format,
            '只能改写，不得添加母稿和事实约束包之外的资质、参数、库存、交期、客户或工程案例。',
            '必须保留原意，平台标题、摘要、正文和标签要分别适配。',
            '仅输出 JSON：{"title":"","summary":"","body_plain":"","body_markdown":"","body_html":null,"tags":[]}',
            '官网母稿快照：'.json_encode($batch->source_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '事实约束包：'.json_encode($batch->fact_constraints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $text): array
    {
        $text = preg_replace('/\A```(?:json)?\s*|\s*```\z/u', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new DomainException('平台改写结果不是有效 JSON。');
        }

        return $decoded;
    }
}
