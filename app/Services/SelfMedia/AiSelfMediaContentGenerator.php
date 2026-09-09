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
    private const IMAGE_PLACEHOLDER_PATTERN = '/【图片\d+】/u';

    public function __construct(
        private AiExecutionContextFactory $contextFactory,
        private WorkerAiModelInvocationGateway $invocationGateway,
        private PortableArticleDocumentService $portableDocuments,
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
            function (array $invocation) use ($batch, $context, $persistVariant, $platform): mixed {
                $response = $invocation['response'];
                $text = trim((string) ($response->text ?? ''));
                if ($text === '') {
                    // 推理型模型（如 deepseek-v4-flash）可能把 max_tokens 全部耗在思考链上，
                    // 正文 content 返回空；报成 JSON 解析错误会误导排障。
                    $reasoning = (int) data_get($response->usage ?? [], 'reasoning_tokens', 0);
                    throw new DomainException(
                        '模型返回空内容（reasoning_tokens='.$reasoning.'，疑似推理耗尽输出预算，请调高该模型 max_tokens）。'
                    );
                }
                $decoded = $this->decodeJson($text);
                $source = (array) $batch->source_snapshot;
                // 只让模型产出 body_markdown；body_plain 服务端从 markdown 确定性派生，
                // 避免同一正文在 JSON 里输出两遍导致 max_tokens 撞顶截断。
                $title = trim((string) Arr::get($decoded, 'title', Arr::get($source, 'title', '')));
                $bodyMarkdown = trim((string) Arr::get($decoded, 'body_markdown', ''));
                $document = $this->portableDocuments->build(
                    $title,
                    $bodyMarkdown,
                    array_values((array) $batch->media_manifest),
                    $platform,
                );
                $body = trim((string) ($document['plain_text'] ?? ''));

                return $persistVariant([
                    'title' => $title,
                    'summary' => trim((string) Arr::get($decoded, 'summary', Arr::get($source, 'excerpt', ''))),
                    'body_plain' => $body,
                    'body_markdown' => (string) $document['markdown'],
                    'body_html' => (string) $document['html'],
                    'document_schema_version' => PortableArticleDocumentService::SCHEMA_VERSION,
                    'portable_document' => $document,
                    'render_fingerprint' => (array) $document['render_fingerprint'],
                    'content_type' => $this->contentType($platform),
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

        $lines = [
            '你是 GEOFlow 的按需自媒体改写器。平台由确定性规则选定，你不得改变平台。',
            '目标平台：'.$platform,
            '内容形式：'.$format,
            '只能改写，不得添加母稿和事实约束包之外的资质、参数、库存、交期、客户或工程案例。',
            '必须保留原意，平台标题、摘要、正文和标签要分别适配。',
        ];

        $bodyImages = array_values(array_filter(
            (array) $batch->media_manifest,
            static fn ($media): bool => is_array($media) && ($media['role'] ?? null) === 'body',
        ));
        if ($bodyImages !== []) {
            $lines[] = '正文配图清单（共'.count($bodyImages).'张，按位置顺序编号 1 到 '.count($bodyImages).'）：';
            foreach ($bodyImages as $index => $image) {
                $lines[] = '{{media:'.(string) ($image['media_key'] ?? '').'}} '.trim((string) ($image['name'] ?? ''));
            }
            $lines[] = 'body_markdown 必须逐字保留每个 {{media:...}} 图片节点，单独成行，数量和顺序必须一致；不得遗漏、重复或更改 media_key。';
        }

        $lines[] = '仅输出 JSON：{"title":"","summary":"","body_markdown":"","tags":[]}（不要输出 body_plain 和 body_html，正文只写 markdown 版本）';
        $lines[] = '官网母稿快照：'.json_encode($batch->source_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '事实约束包：'.json_encode($batch->fact_constraints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return implode("\n", $lines);
    }

    /**
     * body_plain 不允许携带【图片N】占位符：先整行移除，再兜底清掉行内残留。
     */
    private function stripImagePlaceholders(string $text): string
    {
        $text = preg_replace('/^[ \t]*【图片\d+】[ \t]*\R?/mu', '', $text) ?? $text;
        $text = preg_replace(self::IMAGE_PLACEHOLDER_PATTERN, '', $text) ?? $text;

        return trim($text);
    }

    private function contentType(string $platform): string
    {
        return match ($platform) {
            ManualPublicationAccount::PLATFORM_DOUYIN => 'douyin_article',
            ManualPublicationAccount::PLATFORM_WEIBO => 'weibo_post',
            default => 'article',
        };
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $text): array
    {
        $text = preg_replace('/\A```(?:json)?\s*|\s*```\z/u', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            // 模型偶尔在 JSON 外包裹说明文字：截取首个 { 到末个 } 再解析一次。
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new DomainException('平台改写结果不是有效 JSON。');
        }

        return $decoded;
    }
}
