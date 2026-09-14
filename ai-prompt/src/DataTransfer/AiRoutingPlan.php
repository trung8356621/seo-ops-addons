<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;

/**
 * Immutable plan produced BEFORE any provider API call.
 */
final class AiRoutingPlan
{
    public const PATH_PRIMARY_ONLY = 'primary_only';

    public const PATH_FREE_PRIMARY_THEN_SECONDARY_PAID = 'free_primary_then_secondary_paid';

    /**
     * @param  list<AiPlannedRoute>  $freePhase  Diagnostic / primary-free projection
     * @param  list<AiPlannedRoute>  $paidPhase  Diagnostic / paid projection (primary or secondary)
     * @param  list<AiPlannedRoute>  $executionOrder
     * @param  list<AiPlannedRoute>  $primaryFreePhase
     * @param  list<AiPlannedRoute>  $secondaryPaidPhase
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
        public readonly array $primaryFreePhase = [],
        public readonly array $secondaryPaidPhase = [],
        public readonly string $routingPath = self::PATH_PRIMARY_ONLY,
        public readonly ?string $primaryArea = null,
        public readonly ?string $secondaryArea = null,
        public readonly ?string $initialRouteCost = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        $ordered = array_map(
            static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
            $this->executionOrder,
        );

        return [
            'mode' => $this->mode->value,
            'primary_area' => $this->primaryArea,
            'secondary_area' => $this->secondaryArea,
            'initial_route_cost' => $this->initialRouteCost,
            'routing_path' => $this->routingPath,
            // FREE-FIRST lane projections (empty secondary when PRIMARY-only).
            'primary_free_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->primaryFreePhase,
            ),
            'secondary_paid_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->secondaryPaidPhase,
            ),
            // Diagnostic projections by cost_class — NEVER used as execution order.
            'free_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->freePhase,
            ),
            'paid_phase' => array_map(
                static fn (AiPlannedRoute $route): array => $route->toDebugArray(),
                $this->paidPhase,
            ),
            // SoT: lane-aware stream (PRIMARY only OR PRIMARY free → SECONDARY paid).
            'execution_order' => $ordered,
            'ordered_routes' => $ordered,
            'budget' => $this->budget,
            'meta' => $this->meta,
        ];
    }
}
