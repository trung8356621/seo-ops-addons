<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

final class AiRoutesExhaustedException extends PromptRunException
{
    public const CLASSIFICATION = 'AI_ROUTES_EXHAUSTED';

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    public function __construct(
        int $attemptCount,
        array $routingAttempts = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: self::CLASSIFICATION.': '.$attemptCount.' AI attempts failed.',
            code: 0,
            previous: $previous,
            context: [
                'classification' => self::CLASSIFICATION,
                'user_message' => 'AI providers unavailable after '.$attemptCount.' attempts.',
                'technical_details' => self::CLASSIFICATION,
                'retryable' => false,
                'attempt_count' => $attemptCount,
                'routing_attempts' => $routingAttempts,
            ],
        );
    }
}
