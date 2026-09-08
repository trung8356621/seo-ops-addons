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

    public function test_free_only_mixed_failures_do_not_map_to_quota_exhausted(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 2,
            routingAttempts: [
                [
                    'result' => 'skipped',
                    'model' => 'anthropic/claude',
                    'is_free' => false,
                    'skip_reason' => 'connection_paid_locked',
                ],
                [
                    'result' => 'failed',
                    'model' => 'nvidia/nemotron:free',
                    'is_free' => true,
                    'failure_class' => 'transient_provider',
                    'http_status' => 503,
                ],
                [
                    'result' => 'failed',
                    'model' => 'google/gemma:free',
                    'is_free' => true,
                    'failure_class' => 'rate_limited',
                    'http_status' => 429,
                ],
            ],
            diagnostics: [
                'skip_counts' => ['connection_paid_locked' => 1],
                'fail_counts' => [
                    'transient_provider' => 1,
                    'rate_limited' => 1,
                ],
            ],
        );

        $user = $exception->userMessage();
        self::assertSame(AiRoutesExhaustedException::CLASSIFICATION, $exception->context['classification'] ?? null);
        self::assertStringContainsString('model miễn phí', mb_strtolower($user));
        self::assertStringNotContainsString('hạn mức', $user);
        self::assertStringNotContainsString('kết nối dự phòng', mb_strtolower($user));
        self::assertStringNotContainsString('AI_ROUTES_EXHAUSTED', $user);
    }

    public function test_explicit_quota_failure_maps_to_quota_message(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'model' => 'anthropic/claude',
                    'is_free' => false,
                    'failure_class' => 'insufficient_budget_for_request',
                    'http_status' => 402,
                ],
            ],
            diagnostics: [
                'fail_counts' => ['insufficient_budget_for_request' => 1],
            ],
        );

        $user = $exception->userMessage();
        self::assertStringContainsString('hạn mức', $user);
        self::assertStringNotContainsString('kết nối dự phòng', mb_strtolower($user));
    }

    public function test_429_does_not_map_to_quota_exhausted(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [
                [
                    'result' => 'skipped',
                    'model' => 'anthropic/claude',
                    'is_free' => false,
                    'skip_reason' => 'connection_paid_locked',
                ],
                [
                    'result' => 'failed',
                    'model' => 'nvidia/nemotron:free',
                    'is_free' => true,
                    'failure_class' => 'rate_limited',
                    'http_status' => 429,
                ],
            ],
            diagnostics: [
                'skip_counts' => ['connection_paid_locked' => 1],
                'fail_counts' => ['rate_limited' => 1],
            ],
        );

        $user = $exception->userMessage();
        self::assertStringNotContainsString('hạn mức', $user);
        self::assertStringContainsString('model miễn phí', mb_strtolower($user));
    }

    public function test_paid_plus_free_failures_do_not_use_free_only_copy(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 2,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'model' => 'anthropic/claude',
                    'is_free' => false,
                    'failure_class' => 'transient_provider',
                ],
                [
                    'result' => 'failed',
                    'model' => 'nvidia/nemotron:free',
                    'is_free' => true,
                    'failure_class' => 'transient_provider',
                ],
            ],
            diagnostics: [
                'retryable' => true,
                'fail_counts' => ['transient_provider' => 2],
            ],
        );

        $user = $exception->userMessage();
        self::assertStringContainsString('tạm thời', mb_strtolower($user));
        self::assertStringNotContainsString('hạn mức', $user);
    }

    public function test_ai_routes_exhausted_remains_terminal_aggregate_classification(): void
    {
        $exception = new AiRoutesExhaustedException(
            attemptCount: 1,
            routingAttempts: [
                [
                    'result' => 'failed',
                    'model' => 'free/a',
                    'is_free' => true,
                    'failure_class' => 'provider_empty_output',
                ],
            ],
            diagnostics: [
                'fail_counts' => ['provider_empty_output' => 1],
                'free_only_policy' => true,
            ],
        );

        self::assertSame(AiRoutesExhaustedException::CLASSIFICATION, $exception->context['classification'] ?? null);
        self::assertStringContainsString('AI_ROUTES_EXHAUSTED', $exception->getMessage());
        self::assertStringNotContainsString('AI_ROUTES_EXHAUSTED', $exception->userMessage());
    }
}
