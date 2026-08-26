<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use PHPUnit\Framework\TestCase;

final class AiRoutesExhaustedExceptionTest extends TestCase
{
    public function test_user_message_lists_failed_models_without_boolean_false(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 2,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'model' => 'deepseek-reasoner',
                    'failure_class' => 'transient_provider',
                ],
                [
                    'result' => 'failed',
                    'model' => 'nvidia/nemotron-free',
                    'failure_class' => 'transient_provider',
                ],
            ],
            promptResultId: 1094,
        );

        $user = $exception->userMessage();
        self::assertStringContainsString('AI routes exhausted', $user);
        self::assertStringContainsString('deepseek-reasoner', $user);
        self::assertStringContainsString('timed out / transient failure', $user);
        self::assertStringNotContainsString('false', strtolower($user));
        self::assertSame(1094, (int) ($exception->context['prompt_result_id'] ?? 0));
    }
}
