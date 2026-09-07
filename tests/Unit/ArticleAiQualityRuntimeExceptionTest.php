<?php

namespace Tests\Unit;

use App\Exceptions\ArticleAiQualityCauseException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Services\GeoFlow\LaravelArticleAiQualityReviewer;
use App\Services\Outbound\OutboundRequestFailedException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ArticleAiQualityRuntimeExceptionTest extends TestCase
{
    public function test_runtime_failures_retain_only_safe_cause_metadata(): void
    {
        $exception = new ArticleAiQualityRuntimeException(
            'provider_error',
            true,
            new RuntimeException('api_key=secret-value https://private.example.test'),
        );

        $this->assertInstanceOf(ArticleAiQualityCauseException::class, $exception->getPrevious());
        $this->assertSame(RuntimeException::class, $exception->getPrevious()?->causeType);
        $this->assertStringNotContainsString('secret-value', $exception->getPrevious()?->getMessage() ?? '');
        $this->assertNull($exception->getPrevious()?->getPrevious());
    }

    public function test_deepseek_insufficient_balance_is_classified_as_quota_exhausted(): void
    {
        $response = new Response(new PsrResponse(
            402,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => [
                    'code' => 'invalid_request_error',
                    'message' => 'Insufficient Balance',
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $outbound = new OutboundRequestFailedException(new RequestException($response));
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            $outbound,
            'https://api.deepseek.com/v1',
            null,
        );

        $this->assertSame('provider_quota_exhausted', $typed->safeCode());
        $this->assertFalse($typed->retryable());
        $this->assertSame(402, $typed->httpStatus());
        $this->assertSame('invalid_request_error', $typed->providerCode());
    }

    public function test_direct_provider_http_exception_retains_safe_provider_code(): void
    {
        $response = new Response(new PsrResponse(
            402,
            ['Content-Type' => 'application/json'],
            json_encode([
                'error' => [
                    'type' => 'unknown_error',
                    'code' => 'invalid_request_error',
                    'message' => 'Insufficient Balance',
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            new RequestException($response),
            'https://api.deepseek.com/v1',
            null,
        );

        $this->assertSame('provider_quota_exhausted', $typed->safeCode());
        $this->assertSame(402, $typed->httpStatus());
        $this->assertSame('invalid_request_error', $typed->providerCode());
    }

    public function test_truncated_json_output_is_classified_for_sampled_fallback(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'decodeJson');

        try {
            $method->invoke(
                app(LaravelArticleAiQualityReviewer::class),
                '{"summary":"unfinished","issues":[',
            );
            $this->fail('Expected truncated JSON to be rejected.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('model_output_truncated', $exception->safeCode());
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_provider_output_limit_is_classified_for_sampled_fallback(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'typedProviderException');

        /** @var ArticleAiQualityRuntimeException $typed */
        $typed = $method->invoke(
            app(LaravelArticleAiQualityReviewer::class),
            new RuntimeException('Maximum output token limit reached.'),
            'https://api.example.test/v1',
            null,
        );

        $this->assertSame('output_budget_exhausted', $typed->safeCode());
        $this->assertTrue($typed->retryable());
    }

    public function test_provider_result_normalization_repairs_known_json_fallback_type_drift(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'normalizeProviderResult');
        $result = $method->invoke(app(LaravelArticleAiQualityReviewer::class), [
            'summary' => '发现一项待核验内容。',
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'partial',
            'issues' => [[
                'code' => 'unsupported_claim',
                'severity' => 'low',
                'field' => '正文',
                'quote' => '待核验原文',
                'paragraph_index' => '2',
                'heading' => null,
                'fact_candidate_id' => null,
                'article_claim' => '待核验主张',
                'evidence_value' => null,
                'knowledge_refs' => [['K1'], 'K2', [['K3']]],
                'legal_refs' => [['CN-AD-LAW-08']],
                'reason' => '缺少依据',
                'suggestion' => '补充依据',
            ]],
            'uncertainties' => [[
                'claim' => '待核验主张',
                'materiality' => 'low',
                'reason' => '缺少依据',
                'needed_evidence' => null,
            ]],
        ], false);

        $this->assertSame('content', $result['issues'][0]['field']);
        $this->assertSame('', $result['issues'][0]['fact_candidate_id']);
        $this->assertSame('', $result['issues'][0]['evidence_value']);
        $this->assertSame(['K1', 'K2', 'K3'], $result['issues'][0]['knowledge_refs']);
        $this->assertSame(['CN-AD-LAW-08'], $result['issues'][0]['legal_refs']);
        $this->assertSame(2, $result['issues'][0]['paragraph_index']);
        $this->assertSame('', $result['uncertainties'][0]['needed_evidence']);
    }

    public function test_provider_result_normalization_does_not_guess_associative_reference_objects(): void
    {
        $method = new ReflectionMethod(LaravelArticleAiQualityReviewer::class, 'normalizeProviderResult');
        $references = [['id' => 'K1']];
        $result = $method->invoke(app(LaravelArticleAiQualityReviewer::class), [
            'issues' => [[
                'knowledge_refs' => $references,
                'legal_refs' => [],
            ]],
        ], false);

        $this->assertSame($references, $result['issues'][0]['knowledge_refs']);
    }
}
