<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Single source of truth for executable AI routes.
 *
 * Canonical order = Models → capability area manual positions
 * ({@see AiModelPriorityService::areaEnabledModels}).
 *
 * Strategy (Economy / Quality) must not reorder at execution time.
 * Free is metadata; FreeOnly may filter to free subset without reordering.
 */
final class CanonicalAiRouteResolver
{
    public function __construct(
        private readonly AiRoutingTargetService $targets,
    ) {}

    public function areaFor(AiExecutionProfile $profile): AiModelArea
    {
        return AiModelArea::fromProfile($profile);
    }

    /**
     * Ordered route as shown in Models + Routing UI (no cost-policy filter).
     *
     * @return list<RoutedAiCandidate>
     */
    public function resolveRoute(int $userId, AiExecutionProfile $profile): array
    {
        return $this->targets->liveCompatibleCandidates($userId, $profile);
    }

    /**
     * Executable candidates for a request (membership + optional FreeOnly subset).
     *
     * @return list<RoutedAiCandidate>
     */
    public function resolveExecutable(
        int $userId,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
    ): array {
        return $this->targets->eligibleCandidates($userId, $profile, $context);
    }

    /**
     * Stable revision fingerprint of the canonical route (order + model ids).
     */
    public function routeRevision(int $userId, AiExecutionProfile $profile): string
    {
        $parts = [];
        foreach ($this->resolveRoute($userId, $profile) as $index => $candidate) {
            $parts[] = ($index + 1).':'
                .(int) $candidate->connection->id
                .'|'.$candidate->model
                .'|'.($candidate->isFree ? 'f' : 'p');
        }

        return hash('sha256', implode(';', $parts));
    }

    public function costPolicy(AiRoutingContext $context): AiCostPolicy
    {
        return $context->costPolicy ?? AiCostPolicyScope::current();
    }
}
