<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * FreeOnly cost policy: keep free models that are already on the canonical route.
 * Preserves manual order. Does not invent an external free pool or re-sort.
 */
final class FreeRoutingResolver
{
    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function resolve(array $candidates): array
    {
        $free = [];
        foreach ($candidates as $candidate) {
            if ($candidate->isFree) {
                $free[] = $candidate;
            }
        }

        return array_values($free);
    }
}
