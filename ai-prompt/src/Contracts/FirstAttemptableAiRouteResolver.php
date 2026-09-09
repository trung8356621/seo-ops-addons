<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Narrow port used by generation-shape resolution (first usable route).
 */
interface FirstAttemptableAiRouteResolver
{
    public function resolveFirstAttemptable(string $profile, AiRoutingContext $context): RoutedAiCandidate;
}
