<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SelfMediaAiArchitectureTest extends TestCase
{
    public function test_self_media_ai_generation_uses_the_governed_gateway_and_persists_inside_its_callback(): void
    {
        $generator = (string) file_get_contents($this->path('app/Services/SelfMedia/AiSelfMediaContentGenerator.php'));
        $batchGeneration = (string) file_get_contents($this->path('app/Services/SelfMedia/SelfMediaBatchGenerationService.php'));

        self::assertStringContainsString('WorkerAiModelInvocationGateway', $generator);
        self::assertStringContainsString('invocationGateway->generate(', $generator);
        self::assertStringContainsString('$persistVariant([', $generator);
        self::assertStringNotContainsString('ArticleContentGenerationService', $generator);
        self::assertStringNotContainsString('->aiModel', $generator);

        self::assertStringContainsString('generator->generateAndPersist(', $batchGeneration);
        self::assertStringContainsString('persistVariant($batch, $persona, $platform, $variant, $invocation)', $batchGeneration);
        self::assertStringContainsString('invocationGateway->assertReceiptCurrent(', $batchGeneration);
        self::assertStringContainsString('execution_lease_token', $batchGeneration);

        $schedule = (string) file_get_contents($this->path('routes/console.php'));
        self::assertStringContainsString("Schedule::command('geoflow:recover-self-media-batches')", $schedule);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
