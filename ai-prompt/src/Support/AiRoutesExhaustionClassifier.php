<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Classifies AI_ROUTES_EXHAUSTED for retry/defer vs terminal fail.
 */
final class AiRoutesExhaustionClassifier
{
    public const KIND_TEMPORARY_HEALTH = 'temporary_health_exhaustion';

    public const KIND_TRANSIENT_PROVIDER = 'transient_provider_exhaustion';

    public const KIND_HARD = 'hard_route_exhaustion';

    public const KIND_MIXED = 'mixed_exhaustion';

    /** @var list<string> */
    private const HEALTH_SKIPS = [
        'model_cooldown',
        'connection_cooldown',
    ];

    /** @var list<string> */
    private const HARD_SKIPS = [
        'model_unavailable',
        'connection_locked',
        'connection_paid_locked',
        'connection_suppressed',
        'paid_lane_suppressed',
    ];

    /** @var list<string> */
    private const TRANSIENT_FAILURES = [
        AiFailureClass::RateLimited->value,
        AiFailureClass::TransientProvider->value,
        AiFailureClass::ProviderEmptyOutput->value,
        AiFailureClass::ProviderInvalidOutput->value,
        AiFailureClass::ProviderRefusal->value,
    ];

    /** @var list<string> */
    private const HARD_FAILURES = [
        AiFailureClass::ModelNotFound->value,
        AiFailureClass::CredentialInvalid->value,
        AiFailureClass::BillingExhausted->value,
        AiFailureClass::AccountRestricted->value,
        AiFailureClass::RequestInvalid->value,
        AiFailureClass::SystemError->value,
        AiFailureClass::OutputQuality->value,
        AiFailureClass::ContextLimitExceeded->value,
    ];

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     * @return array{
     *   exhaustion_kind: string,
     *   retryable: bool,
     *   temporary: bool,
     *   health_skip_count: int,
     *   hard_skip_count: int,
     *   transient_failure_count: int,
     *   hard_failure_count: int,
     *   free_budget_skip_count: int
     * }
     */
    public function classify(array $routingAttempts, int $attemptCount): array
    {
        $healthSkips = 0;
        $hardSkips = 0;
        $freeBudgetSkips = 0;
        $transientFails = 0;
        $hardFails = 0;
        $otherFails = 0;

        foreach ($routingAttempts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $result = (string) ($row['result'] ?? '');
            if ($result === 'skipped') {
                $reason = (string) ($row['skip_reason'] ?? '');
                if (in_array($reason, self::HEALTH_SKIPS, true)) {
                    $healthSkips++;
                } elseif (in_array($reason, self::HARD_SKIPS, true)) {
                    $hardSkips++;
                } elseif ($reason === 'free_attempt_budget_exhausted') {
                    $freeBudgetSkips++;
                } else {
                    $hardSkips++;
                }
                continue;
            }
            if ($result !== 'failed') {
                continue;
            }
            $failure = (string) ($row['failure_class'] ?? '');
            if (in_array($failure, self::TRANSIENT_FAILURES, true)) {
                $transientFails++;
            } elseif (in_array($failure, self::HARD_FAILURES, true)) {
                $hardFails++;
            } else {
                $otherFails++;
            }
        }

        $hasRecoverable = $healthSkips > 0 || $transientFails > 0;
        $hasHard = $hardSkips > 0 || $hardFails > 0 || $otherFails > 0;

        if ($attemptCount <= 0 && $healthSkips > 0 && $hardSkips === 0 && $transientFails === 0 && $hardFails === 0) {
            $kind = self::KIND_TEMPORARY_HEALTH;
            $retryable = true;
        } elseif ($attemptCount > 0 && $transientFails > 0 && $hardFails === 0 && $otherFails === 0 && $hardSkips === 0) {
            $kind = $healthSkips > 0 ? self::KIND_MIXED : self::KIND_TRANSIENT_PROVIDER;
            $retryable = true;
        } elseif ($hasRecoverable && $hasHard) {
            $kind = self::KIND_MIXED;
            // Recoverable path still exists (cooldown / transient siblings).
            $retryable = true;
        } elseif ($hasRecoverable && ! $hasHard) {
            $kind = $healthSkips > 0 && $transientFails === 0
                ? self::KIND_TEMPORARY_HEALTH
                : self::KIND_TRANSIENT_PROVIDER;
            $retryable = true;
        } else {
            $kind = self::KIND_HARD;
            $retryable = false;
        }

        // Only free-budget skips with no other recoverable path → not temporary health.
        if ($attemptCount <= 0 && $healthSkips === 0 && $hardSkips === 0 && $freeBudgetSkips > 0) {
            $kind = self::KIND_HARD;
            $retryable = false;
        }

        return [
            'exhaustion_kind' => $kind,
            'retryable' => $retryable,
            'temporary' => $retryable,
            'health_skip_count' => $healthSkips,
            'hard_skip_count' => $hardSkips,
            'transient_failure_count' => $transientFails,
            'hard_failure_count' => $hardFails + $otherFails,
            'free_budget_skip_count' => $freeBudgetSkips,
        ];
    }

    public static function isSoftProviderFailureClass(string $failureClass): bool
    {
        return in_array($failureClass, self::TRANSIENT_FAILURES, true)
            || $failureClass === AiFailureClass::InsufficientBudgetForRequest->value;
    }
}
