<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiPlannedRoute;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiAttemptBudgetPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Builds an inspectable RoutingPlan BEFORE provider execution.
 * Separates static eligibility from runtime health and attempt budget.
 *
 * FREE-FIRST (text): PRIMARY free lane → SECONDARY paid lane (manual sortable each).
 * PAID-FIRST / media / FreeOnly: PRIMARY sortable only.
 */
final class AiCandidatePlanner
{
    public function __construct(
        private readonly AiAttemptBudgetPolicy $budgetPolicy = new AiAttemptBudgetPolicy(),
        private readonly AiRoutingContextResolver $contextResolver = new AiRoutingContextResolver(),
        private readonly AiFallbackAreaResolver $fallbackAreas = new AiFallbackAreaResolver(),
    ) {}

    /**
     * @param  list<RoutedAiCandidate>  $candidates  PRIMARY area — AI Center order
     * @param  list<RoutedAiCandidate>  $secondaryCandidates  SECONDARY area sortable (paid extracted when FREE-FIRST)
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
        array $secondaryCandidates = [],
        ?string $primaryArea = null,
        ?string $secondaryArea = null,
    ): array {
        $mode = $context->routingMode ?? $this->contextResolver->resolveMode($context);
        $resolvedPrimaryArea = $primaryArea !== null && $primaryArea !== ''
            ? $primaryArea
            : ($modelArea !== '' ? $modelArea : $profile);
        $primaryAreaEnum = AiModelArea::tryFromMixed($resolvedPrimaryArea);
        $usesLaneSplit = $this->fallbackAreas->usesSecondaryPaidLane($primaryAreaEnum);
        $resolvedSecondaryArea = $secondaryArea;
        if ($resolvedSecondaryArea === null && $usesLaneSplit) {
            $resolvedSecondaryArea = $this->fallbackAreas->secondaryAreaFor($primaryAreaEnum)?->value;
        }

        $firstUsable = $this->firstUsableCandidate($candidates, $mode, $healthSkipReason);
        $initialRouteCost = $firstUsable === null
            ? null
            : ($firstUsable->isFree ? 'free' : 'paid');

        $freeFirstSecondaryPaid = $usesLaneSplit
            && $initialRouteCost === 'free'
            && $mode->allowsPaidRoutes();

        $routingPath = $freeFirstSecondaryPaid
            ? AiRoutingPlan::PATH_FREE_PRIMARY_THEN_SECONDARY_PAID
            : AiRoutingPlan::PATH_PRIMARY_ONLY;

        /** @var list<array{candidate: RoutedAiCandidate, phase: string, area: string, index: int}> $stream */
        $stream = [];
        if ($freeFirstSecondaryPaid) {
            $seenPhysical = [];
            foreach ($candidates as $index => $candidate) {
                if (! $candidate->isFree) {
                    continue;
                }
                $key = $candidate->physicalRouteKey();
                if (isset($seenPhysical[$key])) {
                    continue;
                }
                $seenPhysical[$key] = true;
                $stream[] = [
                    'candidate' => $candidate,
                    'phase' => 'primary_free',
                    'area' => $resolvedPrimaryArea,
                    'index' => $index + 1,
                ];
            }
            $secondarySource = $secondaryCandidates !== []
                ? $secondaryCandidates
                : (($resolvedSecondaryArea === $resolvedPrimaryArea) ? $candidates : []);
            foreach ($secondarySource as $index => $candidate) {
                if ($candidate->isFree) {
                    continue;
                }
                $key = $candidate->physicalRouteKey();
                if (isset($seenPhysical[$key])) {
                    continue;
                }
                $seenPhysical[$key] = true;
                $stream[] = [
                    'candidate' => $candidate,
                    'phase' => 'secondary_paid',
                    'area' => $resolvedSecondaryArea ?? $resolvedPrimaryArea,
                    'index' => $index + 1,
                ];
            }
        } else {
            foreach ($candidates as $index => $candidate) {
                $stream[] = [
                    'candidate' => $candidate,
                    'phase' => 'primary',
                    'area' => $resolvedPrimaryArea,
                    'index' => $index + 1,
                ];
            }
        }

        $attemptablePaid = [];
        if ($mode !== AiExecutionRoutingMode::FreeOnly) {
            foreach ($stream as $row) {
                $candidate = $row['candidate'];
                if ($candidate->isFree) {
                    continue;
                }
                if ($healthSkipReason($candidate) !== null) {
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
        $primaryFreePhase = [];
        $secondaryPaidPhase = [];
        $executionOrder = [];
        $logicalPriorityByKey = [];
        $logicalRank = 0;

        foreach ($stream as $row) {
            $candidate = $row['candidate'];
            $phase = $row['phase'];
            $area = $row['area'];
            $index = $row['index'];

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
                'routing_phase' => $phase,
                'manual_position' => $index,
            ];
            if ($mode === AiExecutionRoutingMode::FreeOnly && ! $candidate->isFree) {
                $static = [
                    'eligible' => false,
                    'reason' => 'policy_free_only',
                    'enabled' => true,
                    'connection_active' => true,
                    'cost_class_allowed' => false,
                    'routing_phase' => $phase,
                    'manual_position' => $index,
                ];
                $health = null;
            }

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
                modelArea: $area,
                phase: $phase,
                staticEligibility: array_merge($static, [
                    'runtime_health_skip' => $health,
                    'index' => $index,
                ]),
            );

            if ($candidate->isFree) {
                $freePhase[] = $planned;
            } else {
                $paidPhase[] = $planned;
            }
            if ($phase === 'primary_free') {
                $primaryFreePhase[] = $planned;
            }
            if ($phase === 'secondary_paid') {
                $secondaryPaidPhase[] = $planned;
            }
            if ($static['eligible']) {
                $executionOrder[] = $planned;
            }
        }

        // FREE-FIRST diagnostics: primary paid siblings are intentionally absent from stream.
        if ($freeFirstSecondaryPaid) {
            $primaryFreePhase = $freePhase;
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
                'fallback_area_resolver' => AiFallbackAreaResolver::class,
                'lane_split_enabled' => $usesLaneSplit,
                'free_first_secondary_paid' => $freeFirstSecondaryPaid,
            ],
            primaryFreePhase: $primaryFreePhase,
            secondaryPaidPhase: $secondaryPaidPhase,
            routingPath: $routingPath,
            primaryArea: $resolvedPrimaryArea,
            secondaryArea: $resolvedSecondaryArea,
            initialRouteCost: $initialRouteCost,
        );

        $orderedCandidates = array_map(
            static fn (AiPlannedRoute $route): RoutedAiCandidate => $route->candidate,
            $executionOrder,
        );

        return [$plan, $orderedCandidates];
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @param  callable(RoutedAiCandidate): ?string  $healthSkipReason
     */
    private function firstUsableCandidate(
        array $candidates,
        AiExecutionRoutingMode $mode,
        callable $healthSkipReason,
    ): ?RoutedAiCandidate {
        foreach ($candidates as $candidate) {
            if ($mode === AiExecutionRoutingMode::FreeOnly && ! $candidate->isFree) {
                continue;
            }
            if ($healthSkipReason($candidate) !== null) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
