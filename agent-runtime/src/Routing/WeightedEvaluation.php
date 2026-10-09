<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

final readonly class WeightedEvaluation
{
    /**
     * @param  list<array{ref: string, semantic_relevance: float, weight: float, score: float, group_id: string, example: string}>  $candidates
     */
    public function __construct(
        public string $status,
        public ?string $winner,
        public array $candidates,
    ) {}
}
