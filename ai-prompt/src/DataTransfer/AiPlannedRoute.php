<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

/**
 * One inspectable physical route entry inside an immutable RoutingPlan.
 */
final class AiPlannedRoute
{
    /**
     * @param  array<string, mixed>  $staticEligibility
     */
    public function __construct(
        public readonly RoutedAiCandidate $candidate,
        public readonly string $logicalModel,
        public readonly int $logicalPriority,
        public readonly string $physicalRoute,
        public readonly int $physicalRoutePriority,
        public readonly string $provider,
        public readonly int $connectionId,
        public readonly string $connectionName,
        public readonly string $providerModel,
        public readonly string $costClass,
        public readonly string $modelArea,
        public readonly string $phase,
        public readonly array $staticEligibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            'logical_model' => $this->logicalModel,
            'logical_priority' => $this->logicalPriority,
            'physical_route' => $this->physicalRoute,
            'physical_route_priority' => $this->physicalRoutePriority,
            'provider' => $this->provider,
            'connection_id' => $this->connectionId,
            'connection_name' => $this->connectionName,
            'provider_model' => $this->providerModel,
            'cost_class' => $this->costClass,
            'model_area' => $this->modelArea,
            'phase' => $this->phase,
            'static_eligibility' => $this->staticEligibility,
            'seo_ai_model_id' => $this->candidate->seoAiModelId,
            'is_free' => $this->candidate->isFree,
        ];
    }
}
