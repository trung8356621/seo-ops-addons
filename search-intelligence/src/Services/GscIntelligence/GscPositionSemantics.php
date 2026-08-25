<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * GSC position: lower number is better.
 * delta = current − previous; positive delta = worsened.
 */
final class GscPositionSemantics
{
    /**
     * @return array{
     *   delta: ?float,
     *   worsened: bool,
     *   improved: bool,
     *   label: string
     * }
     */
    public static function compare(?float $current, ?float $previous): array
    {
        if ($current === null || $previous === null) {
            return [
                'delta' => null,
                'worsened' => false,
                'improved' => false,
                'label' => 'unknown',
            ];
        }

        $delta = round($current - $previous, 4);
        $worsened = $delta > 0;
        $improved = $delta < 0;

        return [
            'delta' => $delta,
            'worsened' => $worsened,
            'improved' => $improved,
            'label' => $worsened
                ? 'position_worsened'
                : ($improved ? 'position_improved' : 'position_stable'),
        ];
    }

    public static function worsenedByAtLeast(?float $current, ?float $previous, float $minDelta): bool
    {
        $cmp = self::compare($current, $previous);

        return $cmp['worsened'] && $cmp['delta'] !== null && $cmp['delta'] >= $minDelta;
    }

    public static function improvedByAtLeast(?float $current, ?float $previous, float $minDelta): bool
    {
        $cmp = self::compare($current, $previous);

        return $cmp['improved'] && $cmp['delta'] !== null && abs($cmp['delta']) >= $minDelta;
    }
}
