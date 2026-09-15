<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Narrow port used by generation-shape resolution (first actually usable route).
 *
 * Implementations must apply the same eligibility/capacity gates as the attempt
 * loop (health skip + {@see \Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityPolicy}),
 * so a capacity-rejected physical route never decides FREE/PAID Content shape.
 */
interface FirstAttemptableAiRouteResolver
{
    public function resolveFirstAttemptable(string $profile, AiRoutingContext $context): RoutedAiCandidate;
}
