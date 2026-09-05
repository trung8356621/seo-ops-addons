<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

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
        $context = [
            'classification' => self::CLASSIFICATION,
            'user_message' => self::userFacingMessage($attemptCount, $routingAttempts),
            'technical_details' => self::CLASSIFICATION,
            'retryable' => false,
            'attempt_count' => $attemptCount,
            'routing_attempts' => $routingAttempts,
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
    public static function userFacingMessage(int $attemptCount, array $routingAttempts = []): string
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
            return 'AI routes exhausted: no eligible AI route was attempted. Check AI Center routing/keys, then retry.';
        }

        if ($parts !== []) {
            return 'AI routes exhausted after '.$attemptCount.' attempt(s): '.implode('; ', $parts).'.';
        }

        return 'AI routes exhausted after '.$attemptCount.' attempt(s). Check AI Center routing/keys, then retry.';
    }
}
