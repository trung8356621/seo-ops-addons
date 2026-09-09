<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;

/**
 * Immutable plan produced BEFORE any provider API call.
 */
final class AiRoutingPlan
{
    /**
     * @param  list<AiPlannedRoute>  $freePhase
     * @param  list<AiPlannedRoute>  $paidPhase
     * @param  list<AiPlannedRoute>  $executionOrder
     * @param  array<string, mixed>  $budget
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly AiExecutionRoutingMode $mode,
        public readonly array $freePhase,
        public readonly array $paidPhase,
        public readonly array $executionOrder,
        public readonly array $budget,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'free_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->freePhase,
            ),
            'paid_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->paidPhase,
            ),
            'execution_order' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->executionOrder,
            ),
            'budget' => $this->budget,
            'meta' => $this->meta,
        ];
    }
}
