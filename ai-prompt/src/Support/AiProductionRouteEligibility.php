<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Canonical production eligibility filters by execution profile + hook.
 *
 * Provider brand is NOT an eligibility axis. DeepSeek (and every other provider)
 * is admitted or rejected by area membership, required capabilities, cost policy,
 * connection/credentials, runtime health, and active model state.
 */
final class AiProductionRouteEligibility
{
    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function filter(array $candidates, AiExecutionProfile $profile, ?AiRoutingContext $context = null): array
    {
        unset($profile, $context);

        return array_values($candidates);
    }

    /**
     * @deprecated Capability / area layers own DeepSeek eligibility. Always true.
     */
    public function deepSeekAllowed(AiExecutionProfile $profile, string $hookKey): bool
    {
        unset($profile, $hookKey);

        return true;
    }
}
