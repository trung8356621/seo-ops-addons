<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingPlan;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiAttemptBudgetPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionRoutingMode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageCapability;
use Omnichannel\Addons\Media\Support\ImageCapabilityResolver;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiModelRouterService implements \Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver
{
    private const MAX_FAILOVER_ATTEMPTS = 8;

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilityRegistry = new ModelCapabilityRegistry(),
        private readonly ?AiRoutingTargetService $routingTargetService = null,
        private readonly ?AiRoutingBootstrapService $routingBootstrapService = null,
        private readonly ?DeepSeekChatClient $deepSeekClient = null,
    ) {}

    /**
     * Canonical profile → candidate resolution. Prompt/Automation must use this.
     */
    public function resolve(string $profile, AiRoutingContext $context): RoutedAiCandidate
    {
        $candidates = $this->resolveAll($profile, $context);
        if ($candidates === []) {
            $parsed = AiExecutionProfile::tryFrom($profile);
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        return $candidates[0];
    }

    /**
     * First candidate that normal execution would attempt (AI Center order + freeOnly + health skips).
     * Does not skip based on PromptBudget / capability estimators.
     */
    public function resolveFirstAttemptable(string $profile, AiRoutingContext $context): RoutedAiCandidate
    {
        $candidates = $this->resolveAll($profile, $context);
        $policyFreeOnly = $context->freeOnly
            || $context->costPolicy()->isFreeOnly()
            || (($context->routingMode ?? null) === AiExecutionRoutingMode::FreeOnly);
        if ($policyFreeOnly) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (RoutedAiCandidate $candidate): bool => $candidate->isFree,
            ));
        }

        if ($candidates === []) {
            $parsed = AiExecutionProfile::tryFrom($profile);
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            if ($policyFreeOnly) {
                throw AiRoutingException::noValidFreeConnection($profile);
            }
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        $userId = $context->userId !== null && $context->userId > 0
            ? $context->userId
            : app(AiRoutingOwnerResolver::class)->resolve(
                explicitUserId: null,
                prompt: null,
                connection: $candidates[0]->connection ?? null,
            );
        if ($userId <= 0) {
            $userId = (int) (auth()->id() ?? 0);
        }

        $health = $this->runtimeHealth();
        $parsed = AiExecutionProfile::tryFrom($profile);
        $firstHealthUsable = null;
        foreach ($candidates as $candidate) {
            $skipReason = $health->skipReason($userId, $candidate);
            if ($skipReason !== null) {
                continue;
            }

            $firstHealthUsable ??= $candidate;

            // Share the same pre-execution capacity authority as the attempt loop so
            // generation shape follows the first ACTUALLY usable physical route.
            if ($parsed instanceof AiExecutionProfile) {
                $capacity = $this->routeCapacityPolicy()->evaluate($candidate, $parsed, $context);
                if (! $capacity->eligible) {
                    continue;
                }
            }

            return $candidate;
        }

        // Let the execution loop own capacity exhaustion and its route diagnostics.
        // Shape planning must not turn an available model into a misleading no-candidate error.
        if ($firstHealthUsable instanceof RoutedAiCandidate) {
            return $firstHealthUsable;
        }

        // No usable route after health + capacity skips — normal routing failure (no fake shape authority).
        $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
        if ($policyFreeOnly) {
            throw AiRoutingException::noValidFreeConnection($profile);
        }
        throw AiRoutingException::noCandidate($profile, $capability);
    }

    /**
     * @return list<RoutedAiCandidate>
     */
    public function resolveAll(string $profile, AiRoutingContext $context): array
    {
        $parsed = AiExecutionProfile::tryFrom($profile);
        if ($parsed === null) {
            throw new AiRoutingException('Unknown routing profile: '.$profile);
        }

        $userId = $context->userId ?? (int) (auth()->id() ?? 0);
        $targets = $this->targetsService();
        $bootstrap = $this->bootstrapService();

        if ($userId > 0 && $targets !== null && $bootstrap !== null) {
            if ($targets->targetsFor($userId, $parsed->value) === []) {
                $bootstrap->bootstrapForUser($userId);
            }
            $candidates = $targets->eligibleCandidates($userId, $parsed, $context);
            if ($candidates !== []) {
                return $this->applyItemRoutingPreferences($candidates, $context);
            }
        }

        if ($context->allowLegacyFallback && $context->legacyConnection instanceof ApiConnection) {
            $legacy = $this->legacyCompatibleCandidate($parsed, $context->legacyConnection);
            if ($legacy !== null) {
                return $this->applyItemRoutingPreferences([$legacy], $context);
            }
        }

        return $this->applyItemRoutingPreferences([], $context);
    }

    /**
     * MODEL ORDER AUTHORITY:
     * AI Center sortable order is preserved. Generation mode must not reorder.
     * Only explicit user model override may float a preferred model.
     * Health / FreeOnly / availability may filter but must preserve relative order.
     *
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    private function applyItemRoutingPreferences(array $candidates, AiRoutingContext $context): array
    {
        $mode = null;
        if (class_exists(\Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode::class)) {
            $mode = \Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode::tryFromMixed(
                $context->itemGenerationMode,
            );
        }

        if (class_exists(\Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationRoutingPreference::class)) {
            // orderCandidates preserves AI Center order (cost class never reorders).
            $candidates = \Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationRoutingPreference::orderCandidates(
                $candidates,
                $mode,
            );

            // Explicit user model_override_id only (preferred prepend). Not auto-primary.
            $candidates = \Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationRoutingPreference::prependPreferred(
                $candidates,
                $context->preferredModelId,
                static fn (RoutedAiCandidate $candidate): ?int => $candidate->seoAiModelId,
            );
        }

        $preferredId = $context->preferredModelId;
        if ($preferredId !== null && $preferredId > 0 && $context->requirePreferredModel) {
            $found = false;
            foreach ($candidates as $candidate) {
                if ((int) ($candidate->seoAiModelId ?? 0) === $preferredId) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                throw AiRoutingException::noCandidate(
                    $context->itemGenerationMode ?? 'required_model',
                    'model.override.'.$preferredId,
                );
            }
        }

        return $candidates;
    }

    /**
     * Infrastructure fallback across profile candidates. Does not retry on "quality".
     *
     * @param  callable(RoutedAiCandidate): array{0: string, 1: array<string, mixed>|null}  $executor
     * @return array{0: string, 1: array<string, mixed>|null, 2: RoutedAiCandidate, 3: int, 4: list<string>, 5?: list<array<string, mixed>>}
     */
    public function executeWithProfile(
        string $profile,
        AiRoutingContext $context,
        callable $executor,
    ): array {
        $parsed = AiExecutionProfile::tryFrom($profile);
        $candidates = $this->resolveAll($profile, $context);
        if ($candidates === []) {
            $eligibility = $this->eligibilityDiagnostics();
            $diagnostics = [
                'routing_context' => [
                    'profile' => $profile,
                    'hook_key' => $context->hookKey,
                    'use_case' => $context->hookKey ?? $profile,
                    'free_only' => $context->isFreeOnly(),
                ],
                'free_only' => $context->isFreeOnly(),
                'rejection_reason' => 'no_candidates_resolved_for_profile',
                'routes_evaluated' => [],
                'eligibility_funnel' => $eligibility,
                'catalog' => $this->catalogFunnelDiagnostics($context->userId),
            ];
            if (function_exists('logger')) {
                logger()->warning('ai.routing.no_candidates_resolved', $diagnostics);
            }
            if ($context->isFreeOnly()) {
                throw AiRoutingException::noValidFreeConnection($profile);
            }
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        // Per-execution isolation for sectioned_free: free candidates only.
        // Does not mutate global routing / cost policy / session state.
        if ($context->isFreeOnly()) {
            $freeCandidates = array_values(array_filter(
                $candidates,
                static fn (RoutedAiCandidate $candidate): bool => $candidate->isFree,
            ));
            if ($freeCandidates === []) {
                $diagnostics = [
                    'routing_context' => [
                        'profile' => $profile,
                        'hook_key' => $context->hookKey,
                        'use_case' => $context->hookKey ?? $profile,
                        'free_only' => true,
                    ],
                    'free_only' => true,
                    'rejection_reason' => 'all_candidates_filtered_by_free_only_policy',
                    'routes_evaluated' => array_map(static fn (RoutedAiCandidate $c): array => [
                        'provider' => $c->provider,
                        'route_model' => $c->model,
                        'connection_id' => (int) $c->connection->id,
                        'connection_name' => (string) $c->connection->name,
                        'cost_classification' => $c->isFree ? 'free' : 'paid',
                        'enabled' => true,
                        'health_state' => 'unknown',
                        'eligibility_result' => false,
                        'rejection_reason' => 'cost_class_paid_disallowed_in_free_only',
                    ], $candidates),
                ];
                if (function_exists('logger')) {
                    logger()->warning('ai.routing.free_only_no_free_candidates', $diagnostics);
                }
                throw AiRoutingException::noValidFreeConnection($profile);
            }
            $candidates = $freeCandidates;
        }

        if ($context->requirePreferredModel && $context->preferredModelId !== null && $context->preferredModelId > 0) {
            $preferredId = $context->preferredModelId;
            $candidates = array_values(array_filter(
                $candidates,
                static fn (RoutedAiCandidate $candidate): bool => (int) ($candidate->seoAiModelId ?? 0) === $preferredId,
            ));
            if ($candidates === []) {
                if ($context->isFreeOnly()) {
                    throw AiRoutingException::noValidFreeConnection($profile);
                }
                throw AiRoutingException::noCandidate($profile, 'model.override.'.$preferredId);
            }
        }

        $userId = $context->userId !== null && $context->userId > 0
            ? $context->userId
            : app(AiRoutingOwnerResolver::class)->resolve(
                explicitUserId: null,
                prompt: null,
                connection: $candidates[0]->connection ?? null,
            );
        if ($userId <= 0) {
            $userId = (int) (auth()->id() ?? 0);
        }
        $settings = $this->resilienceSettings()->get($userId);
        $maxAiAttempts = (int) ($context->maxAiAttempts
            ?? $settings[AiResilienceSettingsService::KEY_MAX_AI_ATTEMPTS]);
        $maxFreeAttempts = (int) ($context->maxFreeAttempts
            ?? $settings[AiResilienceSettingsService::KEY_MAX_FREE_ATTEMPTS]);
        $classifier = $this->failureClassifier();
        $health = $this->runtimeHealth();

        $contextResolver = $this->routingContextResolver();
        $enrichedContext = $contextResolver->enrich($context, $maxAiAttempts, $maxFreeAttempts);
        $routingMode = $enrichedContext->routingMode ?? $contextResolver->resolveMode($enrichedContext);

        $fallbackAreas = $this->fallbackAreaResolver();
        $primaryAreaEnum = $parsed !== null
            ? $fallbackAreas->primaryAreaFor($parsed)
            : null;
        $primaryAreaKey = $primaryAreaEnum?->value
            ?? (string) ($enrichedContext->modelArea ?? $parsed?->value ?? $profile);
        $secondaryAreaEnum = $primaryAreaEnum !== null
            ? $fallbackAreas->secondaryAreaFor($primaryAreaEnum)
            : null;
        $secondaryCandidates = $this->resolveSecondaryPaidLaneCandidates(
            profile: $profile,
            parsed: $parsed,
            context: $enrichedContext,
            primaryCandidates: $candidates,
            routingMode: $routingMode,
            userId: $userId,
            health: $health,
            primaryArea: $primaryAreaEnum,
            secondaryArea: $secondaryAreaEnum,
        );

        [$routingPlan, $candidates] = $this->candidatePlanner()->plan(
            profile: $profile,
            context: $enrichedContext,
            candidates: $candidates,
            maxAiAttempts: $maxAiAttempts,
            maxFreeAttempts: $maxFreeAttempts,
            healthSkipReason: static fn (RoutedAiCandidate $candidate): ?string => $health->skipReason($userId, $candidate),
            modelArea: $primaryAreaKey,
            secondaryCandidates: $secondaryCandidates,
            primaryArea: $primaryAreaKey,
            secondaryArea: $secondaryAreaEnum?->value,
        );

        /** @var array<string, string> physical route → routing phase */
        $phaseByPhysicalRoute = [];
        foreach ($routingPlan->executionOrder as $plannedRoute) {
            $phaseByPhysicalRoute[$plannedRoute->physicalRoute] = $plannedRoute->phase;
        }
        $laneTransitionReason = null;
        $secondaryLaneEntered = false;

        if ($candidates === []) {
            $diagnostics = [
                'routing_context' => [
                    'profile' => $profile,
                    'hook_key' => $context->hookKey,
                    'use_case' => $context->hookKey ?? $profile,
                    'item_generation_mode' => $context->itemGenerationMode,
                    'free_only' => $context->isFreeOnly(),
                    'routing_mode' => $routingMode->value,
                    'routing_decision_source' => $enrichedContext->routingDecisionSource,
                ],
                'free_only' => $context->isFreeOnly(),
                'routing_mode' => $routingMode->value,
                'rejection_reason' => $routingMode === AiExecutionRoutingMode::FreeOnly
                    ? 'no_eligible_free_routes_after_planning'
                    : 'no_eligible_routes_after_planning',
                'routes_evaluated' => array_map(static fn (AiPlannedRoute $route): array => [
                    'provider' => $route->provider,
                    'route_model' => $route->providerModel,
                    'connection_id' => $route->connectionId,
                    'connection_name' => $route->connectionName,
                    'cost_classification' => $route->costClass,
                    'enabled' => $route->staticEligibility['enabled'] ?? true,
                    'health_state' => $route->staticEligibility['runtime_health_skip'] ?? 'healthy',
                    'eligibility_result' => $route->staticEligibility['eligible'] ?? false,
                    'rejection_reason' => $route->staticEligibility['reason']
                        ?? $route->staticEligibility['runtime_health_skip']
                        ?? 'not_eligible',
                ], array_merge($routingPlan->freePhase, $routingPlan->paidPhase)),
            ];

            if (function_exists('logger')) {
                logger()->warning('ai.routing.planner_empty_routes', $diagnostics);
            }

            if ($routingMode === AiExecutionRoutingMode::FreeOnly) {
                throw AiRoutingException::noValidFreeConnection($profile);
            }
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        $budgetPolicy = new AiAttemptBudgetPolicy();
        $budget = $routingPlan->budget;
        $reservedPaidSlots = (int) ($budget['reserved_paid_slots'] ?? 0);
        $effectiveMaxFreeAttempts = (int) ($budget['free_budget'] ?? 0);
        $attemptablePaidExist = (int) ($routingPlan->meta['attemptable_paid_count'] ?? 0) > 0;
        $attemptablePaidCount = (int) ($routingPlan->meta['attemptable_paid_count'] ?? 0);

        $fallbackCount = 0;
        $reasons = [];
        $routingAttempts = [];
        $actualAttempts = 0;
        $freeAttempts = 0;
        $paidAttempts = 0;
        $candidatesTried = 0;
        $candidatesSkipped = 0;
        $lastException = null;
        $routeRevision = null;
        if ($parsed !== null) {
            try {
                if (function_exists('app')) {
                    $routeRevision = app(CanonicalAiRouteResolver::class)
                        ->routeRevision($userId > 0 ? $userId : 0, $parsed);
                }
            } catch (\Throwable) {
                $routeRevision = null;
            }
        }

        $eligibleModels = array_map(
            static fn (RoutedAiCandidate $candidate): string => $candidate->model,
            $candidates,
        );
        $eligiblePhysicalRoutes = array_map(
            static fn (RoutedAiCandidate $candidate): string => $candidate->physicalRouteKey(),
            $candidates,
        );

        /** @var array<int, true> full connection suppress (credential / account) — never keyed by logical model */
        $suppressedConnections = [];
        /** @var array<int, true> paid billing-lane suppress — free routes on same connection remain eligible */
        $suppressedPaidLanes = [];
        /** @var array<int, true> free billing-lane suppress — paid routes on same connection remain eligible */
        $suppressedFreeLanes = [];
        /** @var array<string, true> physical routes already attempted this execution */
        $attemptedPhysicalRoutes = [];
        /** After OUTPUT_TRUNCATED, skip remaining free candidates and prefer paid physical routes. */
        $preferPaidAfterOutputTruncation = false;

        foreach ($candidates as $index => $candidate) {
            $candidateIndex = $index + 1;
            $connectionId = (int) $candidate->connection->id;
            $healthBefore = $health->skipReason($userId, $candidate);
            $routePhase = $phaseByPhysicalRoute[$candidate->physicalRouteKey()] ?? ($candidate->isFree ? 'primary_free' : 'primary');
            if ($routePhase === 'secondary_paid' && ! $secondaryLaneEntered) {
                $secondaryLaneEntered = true;
                $laneTransitionReason = $this->resolveSecondaryLaneTransitionReason($routingAttempts);
            }
            $laneMeta = static function () use (
                $routingPlan,
                $routePhase,
                &$laneTransitionReason,
                $secondaryLaneEntered,
                $candidate,
            ): array {
                return array_filter([
                    'primary_area' => $routingPlan->primaryArea,
                    'secondary_area' => $routingPlan->secondaryArea,
                    'routing_path' => $routingPlan->routingPath,
                    'initial_route_cost' => $routingPlan->initialRouteCost,
                    'phase' => $routePhase,
                    'is_free' => $candidate->isFree,
                    'candidate_manual_position' => $candidate->priority,
                    'lane_transition_reason' => $secondaryLaneEntered ? $laneTransitionReason : null,
                ], static fn (mixed $v): bool => $v !== null && $v !== '');
            };
            $budgetMeta = static function () use (
                &$actualAttempts,
                &$freeAttempts,
                &$paidAttempts,
                &$effectiveMaxFreeAttempts,
                $maxAiAttempts,
                $maxFreeAttempts,
                &$reservedPaidSlots,
                $healthBefore,
            ): array {
                return [
                    'actual_attempts' => $actualAttempts,
                    'free_attempts' => $freeAttempts,
                    'paid_attempts' => $paidAttempts,
                    'effective_max_free_attempts' => $effectiveMaxFreeAttempts,
                    'max_ai_attempts' => $maxAiAttempts,
                    'max_free_attempts' => $maxFreeAttempts,
                    'reserved_paid_slots' => $reservedPaidSlots,
                    'health_state_before' => $healthBefore,
                    'candidate_attempt_number' => 1,
                ];
            };

            if (isset($suppressedConnections[$connectionId])) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    'connection_suppressed',
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                    ),
                );
                continue;
            }

            if (! $candidate->isFree && isset($suppressedPaidLanes[$connectionId])) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    'paid_lane_suppressed',
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                    ),
                );
                continue;
            }

            if ($candidate->isFree && isset($suppressedFreeLanes[$connectionId])) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    'free_lane_suppressed',
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                    ),
                );
                continue;
            }

            if ($preferPaidAfterOutputTruncation && $candidate->isFree) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    'output_truncated_prefer_paid',
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                        [
                            'provider_terminal_reason' => \Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason::OutputTruncated->value,
                        ],
                    ),
                );
                continue;
            }

            if ($parsed instanceof AiExecutionProfile) {
                $capacity = $this->routeCapacityPolicy()->evaluate(
                    $candidate,
                    $parsed,
                    $enrichedContext,
                );
                if (! $capacity->eligible) {
                    $candidatesSkipped++;
                    $routingAttempts[] = $this->attemptLog(
                        $candidate,
                        $candidateIndex,
                        'skipped',
                        (string) ($capacity->reason ?? 'capacity_ineligible'),
                        null,
                        array_merge(
                            $laneMeta(),
                            array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                            $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                            $capacity->toAttemptDiagnostics(),
                        ),
                    );
                    continue;
                }
            }

            if ($healthBefore !== null) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    $healthBefore,
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                        $this->connectionPaidLockAttemptMeta($candidate, $healthBefore),
                    ),
                );
                if ($healthBefore === 'connection_locked') {
                    $suppressedConnections[$connectionId] = true;
                } elseif ($healthBefore === 'connection_paid_locked') {
                    $suppressedPaidLanes[$connectionId] = true;
                } elseif ($healthBefore === 'free_lane_suppressed') {
                    $suppressedFreeLanes[$connectionId] = true;
                }
                continue;
            }

            // Reclaim reserved paid slot when no remaining paid is attemptable.
            $remainingPaid = $this->hasRemainingAttemptableNonFree(
                $userId,
                $candidates,
                $index,
                $health,
                $suppressedConnections,
                $suppressedPaidLanes,
            );
            if (! $remainingPaid && $reservedPaidSlots > 0) {
                $budget = $budgetPolicy->reclaimPaidReserveWhenNoPaidRemain([
                    'max_ai_attempts' => $maxAiAttempts,
                    'max_free_attempts' => $maxFreeAttempts,
                    'reserved_paid_slots' => $reservedPaidSlots,
                    'free_budget' => $effectiveMaxFreeAttempts,
                    'required_paid_fallback_reserve' => $reservedPaidSlots,
                ]);
                $reservedPaidSlots = (int) $budget['reserved_paid_slots'];
                $effectiveMaxFreeAttempts = (int) $budget['free_budget'];
            }
            // Do NOT re-reserve one slot per remaining paid candidate — that zeros freeBudget.

            if ($candidate->isFree && $freeAttempts >= $effectiveMaxFreeAttempts) {
                $candidatesSkipped++;
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $candidateIndex,
                    'skipped',
                    'free_attempt_budget_exhausted',
                    null,
                    array_merge(
                        $laneMeta(),
                        array_filter($budgetMeta(), static fn (mixed $v): bool => $v !== null && $v !== ''),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                    ),
                );
                continue;
            }

            if ($actualAttempts >= $maxAiAttempts) {
                break;
            }

            $actualAttempts++;
            $candidatesTried++;
            $attemptedPhysicalRoutes[$candidate->physicalRouteKey()] = true;
            if ($candidate->isFree) {
                $freeAttempts++;
            } else {
                $paidAttempts++;
            }
            $providerAttempt = $actualAttempts;

            try {
                [$output, $usage] = $executor($candidate);
                $health->recordSuccess($userId, $candidate);
                $actualProviderModel = is_array($usage)
                    ? trim((string) ($usage['resolved_model'] ?? $usage['actual_provider_model'] ?? ''))
                    : '';
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $providerAttempt,
                    'success',
                    null,
                    null,
                    array_merge(
                        $laneMeta(),
                        $this->attemptBudgetMeta(
                            $actualAttempts,
                            $freeAttempts,
                            $paidAttempts,
                            $effectiveMaxFreeAttempts,
                            $maxAiAttempts,
                            $maxFreeAttempts,
                            $reservedPaidSlots,
                            null,
                        ),
                        $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                        array_filter([
                            'candidate_index' => $candidateIndex,
                            'candidates_tried' => $candidatesTried,
                            'candidates_skipped' => $candidatesSkipped,
                            'request_sent' => true,
                            'actual_provider_model' => $actualProviderModel !== '' ? $actualProviderModel : null,
                            'requested_model' => is_array($usage)
                                ? (trim((string) ($usage['requested_model'] ?? '')) ?: null)
                                : null,
                            'token_usage' => is_array($usage) ? $usage : null,
                        ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                    ),
                );

                return [$output, is_array($usage) ? array_merge($usage, [
                    '_routing_plan' => $routingPlan->toDebugArray(),
                    '_routing_mode' => $routingMode->value,
                    '_routing_decision_source' => $enrichedContext->routingDecisionSource,
                    '_correlation_id' => $enrichedContext->correlationId,
                ]) : [
                    '_routing_plan' => $routingPlan->toDebugArray(),
                    '_routing_mode' => $routingMode->value,
                ], $candidate, $fallbackCount, $reasons, $routingAttempts];
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $decision = $classifier->classify($exception);

                $isCapabilitySkip = $exception instanceof \Omnichannel\Addons\AiPrompt\Exceptions\AiRouteCapabilitySkipException
                    || ($exception instanceof PromptRunException && ($exception->context['capability_skip'] ?? false) === true);

                if ($isCapabilitySkip) {
                    // Pre-execution filter — not a provider attempt failure.
                    unset($attemptedPhysicalRoutes[$candidate->physicalRouteKey()]);
                    $actualAttempts = max(0, $actualAttempts - 1);
                    $candidatesTried = max(0, $candidatesTried - 1);
                    $candidatesSkipped++;
                    if ($candidate->isFree) {
                        $freeAttempts = max(0, $freeAttempts - 1);
                    } else {
                        $paidAttempts = max(0, $paidAttempts - 1);
                    }
                    $routingAttempts[] = $this->attemptLog(
                        $candidate,
                        $candidateIndex,
                        'skipped',
                        'capability_mismatch',
                        null,
                        array_merge(
                            $laneMeta(),
                            $this->attemptBudgetMeta(
                                $actualAttempts,
                                $freeAttempts,
                                $paidAttempts,
                                $effectiveMaxFreeAttempts,
                                $maxAiAttempts,
                                $maxFreeAttempts,
                                $reservedPaidSlots,
                                $healthBefore,
                            ),
                            $this->siblingRouteMeta($candidates, $index, $suppressedConnections, $suppressedPaidLanes, $attemptedPhysicalRoutes, $suppressedFreeLanes),
                            $decision->toAttemptDiagnostics(),
                        ),
                    );
                    continue;
                }

                if (! $decision->shouldContinueRouting()) {
                    throw $exception instanceof PromptRunException
                        ? $exception
                        : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
                }

                $health->recordFailure($userId, $candidate, $decision);
                $this->applyLegacyHealthSideEffects($candidate, $decision);

                $healthMutation = null;
                if ($this->isFullConnectionSuppressDecision($decision)) {
                    $suppressedConnections[$connectionId] = true;
                    $healthMutation = 'connection_locked';
                } elseif ($this->isPaidLaneSuppressDecision($decision)) {
                    $suppressedPaidLanes[$connectionId] = true;
                    $healthMutation = $decision->lockConnectionPaid
                        ? 'connection_paid_locked'
                        : 'connection_paid_request_budget_suppressed';
                } elseif ($this->isFreeLaneSuppressDecision($decision)) {
                    $suppressedFreeLanes[$connectionId] = true;
                    $healthMutation = 'free_lane_suppressed';
                }

                if ($exception instanceof \Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated) {
                    $preferPaidAfterOutputTruncation = true;
                }

                $fallbackCount++;
                $reasons[] = 'position '.$candidate->priority.' attempt '.$providerAttempt.' '
                    .$candidate->provider.'/'.$candidate->model.': '.$decision->safeMessage;
                $siblingMeta = $this->siblingRouteMeta(
                    $candidates,
                    $index,
                    $suppressedConnections,
                    $suppressedPaidLanes,
                    $attemptedPhysicalRoutes,
                    $suppressedFreeLanes,
                );
                $routingAttempts[] = $this->attemptLog(
                    $candidate,
                    $providerAttempt,
                    'failed',
                    $decision->category->value,
                    $decision->httpStatus,
                    array_merge(
                        $laneMeta(),
                        $this->qualityAttemptMeta($exception),
                        $decision->toAttemptDiagnostics(),
                        $this->attemptBudgetMeta(
                            $actualAttempts,
                            $freeAttempts,
                            $paidAttempts,
                            $effectiveMaxFreeAttempts,
                            $maxAiAttempts,
                            $maxFreeAttempts,
                            $reservedPaidSlots,
                            $healthBefore,
                        ),
                        $siblingMeta,
                        [
                            'candidate_index' => $candidateIndex,
                            'candidates_tried' => $candidatesTried,
                            'candidates_skipped' => $candidatesSkipped,
                            'failure_scope' => $decision->scope->value,
                            'billing_lane' => $candidate->isFree ? 'free' : 'paid',
                            'retryable' => $decision->recoverable,
                            'fallbackable' => $decision->fallbackAllowed(),
                            'connection_suppressed' => $this->isFullConnectionSuppressDecision($decision),
                            'paid_lane_suppressed' => $this->isPaidLaneSuppressDecision($decision),
                            'free_lane_suppressed' => $this->isFreeLaneSuppressDecision($decision),
                            'health_mutation' => $healthMutation,
                            'logical_model_exhausted' => ! (bool) ($siblingMeta['sibling_routes_remain_eligible'] ?? false),
                            'request_sent' => $decision->requestSent ?? true,
                            'token_usage' => $this->extractFailureUsage($exception),
                        ],
                    ),
                );

                logger()->warning('AI routing infrastructure fallback', array_merge(
                    $candidate->toAttemptLogContext($providerAttempt, $routeRevision),
                    [
                        'failure_class' => $decision->category->value,
                        'failure_scope' => $decision->scope->value,
                        'http_status' => $decision->httpStatus,
                        'error' => $decision->safeMessage,
                        'fallback_allowed' => $decision->fallbackAllowed(),
                        'failure_stage' => $decision->failureStage,
                        'connection_suppressed' => $this->isFullConnectionSuppressDecision($decision),
                        'paid_lane_suppressed' => $this->isPaidLaneSuppressDecision($decision),
                        'free_lane_suppressed' => $this->isFreeLaneSuppressDecision($decision),
                        'health_mutation' => $healthMutation,
                        'sibling_routes_remain_eligible' => $siblingMeta['sibling_routes_remain_eligible'] ?? false,
                        'eligible_sibling_physical_routes' => $siblingMeta['eligible_sibling_physical_routes'] ?? [],
                        'next' => $decision->fallbackAllowed() && isset($candidates[$index + 1]),
                        'routing_owner_user_id' => $userId,
                        'eligible_models' => $eligibleModels,
                        'eligible_physical_routes' => $eligiblePhysicalRoutes,
                        'free_attempts' => $freeAttempts,
                        'actual_attempts' => $actualAttempts,
                        'effective_max_free_attempts' => $effectiveMaxFreeAttempts,
                    ],
                    $this->qualityAttemptMeta($exception),
                ));
            }
        }

        $classified = (new \Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier())
            ->classify($routingAttempts, $actualAttempts);

        $retryAfter = null;
        try {
            $retryAfter = $health->retryAfterSeconds($userId, $candidates);
        } catch (\Throwable) {
        }
        if ($classified['retryable'] && ($retryAfter === null || $retryAfter <= 0)) {
            $retryAfter = 30;
        }

        $connectionIds = [];
        $providerKeys = [];
        foreach ($candidates as $candidate) {
            $connectionIds[(int) $candidate->connection->id] = true;
            $providerKeys[(string) $candidate->provider] = true;
        }

        $failCounts = $this->attemptCounts($routingAttempts, 'failed', 'failure_class');
        $lastFailureClass = null;
        foreach (array_reverse($routingAttempts) as $row) {
            if (! is_array($row) || (string) ($row['result'] ?? '') !== 'failed') {
                continue;
            }
            $cls = (string) ($row['failure_class'] ?? '');
            if ($cls !== '') {
                $lastFailureClass = $cls;
                break;
            }
        }

        $diagnostics = array_merge($this->eligibilityDiagnostics(), [
            'routing_owner_user_id' => $userId,
            'profile' => $profile,
            'hook_key' => $context->hookKey,
            'free_only' => $context->isFreeOnly(),
            'routing_mode' => $routingMode->value,
            'routing_decision_source' => $enrichedContext->routingDecisionSource,
            'routing_plan' => $routingPlan->toDebugArray(),
            'primary_area' => $routingPlan->primaryArea,
            'secondary_area' => $routingPlan->secondaryArea,
            'routing_path' => $routingPlan->routingPath,
            'initial_route_cost' => $routingPlan->initialRouteCost,
            'lane_transition_reason' => $laneTransitionReason,
            'correlation_id' => $enrichedContext->correlationId,
            'eligible_models' => $eligibleModels,
            'eligible_count' => count($candidates),
            'eligible_connection_count' => count($connectionIds),
            'eligible_provider_count' => count($providerKeys),
            'candidates_before_health' => count($candidates),
            'max_ai_attempts' => $maxAiAttempts,
            'max_free_attempts' => $maxFreeAttempts,
            'effective_max_free_attempts' => $effectiveMaxFreeAttempts,
            'reserved_paid_slots' => $reservedPaidSlots,
            'required_paid_fallback_reserve' => (int) ($budget['required_paid_fallback_reserve'] ?? $reservedPaidSlots),
            'free_attempts' => $freeAttempts,
            'paid_attempts' => $paidAttempts,
            'actual_attempts' => $actualAttempts,
            'candidates_tried' => $candidatesTried,
            'candidates_skipped' => $candidatesSkipped,
            'attemptable_paid_existed' => $attemptablePaidExist,
            'attemptable_paid_count' => $attemptablePaidCount,
            'exhaustion_kind' => $classified['exhaustion_kind'],
            'retryable' => $classified['retryable'],
            'temporary' => $classified['temporary'],
            'retry_after_seconds' => $retryAfter,
            'health_skip_count' => $classified['health_skip_count'],
            'hard_skip_count' => $classified['hard_skip_count'],
            'transient_failure_count' => $classified['transient_failure_count'],
            'hard_failure_count' => $classified['hard_failure_count'],
            'free_budget_skip_count' => $classified['free_budget_skip_count'],
            'skip_counts' => $this->attemptCounts($routingAttempts, 'skipped', 'skip_reason'),
            'fail_counts' => $failCounts,
            'last_failure_class' => $lastFailureClass,
            'connection_lock_reason' => $lastFailureClass,
        ]);

        foreach (array_reverse($routingAttempts) as $row) {
            if (! is_array($row) || (string) ($row['result'] ?? '') !== 'failed') {
                continue;
            }
            if ((string) ($row['failure_class'] ?? '') !== AiFailureClass::DailyFreeQuotaExhausted->value) {
                continue;
            }
            foreach (['limit_source', 'rate_limit_limit', 'rate_limit_remaining', 'rate_limit_reset', 'free_daily_reset_at'] as $diagKey) {
                if (isset($row[$diagKey]) && $row[$diagKey] !== null && $row[$diagKey] !== '') {
                    $diagnostics[$diagKey] = $row[$diagKey];
                }
            }
            $diagnostics['free_lane_suppressed'] = true;
            break;
        }

        $normalizedFailure = (new AiPrimaryFailureSelector())->select(
            terminalException: $lastException instanceof \Throwable ? $lastException : null,
            routingAttempts: $routingAttempts,
            actualAttempts: $actualAttempts,
            promptKey: $enrichedContext->canonicalPromptKey ?? $enrichedContext->hookKey,
            stage: $enrichedContext->promptTaskType ?? $enrichedContext->hookKey,
            correlationId: $enrichedContext->correlationId,
        );
        $diagnostics['normalized_failure'] = $normalizedFailure->toArray();
        $diagnostics['routing_terminal_reason'] = 'routes_exhausted';
        $diagnostics['primary_failure_category'] = $normalizedFailure->category->value;
        $diagnostics['primary_failure_code'] = $normalizedFailure->code instanceof \Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode
            ? $normalizedFailure->code->value
            : (string) $normalizedFailure->code;

        if (function_exists('logger')) {
            logger()->info('ai.routing.exhausted', array_merge($diagnostics, [
                'user_id' => $userId,
                'attempt_count' => $actualAttempts,
                'routing_attempts' => $routingAttempts,
            ]));
        }

        throw new AiRoutesExhaustedException(
            attemptCount: $actualAttempts,
            routingAttempts: $routingAttempts,
            previous: $lastException instanceof \Throwable ? $lastException : null,
            diagnostics: $diagnostics,
        );
    }

    /**
     * Per-reason tallies over the routing attempt log.
     *
     * @param  list<array<string, mixed>>  $routingAttempts
     * @return array<string, int>
     */
    /**
     * Full connection suppress (credential / account) — blocks paid and free on that connection.
     * Model-scoped RateLimited must NEVER full-suppress: free/paid often share one key with separate buckets.
     * Account-wide / organization quota (scope=Connection) MUST suppress the connection for the rest of the route.
     */
    private function isFullConnectionSuppressDecision(AiFailureDecision $decision): bool
    {
        if ($decision->category === AiFailureClass::RateLimited
            && $decision->scope !== AiFailureScope::Connection) {
            return false;
        }

        return $decision->lockConnection
            || $decision->scope === AiFailureScope::Connection;
    }

    /**
     * Paid billing-lane suppress for the remainder of this execution.
     * Persistent global paid lock uses lockConnectionPaid (BillingExhausted only).
     * InsufficientBudgetForRequest suppresses paid siblings this request without
     * locking the connection for other profiles/workloads.
     */
    private function isPaidLaneSuppressDecision(AiFailureDecision $decision): bool
    {
        if ($decision->lockConnectionPaid) {
            return true;
        }

        return $decision->category === AiFailureClass::BillingExhausted
            || $decision->category === AiFailureClass::InsufficientBudgetForRequest;
    }

    /**
     * Free billing-lane suppress — paid routes on the same connection stay eligible.
     * Scope: connection_id + FREE lane only (OpenRouter free-models-per-day).
     */
    private function isFreeLaneSuppressDecision(AiFailureDecision $decision): bool
    {
        return $decision->suppressConnectionFree
            || $decision->scope === AiFailureScope::ConnectionFree
            || $decision->category === AiFailureClass::DailyFreeQuotaExhausted;
    }

    /** @deprecated Use isFullConnectionSuppressDecision / isPaidLaneSuppressDecision */
    private function isConnectionScopedDecision(AiFailureDecision $decision): bool
    {
        return $this->isFullConnectionSuppressDecision($decision)
            || $this->isPaidLaneSuppressDecision($decision);
    }

    private function attemptCounts(array $routingAttempts, string $result, string $detailKey): array
    {
        $counts = [];
        foreach ($routingAttempts as $row) {
            if (! is_array($row) || (string) ($row['result'] ?? '') !== $result) {
                continue;
            }
            $detail = (string) ($row[$detailKey] ?? 'unknown');
            if ($detail === '') {
                $detail = 'unknown';
            }
            $counts[$detail] = ($counts[$detail] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Pre-health funnel counts recorded by the routing target service, when available.
     *
     * @return array<string, mixed>
     */
    private function eligibilityDiagnostics(): array
    {
        try {
            return $this->targetsService()?->lastEligibilityDiagnostics() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogFunnelDiagnostics(int $userId): array
    {
        try {
            $catalog = function_exists('app') && app()->bound(AiModelCatalogFreshnessService::class)
                ? app(AiModelCatalogFreshnessService::class)
                : new AiModelCatalogFreshnessService();
            $priorities = function_exists('app') && app()->bound(AiModelPriorityService::class)
                ? app(AiModelPriorityService::class)
                : new AiModelPriorityService();
            $rows = [];
            foreach ($priorities->aiConnections($userId) as $connection) {
                if (! $connection instanceof ApiConnection) {
                    continue;
                }
                $rows[] = $catalog->diagnostics($connection, $userId);
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     */
    private function hasAttemptableNonFreeCandidate(
        int $userId,
        array $candidates,
        AiRuntimeHealthService $health,
    ): bool {
        return $this->countAttemptableNonFreeCandidates($userId, $candidates, $health) > 0;
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     */
    private function countAttemptableNonFreeCandidates(
        int $userId,
        array $candidates,
        AiRuntimeHealthService $health,
    ): int {
        $count = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->isFree) {
                continue;
            }
            if ($health->skipReason($userId, $candidate) !== null) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @param  array<int, true>  $suppressedConnections
     * @param  array<int, true>  $suppressedPaidLanes
     */
    private function hasRemainingAttemptableNonFree(
        int $userId,
        array $candidates,
        int $currentIndex,
        AiRuntimeHealthService $health,
        array $suppressedConnections,
        array $suppressedPaidLanes,
    ): bool {
        return $this->countRemainingAttemptableNonFree(
            $userId,
            $candidates,
            $currentIndex,
            $health,
            $suppressedConnections,
            $suppressedPaidLanes,
        ) > 0;
    }

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @param  array<int, true>  $suppressedConnections
     * @param  array<int, true>  $suppressedPaidLanes
     */
    private function countRemainingAttemptableNonFree(
        int $userId,
        array $candidates,
        int $currentIndex,
        AiRuntimeHealthService $health,
        array $suppressedConnections,
        array $suppressedPaidLanes,
    ): int {
        $count = 0;
        foreach ($candidates as $index => $candidate) {
            if ($index < $currentIndex || $candidate->isFree) {
                continue;
            }
            $connectionId = (int) $candidate->connection->id;
            if (isset($suppressedConnections[$connectionId]) || isset($suppressedPaidLanes[$connectionId])) {
                continue;
            }
            if ($health->skipReason($userId, $candidate) !== null) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Sibling physical routes under the same logical model that remain independently eligible.
     * A failure on one physical route must never imply siblings are exhausted.
     *
     * @param  list<RoutedAiCandidate>  $candidates
     * @param  array<int, true>  $suppressedConnections
     * @param  array<int, true>  $suppressedPaidLanes
     * @param  array<string, true>  $attemptedPhysicalRoutes
     * @param  array<int, true>  $suppressedFreeLanes
     * @return array<string, mixed>
     */
    private function siblingRouteMeta(
        array $candidates,
        int $currentIndex,
        array $suppressedConnections,
        array $suppressedPaidLanes,
        array $attemptedPhysicalRoutes,
        array $suppressedFreeLanes = [],
    ): array {
        $current = $candidates[$currentIndex] ?? null;
        if (! $current instanceof RoutedAiCandidate) {
            return [];
        }

        $logical = $current->logicalModelKey();
        $allSiblings = [];
        $eligibleSiblings = [];
        foreach ($candidates as $index => $candidate) {
            if ($candidate->logicalModelKey() !== $logical) {
                continue;
            }
            if ($candidate->physicalRouteKey() === $current->physicalRouteKey()) {
                continue;
            }
            $allSiblings[] = $candidate->physicalRouteKey();
            if ($index <= $currentIndex) {
                continue;
            }
            $connectionId = (int) $candidate->connection->id;
            if (isset($suppressedConnections[$connectionId])) {
                continue;
            }
            if (! $candidate->isFree && isset($suppressedPaidLanes[$connectionId])) {
                continue;
            }
            if ($candidate->isFree && isset($suppressedFreeLanes[$connectionId])) {
                continue;
            }
            if (isset($attemptedPhysicalRoutes[$candidate->physicalRouteKey()])) {
                continue;
            }
            $eligibleSiblings[] = $candidate->physicalRouteKey();
        }

        return [
            'sibling_physical_routes' => $allSiblings,
            'eligible_sibling_physical_routes' => $eligibleSiblings,
            'sibling_routes_remain_eligible' => $eligibleSiblings !== [],
            'logical_model_route_count' => 1 + count($allSiblings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptBudgetMeta(
        int $actualAttempts,
        int $freeAttempts,
        int $paidAttempts,
        int $effectiveMaxFreeAttempts,
        int $maxAiAttempts,
        int $maxFreeAttempts,
        int $reservedPaidSlots,
        ?string $healthBefore,
    ): array {
        return array_filter([
            'actual_attempts' => $actualAttempts,
            'free_attempts' => $freeAttempts,
            'paid_attempts' => $paidAttempts,
            'effective_max_free_attempts' => $effectiveMaxFreeAttempts,
            'max_ai_attempts' => $maxAiAttempts,
            'max_free_attempts' => $maxFreeAttempts,
            'reserved_paid_slots' => $reservedPaidSlots,
            'health_state_before' => $healthBefore,
            'eligible_before_attempt' => $healthBefore === null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function attemptLog(
        RoutedAiCandidate $candidate,
        int $attemptNumber,
        string $result,
        ?string $detail = null,
        ?int $httpStatus = null,
        array $extra = [],
    ): array {
        $status = match ($result) {
            'success' => 'SUCCESS',
            'failed' => 'FAILED',
            'skipped' => 'SKIPPED',
            default => strtoupper($result),
        };

        return array_filter(array_merge([
            'attempt' => $attemptNumber,
            'connection_id' => (int) $candidate->connection->id,
            'connection_name' => (string) $candidate->connection->name,
            'provider' => $candidate->provider,
            'model' => $candidate->model,
            'candidate_model' => $candidate->model,
            'logical_model' => $candidate->logicalModelKey(),
            'physical_route' => $candidate->physicalRouteKey(),
            'is_aggregator_route' => $candidate->isAggregatorRoute(),
            'seo_ai_model_id' => $candidate->seoAiModelId,
            'is_free' => $candidate->isFree,
            'is_free_candidate' => $candidate->isFree,
            'result' => $result,
            'status' => $status,
            'failure_class' => $result === 'failed' ? $detail : null,
            'skip_reason' => $result === 'skipped' ? $detail : null,
            'http_status' => $httpStatus,
            'eligible' => $result !== 'skipped',
            'skipped' => $result === 'skipped',
            'attempted' => $result === 'failed' || $result === 'success',
        ], $extra), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function qualityAttemptMeta(\Throwable $exception): array
    {
        $meta = [];

        if ($exception instanceof \Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated) {
            $meta['provider_terminal_reason'] = ($exception->terminalReason
                ?? \Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason::OutputTruncated)->value;
            if ($exception->providerFinishReason !== null && $exception->providerFinishReason !== '') {
                $meta['provider_finish_reason'] = $exception->providerFinishReason;
            }
            $meta['failure_code'] = $exception->failureCode->value;
        }

        if (! $exception instanceof PromptRunException) {
            return $meta;
        }

        $rules = $exception->context['quality_rules'] ?? null;
        $sample = $exception->context['quality_sample'] ?? null;
        $usage = $exception->context['token_usage'] ?? $exception->context['usage'] ?? null;
        if (is_array($usage)) {
            $terminal = $usage['provider_terminal_reason'] ?? $usage['finish_reason'] ?? null;
            if (is_string($terminal) && $terminal !== '' && ! isset($meta['provider_terminal_reason'])) {
                $normalized = (new \Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReasonNormalizer)
                    ->normalizeFromUsage($usage);
                if ($normalized !== null) {
                    $meta['provider_terminal_reason'] = $normalized->value;
                }
                $meta['provider_finish_reason'] = (string) ($usage['finish_reason'] ?? $terminal);
            }
        }

        return array_filter(array_merge($meta, [
            'quality_rules' => is_array($rules) ? array_values(array_map('strval', $rules)) : null,
            'quality_sample' => is_string($sample) && $sample !== '' ? mb_substr($sample, 0, 120) : null,
        ]), static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function routeCapacityPolicy(): AiRouteCapacityPolicy
    {
        if (function_exists('app')) {
            try {
                return app(AiRouteCapacityPolicy::class);
            } catch (\Throwable) {
            }
        }

        return new AiRouteCapacityPolicy;
    }

    private function extractFailureUsage(\Throwable $exception): ?array
    {
        if (! $exception instanceof PromptRunException) {
            return null;
        }

        $usage = $exception->context['token_usage'] ?? $exception->context['usage'] ?? null;

        return is_array($usage) && $usage !== [] ? $usage : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionPaidLockAttemptMeta(RoutedAiCandidate $candidate, ?string $skipReason): array
    {
        if ($skipReason !== 'connection_paid_locked') {
            return [];
        }

        $reasons = app(ConnectionPaidLockService::class)->reasonValues($candidate->connection);

        return [
            'connection_paid_locked' => true,
            'lock_reasons' => $reasons,
            'paid_lock_reasons' => $reasons,
        ];
    }

    private function applyLegacyHealthSideEffects(RoutedAiCandidate $candidate, AiFailureDecision $decision): void
    {
        if (! $decision->affectsRuntimeHealth) {
            return;
        }

        if ($candidate->seoAiModelId === null) {
            return;
        }

        if ($decision->markModelUnavailable) {
            $this->markModelUnavailableForAutoRouting($candidate->seoAiModelId, $decision->safeMessage);
        }
    }

    private function failureClassifier(): AiProviderFailureClassifier
    {
        return function_exists('app')
            ? app(AiProviderFailureClassifier::class)
            : new AiProviderFailureClassifier();
    }

    private function runtimeHealth(): AiRuntimeHealthService
    {
        return function_exists('app')
            ? app(AiRuntimeHealthService::class)
            : new AiRuntimeHealthService();
    }

    private function resilienceSettings(): AiResilienceSettingsService
    {
        return function_exists('app')
            ? app(AiResilienceSettingsService::class)
            : new AiResilienceSettingsService();
    }

    private function routingContextResolver(): AiRoutingContextResolver
    {
        return function_exists('app') && app()->bound(AiRoutingContextResolver::class)
            ? app(AiRoutingContextResolver::class)
            : new AiRoutingContextResolver();
    }

    private function fallbackAreaResolver(): AiFallbackAreaResolver
    {
        return function_exists('app') && app()->bound(AiFallbackAreaResolver::class)
            ? app(AiFallbackAreaResolver::class)
            : new AiFallbackAreaResolver();
    }

    private function candidatePlanner(): AiCandidatePlanner
    {
        return function_exists('app') && app()->bound(AiCandidatePlanner::class)
            ? app(AiCandidatePlanner::class)
            : new AiCandidatePlanner();
    }

    /**
     * Resolve SECONDARY area candidates for FREE-FIRST paid fallback.
     * Empty when PAID-FIRST, FreeOnly, media (no secondary), or secondary unavailable.
     *
     * @param  list<RoutedAiCandidate>  $primaryCandidates
     * @return list<RoutedAiCandidate>
     */
    private function resolveSecondaryPaidLaneCandidates(
        string $profile,
        ?AiExecutionProfile $parsed,
        AiRoutingContext $context,
        array $primaryCandidates,
        AiExecutionRoutingMode $routingMode,
        int $userId,
        AiRuntimeHealthService $health,
        ?\Omnichannel\Addons\AiPrompt\Support\AiModelArea $primaryArea,
        ?\Omnichannel\Addons\AiPrompt\Support\AiModelArea $secondaryArea,
    ): array {
        if ($parsed === null || $primaryArea === null || $secondaryArea === null) {
            return [];
        }
        if (! $routingMode->allowsPaidRoutes() || $context->isFreeOnly()) {
            return [];
        }

        $firstUsable = null;
        foreach ($primaryCandidates as $candidate) {
            if ($health->skipReason($userId, $candidate) !== null) {
                continue;
            }
            $firstUsable = $candidate;
            break;
        }
        if ($firstUsable === null || ! $firstUsable->isFree) {
            return [];
        }

        // SECONDARY_PAID always reads the paid text area for this profile — never Free Models.
        $targets = app(AiRoutingTargetService::class);
        $paid = $targets->paidAreaCandidates($userId, $parsed, $context);
        $paid = array_values(array_filter(
            $paid,
            static fn (RoutedAiCandidate $c): bool => ! $c->isFree
                && $health->skipReason($userId, $c) === null,
        ));

        return $paid;
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    private function resolveSecondaryLaneTransitionReason(array $routingAttempts): string
    {
        foreach (array_reverse($routingAttempts) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $failureClass = (string) ($row['failure_class'] ?? '');
            $skipReason = (string) ($row['skip_reason'] ?? '');
            if ($failureClass === AiFailureClass::DailyFreeQuotaExhausted->value
                || $skipReason === 'free_lane_suppressed'
                || ($row['free_lane_suppressed'] ?? false) === true) {
                return 'daily_free_quota_exhausted';
            }
            if ($skipReason === 'free_attempt_budget_exhausted') {
                return 'free_attempt_budget_exhausted';
            }
        }

        $hadFreeAttempt = false;
        foreach ($routingAttempts as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['is_free'] ?? false) === true && in_array(($row['result'] ?? ''), ['failed', 'success'], true)) {
                $hadFreeAttempt = true;
                break;
            }
        }

        if (! $hadFreeAttempt) {
            return 'free_routes_unavailable';
        }

        return 'primary_free_exhausted';
    }

    /** @deprecated Use AiProviderFailureClassifier via executeWithProfile resilience loop. */
    public function isInfrastructureFailure(string $message): bool
    {
        $decision = $this->failureClassifier()->classify(new PromptRunException($message));

        return $decision->fallbackAllowed();
    }

    private function legacyCompatibleCandidate(AiExecutionProfile $profile, ApiConnection $connection): ?RoutedAiCandidate
    {
        if ((string) $connection->status !== 'active' || blank($connection->api_key)) {
            return null;
        }

        $models = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->get();

        foreach ($models as $model) {
            $key = (string) $model->raw_model_name;
            if (! $this->capabilityRegistry->satisfiesAll($connection, $key, $profile->requiredCapabilityKeys())) {
                continue;
            }

            return new RoutedAiCandidate(
                profile: $profile->value,
                connection: $connection,
                provider: (string) $connection->provider,
                model: $key,
                capabilities: $this->capabilityRegistry->capabilitiesFor($connection, $key),
                priority: 99,
                seoAiModelId: (int) $model->id,
                legacyFallback: true,
            );
        }

        return null;
    }

    private function targetsService(): ?AiRoutingTargetService
    {
        if ($this->routingTargetService instanceof AiRoutingTargetService) {
            return $this->routingTargetService;
        }

        return function_exists('app') ? app(AiRoutingTargetService::class) : null;
    }

    private function bootstrapService(): ?AiRoutingBootstrapService
    {
        if ($this->routingBootstrapService instanceof AiRoutingBootstrapService) {
            return $this->routingBootstrapService;
        }

        return function_exists('app') ? app(AiRoutingBootstrapService::class) : null;
    }

    /**
     * Đồng bộ model từ Google Generative Language API.
     *
     * Authority: hybrid — provider /models is authoritative for returned models;
     * curated image models (Imagen / Nano Banana) may coexist because they are often
     * absent from GET /models. On provider failure / suspicious empty: keep LKG
     * (do not reactivate static text seeds).
     */
    public function syncGeminiModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== 'gemini') {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withQueryParameters(['key' => $connection->api_key])
                ->get(app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                    ->httpBaseUrl($connection).'/v1beta/models');

            if (! $response->successful()) {
                logger()->warning('syncGeminiModels API list failed; keeping last-known-good', [
                    'connection_id' => $connectionId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $models = $response->json('models', []);
            if (! is_array($models) || $models === []) {
                logger()->warning('syncGeminiModels empty/malformed catalog; keeping last-known-good', [
                    'connection_id' => $connectionId,
                ]);

                return false;
            }

            $seenRaw = [];
            foreach ($models as $model) {
                if (! is_array($model)) {
                    continue;
                }

                $rawName = str_replace('models/', '', (string) ($model['name'] ?? ''));
                if ($rawName === '') {
                    continue;
                }

                $classified = $this->classifyGeminiModel(
                    $rawName,
                    (array) ($model['supportedGenerationMethods'] ?? []),
                );

                if ($classified === null) {
                    continue;
                }

                $seenRaw[] = $rawName;

                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    $this->mergeSyncPayload($connectionId, $rawName, [
                        'category' => $classified['category'],
                        'display_name' => (string) ($model['displayName'] ?? $rawName),
                        'priority' => $classified['priority'],
                        'status' => SeoAiModel::STATUS_ACTIVE,
                        'capabilities' => $this->capabilitiesWithResolved($rawName, [
                            'supportedGenerationMethods' => $model['supportedGenerationMethods'] ?? [],
                            'source' => 'gemini',
                            'catalog_source' => 'provider',
                        ]),
                        'last_error' => null,
                    ]),
                );
            }

            $seenRaw = array_values(array_unique($seenRaw));
            if ($seenRaw === []) {
                logger()->warning('syncGeminiModels classified zero models; keeping last-known-good', [
                    'connection_id' => $connectionId,
                ]);

                return false;
            }

            // Hybrid curated layer: image models often missing from GET /models.
            $curatedImage = $this->ensureGeminiCuratedImageModels($connectionId);
            $activeSet = array_values(array_unique(array_merge($seenRaw, $curatedImage)));
            $this->deactivateMissingModels($connectionId, $activeSet);

            return true;
        } catch (Throwable $exception) {
            logger()->error('syncGeminiModels failed; keeping last-known-good', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Đồng bộ model Claude từ Anthropic API.
     *
     * Authority: provider. Successful discovery deactivates missing local models.
     * On failure / empty: keep last-known-good (do not fabricate curated ACTIVE catalog).
     */
    public function syncClaudeModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== 'claude') {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $connection->api_key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->get('https://api.anthropic.com/v1/models');

            if (! $response->successful()) {
                logger()->warning('syncClaudeModels API list failed; keeping last-known-good', [
                    'connection_id' => $connectionId,
                    'status' => $response->status(),
                ]);

                return false;
            }

            $models = $response->json('data', []);
            if (! is_array($models) || $models === []) {
                logger()->warning('syncClaudeModels empty catalog; keeping last-known-good', [
                    'connection_id' => $connectionId,
                ]);

                return false;
            }

            $seenRaw = [];

            foreach ($models as $model) {
                if (! is_array($model)) {
                    continue;
                }

                $rawName = (string) ($model['id'] ?? $model['name'] ?? '');
                if ($rawName === '') {
                    continue;
                }

                $classified = $this->classifyClaudeModel($rawName);
                if ($classified === null) {
                    continue;
                }

                $seenRaw[] = $rawName;

                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    [
                        'category' => $classified['category'],
                        'display_name' => (string) ($model['display_name'] ?? $model['displayName'] ?? $rawName),
                        'priority' => $classified['priority'],
                        'status' => SeoAiModel::STATUS_ACTIVE,
                        'capabilities' => $this->capabilitiesWithResolved($rawName, [
                            'source' => 'anthropic',
                            'catalog_source' => 'provider',
                        ]),
                        'last_error' => null,
                    ],
                );
            }

            $seenRaw = array_values(array_unique($seenRaw));
            if ($seenRaw === []) {
                return false;
            }

            $this->deactivateMissingModels($connectionId, $seenRaw);

            return true;
        } catch (Throwable $exception) {
            logger()->error('syncClaudeModels failed; keeping last-known-good', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function syncModelsForConnection(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null) {
            return false;
        }

        return match ($connection->provider) {
            'gemini' => $this->syncGeminiModels($connectionId),
            'claude' => $this->syncClaudeModels($connectionId),
            ApiConnectionProviders::DEEPSEEK => $this->syncDeepSeekModels($connectionId),
            ApiConnectionProviders::OPENROUTER => $this->syncOpenAiCompatibleModels($connectionId),
            default => false,
        };
    }

    public function getActiveModel(int $connectionId, string $category): ?SeoAiModel
    {
        return SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('category', $category)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    public function handleModelExhausted(int $modelId, string $errorMessage): void
    {
        $failedModel = SeoAiModel::query()->find($modelId);
        if ($failedModel === null) {
            return;
        }

        $failedModel->update([
            'status' => SeoAiModel::STATUS_EXHAUSTED,
            'last_error' => mb_substr($errorMessage, 0, 2000),
        ]);

        logger()->warning('AI model exhausted, failover next', [
            'planner_model' => $failedModel->raw_model_name,
            'category' => $failedModel->category,
        ]);
    }

    public function markModelUnavailableForAutoRouting(int $modelId, string $errorMessage): void
    {
        $failedModel = SeoAiModel::query()->find($modelId);
        if ($failedModel === null) {
            return;
        }

        $capabilities = is_array($failedModel->capabilities) ? $failedModel->capabilities : [];
        $failedModel->update([
            'capabilities' => GeminiModelVersionPolicy::markCapabilitiesUnavailable($capabilities, $errorMessage),
            'last_error' => mb_substr($errorMessage, 0, 2000),
        ]);

        logger()->warning('AI model marked unavailable for auto-routing', [
            'planner_model' => $failedModel->raw_model_name,
            'disabled_reason' => GeminiModelVersionPolicy::REASON_PROVIDER_UNAVAILABLE,
        ]);
    }

    /**
     * Category cho text path theo RenderingPreference.
     * Image path không dùng — ImageRoutingStrategy.
     *
     * @deprecated prompts.model_category không còn điều khiển routing
     */
    public function resolveCategoryForPrompt(SeoPrompt $prompt, string $toolType = 'default'): string
    {
        $connection = $prompt->aiConnection;
        $provider = $connection !== null ? (string) $connection->provider : 'gemini';

        if (ImageToolType::fromMixed($toolType)->isImagePipeline()) {
            return AiModelCategory::IMAGEN_PRO;
        }

        $preference = app(SeoCreateArticleSettingsService::class)->getRenderingPreference();

        return match ($provider) {
            'claude' => match ($preference) {
                RenderingPreference::CostFirst => AiModelCategory::CLAUDE_HAIKU,
                RenderingPreference::QualityFirst => AiModelCategory::CLAUDE_OPUS,
                RenderingPreference::Balanced => AiModelCategory::CLAUDE_SONNET,
            },
            ApiConnectionProviders::DEEPSEEK => AiModelCategory::DEEPSEEK_CHAT,
            default => match ($preference) {
                RenderingPreference::QualityFirst => AiModelCategory::GEMINI_PRO,
                RenderingPreference::CostFirst,
                RenderingPreference::Balanced => AiModelCategory::GEMINI_FLASH,
            },
        };
    }

    /**
     * Thực thi callable với failover theo category.
     *
     * @param  callable(string $rawModelName, ?int $seoAiModelId): array{0: string, 1: array<string, mixed>|null}  $executor
     * @return array{0: string, 1: array<string, mixed>|null, 2: string, 3: ?int}
     */
    public function executeWithFailover(
        ApiConnection $connection,
        string $category,
        callable $executor,
        int $attempt = 0,
        ?int $excludeModelId = null,
    ): array {
        if ($attempt >= self::MAX_FAILOVER_ATTEMPTS) {
            throw new PromptRunException('Đã thử toàn bộ model dự phòng trong nhóm «'.$category.'» nhưng đều thất bại.');
        }

        if (! AiModelCategory::matchesProvider($category, (string) $connection->provider)) {
            throw new PromptRunException(
                'Nhóm model «'.$category.'» không tương thích với kết nối '.$connection->provider.'.',
            );
        }

        $activeModel = $this->getNextActiveModel((int) $connection->id, $category, $excludeModelId);

        if ($activeModel !== null) {
            $rawName = (string) $activeModel->raw_model_name;
            $modelId = (int) $activeModel->id;

            try {
                [$output, $usage] = $executor($rawName, $modelId);

                return [$output, $usage, $rawName, $modelId];
            } catch (Throwable $exception) {
                $decision = $this->failureClassifier()->classify($exception);
                if (! $decision->shouldContinueRouting()) {
                    throw $exception instanceof PromptRunException
                        ? $exception
                        : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
                }

                if ($decision->markModelUnavailable || $decision->category === AiFailureClass::ModelNotFound) {
                    $this->markModelUnavailableForAutoRouting($modelId, $exception->getMessage());
                } elseif (
                    $decision->category === AiFailureClass::BillingExhausted
                    || $decision->category === AiFailureClass::InsufficientBudgetForRequest
                    || $decision->category === AiFailureClass::RateLimited
                ) {
                    $this->handleModelExhausted($modelId, $exception->getMessage());
                }

                logger()->warning('Planner model failed, failover next', [
                    'planner_model' => $rawName,
                    'failure_class' => $decision->category->value,
                    'fallback_allowed' => $decision->fallbackAllowed(),
                    'error' => $exception->getMessage(),
                ]);

                return $this->executeWithFailover($connection, $category, $executor, $attempt + 1, $modelId);
            }
        }

        $fallbackRaw = $this->fallbackRawModelName($connection, $category);
        if ($fallbackRaw === '') {
            throw new PromptRunException(
                'Không có model active trong DB cho nhóm «'.$category.'». Vào Cấu hình AI → Đồng bộ model.',
            );
        }

        [$output, $usage] = $executor($fallbackRaw, null);

        return [$output, $usage, $fallbackRaw, null];
    }

    public function isQuotaOrRateLimitError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($message, '429')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'rate_limit')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'insufficient')
            || str_contains($lower, 'billing');
    }

    /**
     * @param  list<string>  $methods
     * @return array{category: string, priority: int}|null
     */
    private function classifyGeminiModel(string $rawName, array $methods): ?array
    {
        $lower = strtolower($rawName);
        $methods = array_map('strtolower', $methods);

        if (str_contains($lower, 'embedding') || str_contains($lower, 'tts') || str_contains($lower, 'veo')
            || str_contains($lower, 'lyria') || str_contains($lower, 'computer-use') || str_contains($lower, 'robotics')) {
            return null;
        }

        if (str_contains($lower, 'imagen') || in_array('predict', $methods, true)) {
            $priority = 100;
            if (str_contains($lower, 'ultra')) {
                $priority = 220;
            } elseif (str_contains($lower, 'fast')) {
                $priority = 200;
            } elseif (preg_match('/imagen-4/', $lower)) {
                $priority = 210;
            } elseif (str_contains($lower, '3.0')) {
                $priority = 150;
            }

            return ['category' => AiModelCategory::IMAGEN_PRO, 'priority' => $priority];
        }

        if (str_contains($lower, 'image') || str_contains($lower, 'banana')) {
            $priority = $this->versionPriority($lower, 180);

            return ['category' => AiModelCategory::IMAGEN_PRO, 'priority' => $priority];
        }

        if (! in_array('generatecontent', $methods, true) && $methods !== []) {
            return null;
        }

        if (str_contains($lower, 'pro') && ! str_contains($lower, 'flash')) {
            return ['category' => AiModelCategory::GEMINI_PRO, 'priority' => $this->versionPriority($lower, 120)];
        }

        if (str_contains($lower, 'flash') || str_contains($lower, 'lite')) {
            return ['category' => AiModelCategory::GEMINI_FLASH, 'priority' => $this->versionPriority($lower, 100)];
        }

        if (preg_match('/gemini-[\d.]+/', $lower)) {
            return ['category' => AiModelCategory::GEMINI_FLASH, 'priority' => 80];
        }

        return null;
    }

    /**
     * @return array{category: string, priority: int}|null
     */
    private function classifyClaudeModel(string $rawName): ?array
    {
        $lower = strtolower($rawName);

        if (str_contains($lower, 'opus')) {
            return ['category' => AiModelCategory::CLAUDE_OPUS, 'priority' => $this->versionPriority($lower, 200)];
        }

        if (str_contains($lower, 'sonnet')) {
            return ['category' => AiModelCategory::CLAUDE_SONNET, 'priority' => $this->versionPriority($lower, 150)];
        }

        if (str_contains($lower, 'haiku')) {
            return ['category' => AiModelCategory::CLAUDE_HAIKU, 'priority' => $this->versionPriority($lower, 100)];
        }

        return null;
    }

    private function versionPriority(string $rawName, int $base): int
    {
        if (preg_match('/3\.5|3-5/', $rawName)) {
            return $base + 80;
        }

        if (preg_match('/3\.1|3-1/', $rawName)) {
            return $base + 70;
        }

        if (preg_match('/3\.0|3-0|\b3-pro|\b3-flash/', $rawName)) {
            return $base + 60;
        }

        if (preg_match('/2\.5|2-5/', $rawName)) {
            return $base + 50;
        }

        if (preg_match('/2\.0|2-0/', $rawName)) {
            return $base + 40;
        }

        if (preg_match('/1\.5|1-5/', $rawName)) {
            return $base + 20;
        }

        return $base;
    }

    private function getNextActiveModel(int $connectionId, string $category, ?int $excludeModelId): ?SeoAiModel
    {
        $query = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('category', $category)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if ($excludeModelId !== null) {
            $query->where('id', '!=', $excludeModelId);
        }

        foreach ($query->get() as $model) {
            $capabilities = is_array($model->capabilities) ? $model->capabilities : [];
            if (GeminiModelVersionPolicy::isEligibleForAutoRouting((string) $model->raw_model_name, $capabilities)) {
                return $model;
            }
        }

        return null;
    }

    private function fallbackRawModelName(ApiConnection $connection, string $category): string
    {
        $legacyRaw = trim((string) ($connection->default_model ?? ''));
        if (
            $legacyRaw !== ''
            && ! AiModelCategory::isValid($legacyRaw)
            && $this->categoryForLegacyRaw($legacyRaw) === $category
            && GeminiModelVersionPolicy::isEligibleForAutoRouting($legacyRaw)
        ) {
            return $legacyRaw;
        }

        return match ($category) {
            AiModelCategory::IMAGEN_PRO => 'gemini-3.1-flash-image-preview',
            AiModelCategory::GEMINI_PRO => 'gemini-3.1-pro-preview',
            AiModelCategory::GEMINI_FLASH => 'gemini-3-flash-preview',
            AiModelCategory::CLAUDE_OPUS => 'claude-opus-4-20250514',
            AiModelCategory::CLAUDE_SONNET => 'claude-sonnet-4-20250514',
            AiModelCategory::CLAUDE_HAIKU => 'claude-3-5-haiku-20241022',
            // DeepSeek: never hardcode retired aliases — require active catalog / routing model.
            AiModelCategory::DEEPSEEK_CHAT, AiModelCategory::DEEPSEEK_REASONER => '',
            default => '',
        };
    }

    private function categoryForLegacyRaw(string $raw): string
    {
        if (GoogleAiModelRegistry::isImagenModel($raw) || GoogleAiModelRegistry::isGeminiNativeImageModel($raw)) {
            return AiModelCategory::IMAGEN_PRO;
        }

        $lower = strtolower($raw);
        if (str_contains($lower, 'opus')) {
            return AiModelCategory::CLAUDE_OPUS;
        }

        if (str_contains($lower, 'haiku')) {
            return AiModelCategory::CLAUDE_HAIKU;
        }

        if (str_contains($lower, 'sonnet')) {
            return AiModelCategory::CLAUDE_SONNET;
        }

        if (str_contains($lower, 'pro')) {
            return AiModelCategory::GEMINI_PRO;
        }

        return AiModelCategory::GEMINI_FLASH;
    }

    /**
     * @param  list<string>  $activeRawNames
     */
    private function deactivateMissingModels(int $connectionId, array $activeRawNames): void
    {
        if ($activeRawNames === []) {
            return;
        }

        SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->whereNotIn('raw_model_name', $activeRawNames)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->update(['status' => SeoAiModel::STATUS_INACTIVE]);
    }

    /**
     * Curated image models often absent from Gemini GET /models (hybrid authority layer).
     * Does NOT seed static text model aliases — those must come from provider discovery.
     *
     * @return list<string>
     */
    private function ensureGeminiCuratedImageModels(int $connectionId): array
    {
        $catalog = [
            ['imagen-4.0-fast-generate-001', 'Imagen 4 Fast Generate', AiModelCategory::IMAGEN_PRO, 230, ['predict']],
            ['imagen-4.0-generate-001', 'Imagen 4 Generate', AiModelCategory::IMAGEN_PRO, 220, ['predict']],
            ['imagen-4.0-ultra-generate-001', 'Imagen 4 Ultra Generate', AiModelCategory::IMAGEN_PRO, 225, ['predict']],
            ['gemini-3.1-flash-image-preview', 'Nano Banana 2 (Gemini 3.1 Flash Image)', AiModelCategory::IMAGEN_PRO, 210, ['generateContent']],
            ['gemini-3-pro-image-preview', 'Nano Banana Pro (Gemini 3 Pro Image)', AiModelCategory::IMAGEN_PRO, 205, ['generateContent']],
            ['gemini-2.5-flash-image', 'Nano Banana (Gemini 2.5 Flash Image)', AiModelCategory::IMAGEN_PRO, 190, ['generateContent']],
            ['gemini-2.5-pro-image', 'Nano Banana Pro (Gemini 2.5 Pro Image)', AiModelCategory::IMAGEN_PRO, 188, ['generateContent']],
        ];

        $seeded = [];

        foreach ($catalog as [$raw, $label, $category, $priority, $methods]) {
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $raw,
                ],
                $this->mergeSyncPayload($connectionId, $raw, [
                    'category' => $category,
                    'display_name' => $label,
                    'priority' => $priority,
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => $this->capabilitiesWithResolved($raw, [
                        'supportedGenerationMethods' => $methods,
                        'source' => 'catalog',
                        'catalog_source' => 'curated',
                    ]),
                    'last_error' => null,
                ]),
            );

            $seeded[] = $raw;
        }

        return $seeded;
    }

    /**
     * @deprecated Use {@see ensureGeminiCuratedImageModels()} — text models must not be
     *             statically seeded after authoritative provider sync.
     *
     * @return list<string>
     */
    private function seedGeminiCatalogModels(int $connectionId): array
    {
        return $this->ensureGeminiCuratedImageModels($connectionId);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function capabilitiesWithResolved(string $rawName, array $base): array
    {
        return (new ImageCapabilityResolver())->mergeResolvedIntoCapabilities($rawName, $base);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeSyncPayload(int $connectionId, string $rawName, array $payload): array
    {
        $existing = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('raw_model_name', $rawName)
            ->first();
        if (! $existing instanceof SeoAiModel) {
            return $payload;
        }
        $payload['priority'] = (int) ($existing->priority ?: ($payload['priority'] ?? 100));
        $incoming = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
        $payload['capabilities'] = (new AiModelPriorityService())->copyAreas(
            is_array($existing->capabilities) ? $existing->capabilities : [],
            $incoming,
        );
        if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')
            && ! array_key_exists('is_hidden', $payload)) {
            $payload['is_hidden'] = (bool) ($existing->getAttribute('is_hidden') ?? false);
        }

        return $payload;
    }

    /**
     * @return array{connections: list<array<string, mixed>>, total_models: int, last_synced_at: ?string}
     */
    public function overviewForUser(?int $userId = null): array
    {
        $userId ??= (int) auth()->id();

        $connections = ApiConnection::query()
            ->with(['seoAiModels' => static fn ($query) => $query
                ->orderByDesc('priority')
                ->orderBy('category')
                ->orderBy('raw_model_name')])
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderBy('name')
            ->get();

        $rows = [];
        $total = 0;
        $latestSync = null;

        $resolver = new ImageCapabilityResolver();
        $labels = new \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter();
        $catalog = new AiModelFamilyCatalog();
        $adminEnabledUnknown = array_fill_keys(
            app(SeoCreateArticleSettingsService::class)->getAdminEnabledUnknownImageModels(),
            true,
        );

        foreach ($connections as $connection) {
            $models = [];
            $groups = [
                'text' => [],
                'image' => [],
                'image_typography' => [],
                'video' => [],
                'unknown' => [],
            ];

            foreach ($connection->seoAiModels as $model) {
                $total++;
                $capabilities = is_array($model->capabilities) ? $model->capabilities : [];
                $resolved = $resolver->resolve((string) $model->raw_model_name, $capabilities);
                $group = ImageCapability::displayGroupForCapabilities($resolved);
                $slug = GoogleAiModelRegistry::normalizeSlug((string) $model->raw_model_name);
                $routing = GeminiModelVersionPolicy::routingDecision($slug, $capabilities);
                $family = $catalog->familyForModelId((string) $model->raw_model_name);
                $showNormal = $family !== null
                    && $model->status === SeoAiModel::STATUS_ACTIVE
                    && ($routing['routing_status'] ?? '') !== 'disabled'
                    && $group !== 'unknown';
                $row = [
                    'id' => $model->id,
                    'category' => $model->category,
                    'category_label' => AiModelCategory::promptSelectOptions()[$model->category] ?? $model->category,
                    'capability_group' => $group,
                    'capabilities_resolved' => $resolved,
                    'raw_model_name' => $model->raw_model_name,
                    'display_name' => $labels->normal((string) $model->raw_model_name, (string) $model->display_name),
                    'family_key' => $family?->familyKey,
                    'show_in_normal' => $showNormal,
                    'priority' => $model->priority,
                    'status' => $model->status,
                    'routing_status' => $routing['routing_status'],
                    'disabled_reason' => $routing['disabled_reason'],
                    'last_error' => $model->last_error,
                    'admin_enabled_unknown' => isset($adminEnabledUnknown[$slug]),
                    'updated_at' => $model->updated_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                ];
                $models[] = $row;
                $groups[$group][] = $row;

                if ($model->updated_at !== null
                    && ($latestSync === null || $model->updated_at->gt($latestSync))) {
                    $latestSync = $model->updated_at;
                }
            }

            $rows[] = [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider,
                'status' => $connection->status,
                'model_count' => count($models),
                'models' => $models,
                'groups' => $groups,
            ];
        }

        return [
            'connections' => $rows,
            'total_models' => $total,
            'last_synced_at' => $latestSync !== null ? SystemDateTime::formatDateTime($latestSync) : null,
            'capability_groups' => ImageCapability::displayGroups(),
        ];
    }

    public function toggleAdminEnabledUnknownImageModel(string $rawModelName, bool $enabled): void
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $slug = GoogleAiModelRegistry::normalizeSlug($rawModelName);
        if ($slug === '') {
            return;
        }

        $current = $settings->getAdminEnabledUnknownImageModels();
        if ($enabled) {
            $current[] = $slug;
        } else {
            $current = array_values(array_filter($current, static fn (string $item): bool => $item !== $slug));
        }

        $bag = $settings->getSettings();
        $bag[SeoCreateArticleSettingsService::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] = array_values(array_unique($current));
        $settings->saveSettings($bag);
    }

    /**
     * @return array{ok: int, failed: int, messages: list<string>}
     */
    public function syncAllConnectionsForUser(?int $userId = null): array
    {
        $userId ??= (int) auth()->id();

        $connections = ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->get();

        $ok = 0;
        $failed = 0;
        $messages = [];

        foreach ($connections as $connection) {
            if (blank($connection->api_key)) {
                $failed++;
                $messages[] = $connection->name.': thiếu API Key';

                continue;
            }

            if ($this->syncModelsForConnection((int) $connection->id)) {
                $ok++;
            } else {
                $failed++;
                $messages[] = $connection->name.': đồng bộ thất bại (kiểm tra log)';
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'messages' => $messages];
    }

    public function syncOpenAiCompatibleModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null) {
            return false;
        }
        $provider = (string) $connection->provider;
        if (! in_array($provider, [ApiConnectionProviders::OPENROUTER, ApiConnectionProviders::DEEPSEEK], true)
            && $provider !== 'openai_compatible') {
            return false;
        }
        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $adapter = function_exists('app')
                ? app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter::class)
                : new \Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter();
            $catalog = new AiModelFamilyCatalog();
            $seenRaw = [];
            foreach ($adapter->listModels($connection) as $row) {
                $rawName = (string) ($row['id'] ?? '');
                if ($rawName === '' || MalformedAiModelRepairService::isMalformedProviderModelId($rawName)) {
                    continue;
                }
                $seenRaw[] = $rawName;
                $existing = SeoAiModel::query()
                    ->where('api_connection_id', $connectionId)
                    ->where('raw_model_name', $rawName)
                    ->first();
                $family = $catalog->familyForModelId($rawName);
                $hidden = $existing instanceof SeoAiModel
                    ? (bool) ($existing->getAttribute('is_hidden') ?? false)
                    : true;
                $category = $family !== null
                    ? ($family->modality === 'image' ? AiModelCategory::IMAGEN_PRO : AiModelCategory::GEMINI_FLASH)
                    : AiModelCategory::GEMINI_FLASH;
                if (str_contains(strtolower($rawName), 'reason')) {
                    $category = AiModelCategory::DEEPSEEK_REASONER;
                }
                $payload = $this->mergeSyncPayload($connectionId, $rawName, [
                    'category' => $existing?->category ?: $category,
                    'display_name' => (string) ($row['display_name'] ?? $rawName),
                    'priority' => $existing?->priority ?: 100,
                    'status' => $existing?->status ?: SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => [
                        'source' => $provider,
                        'language_suitability' => 'unknown',
                        'provider_metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                        'resolved' => $this->capabilityRegistry->capabilitiesFor($connection, $rawName),
                    ],
                    'last_error' => null,
                ]);
                if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')) {
                    $payload['is_hidden'] = $hidden;
                }
                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    $payload,
                );
            }
            $seenRaw = array_values(array_unique($seenRaw));
            if ($provider === ApiConnectionProviders::OPENROUTER) {
                $this->upsertOpenRouterFreeRouter($connection);
                $seenRaw[] = OpenRouterModelEconomics::FREE_ROUTER_ID;
                $seenRaw = array_values(array_unique($seenRaw));
            }
            if ($seenRaw === []) {
                return false;
            }
            $this->deactivateMissingModels($connectionId, $seenRaw);
            if ($provider === ApiConnectionProviders::OPENROUTER) {
                (new AiModelPrimaryTypeClassifier())->classifyConnection($connection);
                try {
                    $pool = new OpenRouterFreePoolService();
                    $fresh = $connection->fresh() ?? $connection;
                    $pool->refreshCatalogSnapshot($fresh);
                    $pool->ensureRouterAnchors((int) ($connection->user_id ?: 0));
                    (new OpenRouterFreePoolHealthService())->markCatalogSynced($fresh, true);
                } catch (\Throwable) {
                }
            }

            return true;
        } catch (\Throwable $exception) {
            logger()->error('syncOpenAiCompatibleModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            try {
                $failed = ApiConnection::query()->find($connectionId);
                if ($failed !== null && (string) $failed->provider === ApiConnectionProviders::OPENROUTER) {
                    (new OpenRouterFreePoolHealthService())->markCatalogSynced($failed, false);
                }
            } catch (\Throwable) {
            }

            return false;
        }
    }

    private function upsertOpenRouterFreeRouter(ApiConnection $connection): void
    {
        $raw = OpenRouterModelEconomics::FREE_ROUTER_ID;
        $existing = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('raw_model_name', $raw)
            ->first();
        SeoAiModel::query()->updateOrCreate(
            [
                'api_connection_id' => (int) $connection->id,
                'raw_model_name' => $raw,
            ],
            $this->mergeSyncPayload((int) $connection->id, $raw, [
                'category' => AiModelCategory::GEMINI_FLASH,
                'display_name' => 'OpenRouter Free Pool',
                'priority' => $existing?->priority ?: 999,
                'status' => $existing?->status ?: SeoAiModel::STATUS_ACTIVE,
                'capabilities' => [
                    'source' => ApiConnectionProviders::OPENROUTER,
                    'provider_metadata' => [
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                        'architecture' => ['modality' => 'text->text'],
                    ],
                    'resolved' => [
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::TextGenerate->value,
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::TextReasoning->value,
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::StructuredOutput->value,
                    ],
                ],
                'last_error' => null,
            ]),
        );
    }

    /**
     * Sync DeepSeek catalog from provider GET /models.
     *
     * Successful provider response is authoritative: models absent from the response
     * are marked inactive. Historical rows are retained but not reactivated.
     * On provider failure / empty response: keep last-known-good catalog (no legacy seed).
     */
    public function syncDeepSeekModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== ApiConnectionProviders::DEEPSEEK) {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        $client = $this->deepSeekClient ?? (function_exists('app') ? app(DeepSeekChatClient::class) : new DeepSeekChatClient());

        try {
            $providerModels = $client->listModels($connection);
        } catch (Throwable $exception) {
            logger()->error('syncDeepSeekModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);

            // Keep last-known-good catalog — do not fabricate legacy aliases.
            return false;
        }

        if ($providerModels === []) {
            logger()->warning('syncDeepSeekModels empty provider catalog; keeping last-known-good', [
                'connection_id' => $connectionId,
            ]);

            return false;
        }

        $seenRaw = [];
        foreach ($providerModels as $row) {
            $rawName = (string) ($row['id'] ?? '');
            if ($rawName === '' || MalformedAiModelRepairService::isMalformedProviderModelId($rawName)) {
                continue;
            }
            $classified = $this->classifyDeepSeekModel($rawName);
            if ($classified === null) {
                continue;
            }
            $seenRaw[] = $rawName;
            $displayName = trim((string) ($row['display_name'] ?? ''));
            if ($displayName === '' || strcasecmp($displayName, 'deepseek') === 0) {
                $displayName = $this->deepSeekDisplayName($rawName);
            }
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $rawName,
                ],
                $this->mergeSyncPayload($connectionId, $rawName, [
                    'category' => $classified['category'],
                    'display_name' => $displayName,
                    'priority' => $classified['priority'],
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => [
                        'source' => 'deepseek',
                        'resolved' => $this->capabilityRegistry->capabilitiesFor($connection, $rawName),
                    ],
                    'last_error' => null,
                ]),
            );
        }

        $seenRaw = array_values(array_unique($seenRaw));
        if ($seenRaw === []) {
            return false;
        }

        $this->deactivateMissingModels($connectionId, $seenRaw);

        return true;
    }

    /**
     * @return array{category: string, priority: int}|null
     */
    private function classifyDeepSeekModel(string $rawName): ?array
    {
        $lower = strtolower($rawName);
        if (! str_starts_with($lower, 'deepseek')) {
            return null;
        }
        if (str_contains($lower, 'image') || str_contains($lower, 'video') || str_contains($lower, 'vision')) {
            return null;
        }
        if (str_contains($lower, 'reason')) {
            return ['category' => AiModelCategory::DEEPSEEK_REASONER, 'priority' => 200];
        }
        if (str_contains($lower, 'pro')) {
            return ['category' => AiModelCategory::DEEPSEEK_CHAT, 'priority' => 180];
        }
        if (str_contains($lower, 'flash')) {
            return ['category' => AiModelCategory::DEEPSEEK_CHAT, 'priority' => 160];
        }

        return ['category' => AiModelCategory::DEEPSEEK_CHAT, 'priority' => 150];
    }

    private function deepSeekDisplayName(string $rawName): string
    {
        $normalized = strtolower(trim($rawName));

        return match ($normalized) {
            'deepseek-flash' => 'DeepSeek Flash',
            'deepseek-v4-pro' => 'DeepSeek V4 Pro',
            'deepseek-v4-flash' => 'DeepSeek V4 Flash',
            'deepseek-chat' => 'DeepSeek Chat',
            'deepseek-reasoner' => 'DeepSeek Reasoner',
            default => $rawName,
        };
    }

    /**
     * Curated bootstrap only — never call after a failed provider sync.
     * Used for first-time never-synced Claude connections with zero inventory.
     */
    private function seedClaudeFallbackModels(int $connectionId): bool
    {
        $existing = (int) SeoAiModel::query()->where('api_connection_id', $connectionId)->count();
        if ($existing > 0) {
            return false;
        }

        $fallbacks = [
            ['claude-sonnet-4-20250514', 'Claude Sonnet 4', AiModelCategory::CLAUDE_SONNET, 200],
            ['claude-opus-4-20250514', 'Claude Opus 4', AiModelCategory::CLAUDE_OPUS, 190],
            ['claude-3-5-sonnet-20240620', 'Claude 3.5 Sonnet', AiModelCategory::CLAUDE_SONNET, 150],
            ['claude-3-haiku-20240307', 'Claude 3 Haiku', AiModelCategory::CLAUDE_HAIKU, 100],
        ];

        foreach ($fallbacks as [$raw, $label, $category, $priority]) {
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $raw,
                ],
                [
                    'category' => $category,
                    'display_name' => $label,
                    'priority' => $priority,
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => $this->capabilitiesWithResolved($raw, [
                        'source' => 'catalog',
                        'catalog_source' => 'curated',
                    ]),
                ],
            );
        }

        return true;
    }
}
