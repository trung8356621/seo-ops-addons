<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Internal free-only fallback order. Never persisted into global Routing rows.
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

        usort($free, static function (RoutedAiCandidate $a, RoutedAiCandidate $b): int {
            $aRouter = OpenRouterModelEconomics::isOpenRouterFreeRouter($a->model) ? 1 : 0;
            $bRouter = OpenRouterModelEconomics::isOpenRouterFreeRouter($b->model) ? 1 : 0;
            $router = $aRouter <=> $bRouter;
            if ($router !== 0) {
                return $router;
            }
            $priority = $a->priority <=> $b->priority;
            if ($priority !== 0) {
                return $priority;
            }

            return ($a->seoAiModelId ?? 0) <=> ($b->seoAiModelId ?? 0);
        });

        return array_values($free);
    }
}
