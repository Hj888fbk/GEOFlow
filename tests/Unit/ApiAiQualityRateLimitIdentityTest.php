<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiAiQualityRateLimitIdentityTest extends TestCase
{
    public function test_bearer_tokens_use_distinct_irreversible_fallback_identities(): void
    {
        $limiter = RateLimiter::limiter('api-ai-quality-manual');
        $this->assertNotNull($limiter);

        $firstToken = '15|first-secret-token';
        $secondToken = '16|second-secret-token';
        $firstLimits = $limiter($this->requestFor($firstToken));
        $secondLimits = $limiter($this->requestFor($secondToken));

        $firstKey = (string) $firstLimits[0]->key;
        $secondKey = (string) $secondLimits[0]->key;

        $this->assertStringContainsString(hash('sha256', $firstToken), $firstKey);
        $this->assertStringNotContainsString($firstToken, $firstKey);
        $this->assertStringNotContainsString($secondToken, $secondKey);
        $this->assertNotSame($firstKey, $secondKey);
    }

    private function requestFor(string $token): Request
    {
        $request = Request::create(
            '/api/v1/articles/42/ai-quality/recheck',
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'REMOTE_ADDR' => '127.0.0.1'],
        );
        $route = new Route('POST', 'api/v1/articles/{article}/ai-quality/recheck', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}
