<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

/**
 * Item generation preferences over an existing candidate list.
 *
 * Order policy (AI Center sortable / areaEnabledModels) is authoritative.
 * Generation mode (FastEconomy / BestQuality) MUST NOT reorder by cost class
 * or reverse the list — cost is a constraint enforced by AiCostPolicy / budgets,
 * not an order rewrite.
 *
 * Explicit model_override may still float a preferred model via prependPreferred().
 */
final class ItemGenerationRoutingPreference
{
    /**
     * Preserve caller order. Modes no longer rewrite free-before-paid or reverse.
     *
     * @template TCandidate
     *
     * @param  array<array-key, TCandidate>  $candidates
     * @return list<TCandidate>
     */
    public static function orderCandidates(array $candidates, ?ItemGenerationMode $mode): array
    {
        unset($mode);

        return array_values($candidates);
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
