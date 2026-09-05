<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ByxxApiPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $config = $channel->resolvedByxxConfig();
        $response = $this->post($channel, '/api_getSpace.php', [
            'member_id' => $this->memberId($config),
            'apikey' => $this->secret($channel),
        ], '百业网空间检查');
        $json = $this->successfulJson($response, '百业网空间检查');

        return [
            'ok' => true,
            'channel_type' => DistributionChannel::TYPE_BYXX_API,
            'code' => 200,
            'total' => max(0, (int) ($json['total'] ?? 0)),
            'used' => max(0, (int) ($json['used'] ?? 0)),
            'free' => max(0, (int) ($json['free'] ?? 0)),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $config = $channel->resolvedByxxConfig();
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $title = trim((string) ($article['title'] ?? ''));
        $text = $this->plainText($article);
        if ($title === '' || $text === '') {
            throw new RuntimeException('百业网文章标题和正文不能为空。');
        }

        $form = [
            'member_id' => $this->memberId($config),
            'apikey' => $this->secret($channel),
            'title' => $title,
            'text' => $text,
            'pinpai_id' => (int) $config['byxx_brand_id'],
            'price' => (float) $config['byxx_price'],
            'service' => (int) $config['byxx_service'],
        ];
        if ($config['byxx_site_id'] !== '') {
            $form['site_id'] = $config['byxx_site_id'];
        }
        if ($config['byxx_class_id'] !== null) {
            $form['class_id'] = (int) $config['byxx_class_id'];
        }

        $response = $this->post($channel, '/api_goods.php', $form, '百业网文章发布');
        $json = $this->successfulJson($response, '百业网文章发布');
        $remoteId = trim((string) ($json['id'] ?? ''));
        if ($remoteId === '' || preg_match('/^\d+$/', $remoteId) !== 1) {
            throw new RuntimeException('百业网文章发布返回缺少有效信息ID。');
        }

        return [
            'remote_id' => $remoteId,
            'remote_url' => '',
            'remote_meta' => [
                'byxx' => [
                    'information_id' => $remoteId,
                    'shop_id' => (string) $config['byxx_shop_id'],
                    'shop_url' => $this->shopUrl((string) $config['byxx_shop_id']),
                    'api_code' => 200,
                    'remote_lookup_required' => true,
                    'supports_remote_update' => false,
                    'supports_remote_delete' => false,
                ],
            ],
        ];
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        throw new RuntimeException('百业网API不支持远程更新，请在百业网后台修改后人工回读。');
    }

    public function delete(ArticleDistribution $distribution): array
    {
        throw new RuntimeException('百业网API不支持远程删除，请在百业网后台处理后人工回读。');
    }

    public function syncSiteSettings(DistributionChannel $channel, ?string $idempotencyKey = null, ?array $settings = null): array
    {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'byxx_api_does_not_support_site_settings',
        ];
    }

    /** @param array<string,mixed> $data */
    private function post(DistributionChannel $channel, string $path, array $data, string $operation): Response
    {
        $config = $channel->resolvedByxxConfig();
        $request = Http::timeout((int) $config['byxx_timeout_seconds'])
            ->connectTimeout(5)
            ->acceptJson()
            ->asForm()
            ->withUserAgent('GEOFlow/2.0 Baiye Publisher');
        $response = $this->safeHttp->post(
            $request,
            rtrim((string) $channel->endpoint_url, '/').$path,
            $data,
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
        );
        $this->markSecretUsed($channel);

        if ($response->failed()) {
            throw new DistributionHttpException($operation.'失败：HTTP '.$response->status(), $response->status());
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function successfulJson(Response $response, string $operation): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException($operation.'失败：百业网返回了无效JSON。');
        }

        $code = (int) ($json['code'] ?? 0);
        if ($code !== 200) {
            $reason = match ($code) {
                401 => '请求参数不完整',
                402 => '认证失败，登录密码不正确',
                403 => '店铺不存在或已删除',
                404 => '信息空间不足',
                405 => '当前店铺套餐无API权限',
                406 => '店铺已被禁用',
                407 => '店铺分类设置错误',
                408 => '发布分类不属于店铺大类',
                409 => '信息标题重复',
                410 => '发布加红信息时广告币不足',
                411 => '百业网数据库异常',
                412 => '百业网返回数据完整性异常',
                413 => '百业网计费系统异常',
                default => '未知业务错误',
            };

            throw new DistributionHttpException($operation.'失败：百业网业务码'.$code.'（'.$reason.'）', $code);
        }

        return $json;
    }

    /** @param array<string,mixed> $config */
    private function memberId(array $config): string
    {
        $memberId = trim((string) ($config['byxx_member_id'] ?? ''));
        if ($memberId === '' || preg_match('/^\d+$/', $memberId) !== 1) {
            throw new RuntimeException('百业网渠道缺少有效 member_id。');
        }

        return $memberId;
    }

    private function secret(DistributionChannel $channel): string
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret) {
            throw new RuntimeException('百业网渠道缺少有效API密码。');
        }

        $plain = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($plain === '') {
            throw new RuntimeException('百业网API密码解密失败。');
        }

        return $plain;
    }

    /** @param array<string,mixed> $article */
    private function plainText(array $article): string
    {
        $html = trim((string) ($article['content_html'] ?? ''));
        if ($html !== '') {
            $html = preg_replace('/<br\s*\/?\s*>/iu', "\n", $html) ?? $html;
            $html = preg_replace('/<\/(?:p|h[1-6]|li|tr|table|blockquote)>/iu', "\n", $html) ?? $html;
            $html = preg_replace('/<\/t[dh]>/iu', "\t", $html) ?? $html;
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = (string) ($article['content'] ?? '');
            $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;
            $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;
        }

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function markSecretUsed(DistributionChannel $channel): void
    {
        if ($channel->activeSecret) {
            $channel->activeSecret->forceFill(['last_used_at' => now()])->save();
        }
    }

    private function shopUrl(string $shopId): string
    {
        return preg_match('/^\d+$/', $shopId) === 1 ? 'https://www.byxx.com/'.$shopId : '';
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少百业网渠道。');
        }

        return $distribution->channel;
    }
}
