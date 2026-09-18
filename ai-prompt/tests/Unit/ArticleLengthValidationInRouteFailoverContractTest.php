<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: article length OUTPUT_TRUNCATED must throw inside AiModelRouter
 * attempt (PromptRunnerService::executePlannedRouteAttempt), not only after
 * PromptHookRuntimeEngine output pipeline — otherwise failover never runs.
 */
final class ArticleLengthValidationInRouteFailoverContractTest extends TestCase
{
    public function test_prompt_runner_asserts_length_inside_planned_route_attempt(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );

        self::assertStringContainsString('assertArticleRouteOutputEligibleForFailover', $src);
        self::assertStringContainsString('ArticleGenerationLengthValidator', $src);

        $attemptPos = strpos($src, 'private function executePlannedRouteAttempt');
        self::assertNotFalse($attemptPos);

        $failoverPos = strpos($src, 'assertArticleRouteOutputEligibleForFailover', $attemptPos);
        self::assertNotFalse($failoverPos);

        $returnPos = strpos($src, "return [\$output, \$usage];", $attemptPos);
        self::assertNotFalse($returnPos);
        self::assertLessThan(
            $returnPos,
            $failoverPos,
            'Length assert must run before planned-route attempt returns success',
        );

        $engineSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/PromptHooks/Runtime/PromptHookRuntimeEngine.php',
        );
        self::assertStringContainsString('outputPipeline->process', $engineSrc);
        self::assertStringContainsString('persistFailedLengthValidation', $engineSrc);
    }
}
