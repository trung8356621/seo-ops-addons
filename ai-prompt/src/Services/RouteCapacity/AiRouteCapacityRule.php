<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

interface AiRouteCapacityRule
{
    public function evaluate(
        RoutedAiCandidate $candidate,
        AiExecutionProfile $profile,
        AiRoutingContext $context,
        ?AiProviderBalanceSnapshot $balance,
    ): AiRouteCapacityDecision;
}
