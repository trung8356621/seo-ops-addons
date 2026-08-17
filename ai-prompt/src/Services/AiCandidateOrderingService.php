<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelFamily;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;

/**
 * Suggests Economy / Quality-first order for unordered or explicit auto-arrange.
 * Runtime routing does not call this — AI Models table order is canonical.
 */
final class AiCandidateOrderingService
{
    public function __construct(
        private readonly AiModelFamilyCatalog $catalog = new AiModelFamilyCatalog(),
    ) {}

    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function sort(
        array $candidates,
        AiExecutionProfile $profile,
        AiUsageMode $mode,
        bool $preserveExplicitOrder,
    ): array {
        if ($candidates === [] || $preserveExplicitOrder || $profile->isMedia()) {
            usort($candidates, static fn (RoutedAiCandidate $a, RoutedAiCandidate $b): int => $a->priority <=> $b->priority);

            return array_values($candidates);
        }

        usort($candidates, function (RoutedAiCandidate $a, RoutedAiCandidate $b) use ($mode): int {
            $fa = $this->catalog->familyForModelId($a->model);
            $fb = $this->catalog->familyForModelId($b->model);
            $cmp = $this->compareFamilies($fa, $fb, $mode);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a->priority <=> $b->priority;
        });

        return array_values($candidates);
    }

    private function compareFamilies(?AiModelFamily $a, ?AiModelFamily $b, AiUsageMode $mode): int
    {
        $aCost = $a?->costTier ?? 99;
        $bCost = $b?->costTier ?? 99;
        $aQuality = $a?->qualityTier ?? 0;
        $bQuality = $b?->qualityTier ?? 0;
        $aSpeed = $a?->speedTier ?? 0;
        $bSpeed = $b?->speedTier ?? 0;

        if ($mode === AiUsageMode::Economy) {
            return [$aCost, -$aSpeed, $aQuality] <=> [$bCost, -$bSpeed, $bQuality];
        }

        return [-$aQuality, -$aCost] <=> [-$bQuality, -$bCost];
    }
}
