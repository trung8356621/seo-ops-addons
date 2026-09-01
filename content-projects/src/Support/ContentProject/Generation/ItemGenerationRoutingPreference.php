<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

/**
 * Reorders an existing model candidate list for a per-item policy.
 *
 * Deliberately shape-agnostic: candidates stay whatever the routing engine passes in
 * (model rows, DTOs, ids). Nothing is added or dropped — only the order changes — so
 * the caller keeps full ownership of eligibility.
 */
final class ItemGenerationRoutingPreference
{
    /**
     * Incoming lists are ordered cheapest/fastest first.
     * best_quality flips that; fast_economy keeps it, floating free candidates when
     * they advertise isFree().
     *
     * @template TCandidate
     *
     * @param  array<array-key, TCandidate>  $candidates
     * @return list<TCandidate>
     */
    public static function orderCandidates(array $candidates, ?ItemGenerationMode $mode): array
    {
        $ordered = array_values($candidates);

        if ($mode === null || count($ordered) < 2) {
            return $ordered;
        }

        if ($mode === ItemGenerationMode::BestQuality) {
            return array_reverse($ordered);
        }

        $free = [];
        $rest = [];
        $sawFreeSignal = false;

        foreach ($ordered as $candidate) {
            $freeFlag = self::candidateIsFree($candidate);
            if ($freeFlag !== null) {
                $sawFreeSignal = true;

                if ($freeFlag) {
                    $free[] = $candidate;

                    continue;
                }
            }

            $rest[] = $candidate;
        }

        if (! $sawFreeSignal) {
            return $ordered;
        }

        return [...$free, ...$rest];
    }

    private static function candidateIsFree(mixed $candidate): ?bool
    {
        if (! is_object($candidate)) {
            return null;
        }

        if (method_exists($candidate, 'isFree')) {
            return (bool) $candidate->isFree();
        }

        if (property_exists($candidate, 'isFree')) {
            return (bool) $candidate->isFree;
        }

        return null;
    }

    /**
     * @template TCandidate
     *
     * @param  array<array-key, TCandidate>  $candidates
     * @param  callable(TCandidate): (int|string|null)  $idOf
     * @return list<TCandidate>
     */
    public static function prependPreferred(array $candidates, ?int $modelId, callable $idOf): array
    {
        $ordered = array_values($candidates);

        if ($modelId === null || $modelId <= 0 || $ordered === []) {
            return $ordered;
        }

        $preferred = [];
        $rest = [];

        foreach ($ordered as $candidate) {
            $id = $idOf($candidate);

            if ($id !== null && is_numeric($id) && (int) $id === $modelId) {
                $preferred[] = $candidate;

                continue;
            }

            $rest[] = $candidate;
        }

        return [...$preferred, ...$rest];
    }
}
