<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptRunnerProviderAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PromptRunnerRoutingFailureChainTest extends TestCase
{
    public function test_refusal_wrapper_preserves_previous_exception(): void
    {
        $routeFailure = new AiRoutesExhaustedException(
            attemptCount: 0,
            routingAttempts: [['result' => 'skipped', 'skip_reason' => 'model_cooldown']],
            diagnostics: ['retry_after_seconds' => 900],
        );

        $wrapped = new ProviderRefused('blocked', $routeFailure);

        self::assertSame($routeFailure, $wrapped->getPrevious());
    }

    public function test_route_exhaustion_is_not_misclassified_as_refusal(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(PromptRunnerProviderAdapter::class))->getFileName());
        $routeCheck = strpos($source, "str_contains(\$message, 'AI_ROUTES_EXHAUSTED')");
        $refusalCheck = strpos($source, 'if ($this->looksLikeRefusal($message))');

        self::assertNotFalse($routeCheck);
        self::assertNotFalse($refusalCheck);
        self::assertLessThan($refusalCheck, $routeCheck);
        self::assertStringContainsString('throw new ProviderFailed($message, $exception);', $source);
    }
}
