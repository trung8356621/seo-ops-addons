<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

/**
 * Stable decision-model handle. Contains no API key or bearer.
 */
final readonly class ResolvedDecisionModel
{
    public function __construct(
        public int $connectionId,
        public string $provider,
        public string $model,
        public string $displayName,
        public int $priority,
    ) {}
}
