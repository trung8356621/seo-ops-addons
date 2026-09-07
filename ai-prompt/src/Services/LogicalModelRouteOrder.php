<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiCanonicalModelKey;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Preserves logical-model order; within each logical model prefers Direct over Aggregator.
 * Does not promote later logical models above earlier ones.
 *
 * @phpstan-type CandidateList list<RoutedAiCandidate>
 */
final class LogicalModelRouteOrder
{
    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function apply(array $candidates): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }

        /** @var array<string, list<RoutedAiCandidate>> $groups */
        $groups = [];
        /** @var list<string> $order */
        $order = [];

        foreach ($candidates as $candidate) {
            $key = AiCanonicalModelKey::fromProviderModelId($candidate->model, $candidate->provider);
            if (! isset($groups[$key])) {
                $groups[$key] = [];
                $order[] = $key;
            }
            $groups[$key][] = $candidate;
        }

        $out = [];
        foreach ($order as $key) {
            $members = $groups[$key];
            usort($members, function (RoutedAiCandidate $a, RoutedAiCandidate $b): int {
                $ra = $this->routeRank($a);
                $rb = $this->routeRank($b);
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }

                return $a->priority <=> $b->priority;
            });
            foreach ($members as $member) {
                $out[] = $member;
            }
        }

        return $out;
    }

    private function routeRank(RoutedAiCandidate $candidate): int
    {
        if (ApiConnectionProviders::isAggregator($candidate->provider)) {
            return 2;
        }

        // Official / direct APIs first.
        return 1;
    }
}
