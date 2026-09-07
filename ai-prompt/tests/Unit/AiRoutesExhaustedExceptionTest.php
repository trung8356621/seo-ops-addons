<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use PHPUnit\Framework\TestCase;

final class AiRoutesExhaustedExceptionTest extends TestCase
{
    public function test_user_message_is_actionable_without_technical_codes(): void
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
            diagnostics: [
                'retryable' => true,
                'exhaustion_kind' => 'transient_provider_exhaustion',
            ],
        );

        $user = $exception->userMessage();
        self::assertStringNotContainsString('AI_ROUTES_EXHAUSTED', $user);
        self::assertStringNotContainsString('connection lock', strtolower($user));
        self::assertStringContainsString('tạm thời', mb_strtolower($user));
        self::assertSame(1094, (int) ($exception->context['prompt_result_id'] ?? 0));
        self::assertStringContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
    }

    public function test_credential_failure_user_message_does_not_confuse_with_credits(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'model' => 'or/a',
                    'failure_class' => 'credential_invalid',
                ],
            ],
            diagnostics: [
                'fail_counts' => ['credential_invalid' => 1],
                'last_failure_class' => 'credential_invalid',
            ],
        );

        $user = $exception->userMessage();
        self::assertStringContainsString('API key', $user);
        self::assertStringNotContainsString('hạn mức', $user);
    }
}
