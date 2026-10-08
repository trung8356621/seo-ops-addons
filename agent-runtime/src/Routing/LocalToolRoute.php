<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

/**
 * Route descriptor only. This object cannot execute a tool.
 */
final readonly class LocalToolRoute
{
    /**
     * @param  list<array{ref: string, score: float}>  $matches
     */
    public function __construct(
        public string $outcome,
        public ?string $capability,
        public ?float $score,
        public bool $catalogAuthorized,
        public array $matches,
        public string $evidenceKind,
    ) {}

    public function executesTool(): bool
    {
        return false;
    }
}
