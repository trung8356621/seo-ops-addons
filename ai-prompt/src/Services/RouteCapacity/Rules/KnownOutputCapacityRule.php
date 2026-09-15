<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity\Rules;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiProviderBalanceSnapshot;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityDecision;
use Omnichannel\Addons\AiPrompt\Services\RouteCapacity\AiRouteCapacityRule;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Extension point for known output-capacity / max-token ceilings before the provider call.
 * Currently a no-op so future rules can plug in without router conditionals.
 */
final class KnownOutputCapacityRule implements AiRouteCapacityRule
{
    public function evaluate(
        RoutedAiCandidate $candidate,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
        ?AiProviderBalanceSnapshot $balance,
    ): AiRouteCapacityDecision {
        unset($candidate, $profile, $context);

        return AiRouteCapacityDecision::allow($balance);
    }
}
