<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;

final class AiRoutesExhaustedException extends PromptRunException
{
    public const CLASSIFICATION = 'AI_ROUTES_EXHAUSTED';

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        int $attemptCount,
        array $routingAttempts = [],
        ?\Throwable $previous = null,
        ?int $promptResultId = null,
        array $diagnostics = [],
    ) {
        $classified = (new AiRoutesExhaustionClassifier())->classify($routingAttempts, $attemptCount);
        $retryable = (bool) ($diagnostics['retryable'] ?? $classified['retryable']);
        $kind = (string) ($diagnostics['exhaustion_kind'] ?? $classified['exhaustion_kind']);

        $context = [
            'classification' => self::CLASSIFICATION,
            'user_message' => self::userFacingMessage($attemptCount, $routingAttempts, $retryable),
            'technical_details' => self::CLASSIFICATION,
            'retryable' => $retryable,
            'temporary' => $retryable,
            'attempt_count' => $attemptCount,
            'routing_attempts' => $routingAttempts,
            'exhaustion_kind' => $kind,
            'health_skip_count' => $classified['health_skip_count'],
            'hard_skip_count' => $classified['hard_skip_count'],
            'transient_failure_count' => $classified['transient_failure_count'],
            'hard_failure_count' => $classified['hard_failure_count'],
        ];
        foreach ($diagnostics as $key => $value) {
            if (! is_string($key) || $key === '' || array_key_exists($key, $context)) {
                continue;
            }
            $context[$key] = $value;
        }
        if ($promptResultId !== null && $promptResultId > 0) {
            $context['prompt_result_id'] = $promptResultId;
        }

        parent::__construct(
            message: self::CLASSIFICATION.': '.self::technicalAttemptPhrase($attemptCount),
            code: 0,
            previous: $previous,
            context: $context,
        );
    }

    public function isRetryable(): bool
    {
        return (bool) ($this->context['retryable'] ?? false);
    }

    public function exhaustionKind(): string
    {
        return (string) ($this->context['exhaustion_kind'] ?? AiRoutesExhaustionClassifier::KIND_HARD);
    }

    public function retryAfterSeconds(): ?int
    {
        $value = $this->context['retry_after_seconds'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public static function technicalAttemptPhrase(int $attemptCount): string
    {
        if ($attemptCount <= 0) {
            return 'No eligible AI route was attempted';
        }

        return $attemptCount.' AI attempt(s) failed';
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    public static function userFacingMessage(int $attemptCount, array $routingAttempts = [], bool $retryable = false): string
    {
        $parts = [];
        foreach ($routingAttempts as $attempt) {
            if (! is_array($attempt) || (string) ($attempt['result'] ?? '') !== 'failed') {
                continue;
            }
            $model = trim((string) ($attempt['model'] ?? ''));
            $failure = trim((string) ($attempt['failure_class'] ?? ''));
            if ($model === '') {
                continue;
            }
            $label = match ($failure) {
                'transient_provider' => $model.' timed out / transient failure',
                'rate_limited' => $model.' rate limited',
                'insufficient_budget_for_request' => $model.' insufficient budget',
                'credential_invalid' => $model.' invalid credentials',
                default => $failure !== '' ? $model.' ('.$failure.')' : $model.' failed',
            };
            $parts[] = $label;
        }

        if ($attemptCount <= 0) {
            return $retryable
                ? 'AI routes temporarily unavailable (cooldown/health). Will retry shortly.'
                : 'AI routes exhausted: no eligible AI route was attempted. Check AI Center routing/keys, then retry.';
        }

        if ($parts !== []) {
            $suffix = $retryable ? ' Temporary — will retry.' : '';

            return 'AI routes exhausted after '.$attemptCount.' attempt(s): '.implode('; ', $parts).'.'.$suffix;
        }

        return 'AI routes exhausted after '.$attemptCount.' attempt(s). Check AI Center routing/keys, then retry.';
    }
}
