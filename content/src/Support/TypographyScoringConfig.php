<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Trọng số chấm điểm typography — một nơi duy nhất, có unit test.
 */
final class TypographyScoringConfig
{
    public const WEIGHT_EXACT_REQUIRED = 0.70;

    public const WEIGHT_MISSING_PENALTY = 0.20;

    public const WEIGHT_EXTRA_TEXT = 0.15;

    public const WEIGHT_LAYOUT = 0.10;

    public const WEIGHT_READABILITY = 0.05;

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $expectedBlocks
     * @param  list<array{id?: string, text: string, matched?: bool}>  $detectedBlocks
     * @param  list<string>  $missingBlockIds
     * @param  list<string>  $mismatchedBlockIds
     * @param  list<string>  $extraText
     */
    public function computeScore(
        array $expectedBlocks,
        array $detectedBlocks,
        array $missingBlockIds,
        array $mismatchedBlockIds,
        array $extraText,
        float $readabilityConfidence = 1.0,
    ): float {
        if ($expectedBlocks === []) {
            return max(0.0, min(1.0, $readabilityConfidence * self::WEIGHT_READABILITY));
        }

        $requiredBlocks = array_values(array_filter(
            $expectedBlocks,
            static fn (array $block): bool => (bool) ($block['required'] ?? true),
        ));

        $requiredCount = count($requiredBlocks);
        $matchedRequired = 0;

        foreach ($requiredBlocks as $block) {
            $id = (string) ($block['id'] ?? '');
            if ($id !== '' && in_array($id, $mismatchedBlockIds, true)) {
                continue;
            }
            if ($id !== '' && in_array($id, $missingBlockIds, true)) {
                continue;
            }
            $matchedRequired++;
        }

        $exactAccuracy = $requiredCount > 0 ? $matchedRequired / $requiredCount : 1.0;
        $missingPenalty = $requiredCount > 0
            ? count($missingBlockIds) / $requiredCount
            : 0.0;
        $extraPenalty = min(1.0, count($extraText) / max(1, count($expectedBlocks)));
        $layoutScore = $detectedBlocks !== [] ? 1.0 : 0.5;

        $score = (self::WEIGHT_EXACT_REQUIRED * $exactAccuracy)
            + (self::WEIGHT_LAYOUT * $layoutScore)
            + (self::WEIGHT_READABILITY * max(0.0, min(1.0, $readabilityConfidence)))
            - (self::WEIGHT_MISSING_PENALTY * $missingPenalty)
            - (self::WEIGHT_EXTRA_TEXT * $extraPenalty);

        return max(0.0, min(1.0, round($score, 4)));
    }
}
