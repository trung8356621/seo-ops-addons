<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

final readonly class ToolIntentMatchResult
{
    /**
     * @param  list<array{ref: string, score: float, lexical: bool, semantic_score: float|null}>  $matches
     */
    public function __construct(
        public string $status,
        public array $matches,
    ) {}
}
