<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiPlannedRoute;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiAttemptBudgetPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;

/**
 * Builds an inspectable RoutingPlan BEFORE provider execution.
 * Separates static eligibility from runtime health and attempt budget.
 */
final class AiCandidatePlanner
{
    public function __construct(
        private readonly AiAttemptBudgetPolicy $budgetPolicy = new AiAttemptBudgetPolicy(),
        private readonly AiRoutingContextResolver $contextResolver = new AiRoutingContextResolver(),
    ) {}

    /**
     * @param  list<RoutedAiCandidate>  $candidates  Already ordered (logical + physical priority)
     * @param  callable(RoutedAiCandidate): ?string  $healthSkipReason  Runtime health — not static eligibility
     * @return array{0: AiRoutingPlan, 1: list<RoutedAiCandidate>}
     */
    public function plan(
        string $profile,
        AiRoutingContext $context,
        array $candidates,
        int $maxAiAttempts,
        int $maxFreeAttempts,
        callable $healthSkipReason,
        string $modelArea = '',
    ): array {
        $mode = $context->routingMode ?? $this->contextResolver->resolveMode($context);

        // ORDER POLICY: preserve caller/AI Center sortable order exactly.
        // COST POLICY: FreeOnly may drop paid from execution; free/paid phase lists are diagnostics.
        $free = [];
        $paid = [];
        foreach ($candidates as $candidate) {
            if ($candidate->isFree) {
                $free[] = $candidate;
            } else {
                $paid[] = $candidate;
            }
        }

        // Default / Economy / Quality / PaidPreferred / Explicit: never rewrite order by cost.
        // FreeOnly: keep configured order for diagnostics; exclude paid from attemptable stream.

        $attemptablePaid = [];
        if ($mode !== AiExecutionRoutingMode::FreeOnly) {
            foreach ($paid as $candidate) {
                $health = $healthSkipReason($candidate);
                if ($health !== null) {
                    continue;
                }
                $attemptablePaid[] = $candidate;
            }
        }

        $budget = $this->budgetPolicy->resolve(
            $maxAiAttempts,
            $maxFreeAttempts,
            $attemptablePaid !== [] && $mode->allowsPaidRoutes(),
            $mode,
        );

        $freePhase = [];
        $paidPhase = [];
        $executionOrder = [];
        $logicalPriorityByKey = [];
        $logicalRank = 0;

        foreach ($candidates as $index => $candidate) {
            $logical = $candidate->logicalModelKey();
            if (! isset($logicalPriorityByKey[$logical])) {
                $logicalRank++;
                $logicalPriorityByKey[$logical] = $logicalRank;
            }

            $health = $healthSkipReason($candidate);
            $static = [
                'eligible' => true,
                'reason' => null,
                'enabled' => true,
                'connection_active' => true,
                'cost_class_allowed' => $mode === AiExecutionRoutingMode::FreeOnly
                    ? $candidate->isFree
                    : true,
            ];
            if ($mode === AiExecutionRoutingMode::FreeOnly && ! $candidate->isFree) {
                $static = [
                    'eligible' => false,
                    'reason' => 'policy_free_only',
                    'enabled' => true,
                    'connection_active' => true,
                    'cost_class_allowed' => false,
                ];
                // Policy exclusion: 0 API attempts — do not treat as health skip.
                $health = null;
            }

            $phase = $candidate->isFree ? 'free' : 'paid';
            $planned = new AiPlannedRoute(
                candidate: $candidate,
                logicalModel: $logical,
                logicalPriority: $logicalPriorityByKey[$logical],
                physicalRoute: $candidate->physicalRouteKey(),
                physicalRoutePriority: $candidate->priority,
                provider: $candidate->provider,
                connectionId: (int) $candidate->connection->id,
                connectionName: (string) $candidate->connection->name,
                providerModel: $candidate->model,
                costClass: $candidate->isFree ? 'free' : 'paid',
                modelArea: $modelArea !== '' ? $modelArea : $profile,
                phase: $phase,
                staticEligibility: array_merge($static, [
                    'runtime_health_skip' => $health,
                    'index' => $index + 1,
                ]),
            );

            if ($candidate->isFree) {
                $freePhase[] = $planned;
            } else {
                $paidPhase[] = $planned;
            }
            if ($static['eligible']) {
                $executionOrder[] = $planned;
            }
        }

        $plan = new AiRoutingPlan(
            mode: $mode,
            freePhase: $freePhase,
            paidPhase: $paidPhase,
            executionOrder: $executionOrder,
            budget: $budget,
            meta: [
                'profile' => $profile,
                'hook_key' => $context->hookKey,
                'prompt_key' => $context->canonicalPromptKey ?? $context->hookKey,
                'routing_decision_source' => $context->routingDecisionSource,
                'correlation_id' => $context->correlationId,
                'attemptable_paid_count' => count($attemptablePaid),
                'physical_route_order_source' => 'seo_ai_models.capabilities.omi_areas.{area}.priority + LogicalModelRouteOrder',
                'logical_model_order_source' => 'AiModelPriorityService.areaEnabledModels',
            ],
        );

        $orderedCandidates = array_map(
            static fn (AiPlannedRoute $route): RoutedAiCandidate => $route->candidate,
            $executionOrder,
        );

        return [$plan, $orderedCandidates];
    }
}
