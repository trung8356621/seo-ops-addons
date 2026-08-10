<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * Expected CTR bands theo position — configurable, không ML.
 */
final class GscExpectedCtrModel
{
    public const ALGORITHM_VERSION = '1.0.0';

    /** @var list<array{position_min: float, position_max: float, ctr: float}> */
    private const DEFAULT_BANDS = [
        ['position_min' => 1, 'position_max' => 1, 'ctr' => 0.28],
        ['position_min' => 2, 'position_max' => 3, 'ctr' => 0.15],
        ['position_min' => 4, 'position_max' => 5, 'ctr' => 0.08],
        ['position_min' => 6, 'position_max' => 10, 'ctr' => 0.04],
        ['position_min' => 11, 'position_max' => 20, 'ctr' => 0.02],
        ['position_min' => 21, 'position_max' => 100, 'ctr' => 0.005],
    ];

    public function expectedCtr(?float $position): ?float
    {
        if ($position === null || $position <= 0) {
            return null;
        }

        foreach ($this->bands() as $band) {
            if ($position >= $band['position_min'] && $position <= $band['position_max']) {
                return $band['ctr'];
            }
        }

        return 0.001;
    }

    public function ctrGap(?float $actualCtr, ?float $position): ?float
    {
        if ($actualCtr === null) {
            return null;
        }

        $expected = $this->expectedCtr($position);
        if ($expected === null) {
            return null;
        }

        return round($expected - $actualCtr, 6);
    }

    /** @return list<array{position_min: float, position_max: float, ctr: float}> */
    public function bands(): array
    {
        if (! function_exists('config')) {
            return self::DEFAULT_BANDS;
        }

        try {
            $value = config('seo-content-ai.gsc_intelligence.expected_ctr.bands', self::DEFAULT_BANDS);

            return is_array($value) ? $value : self::DEFAULT_BANDS;
        } catch (\Throwable) {
            return self::DEFAULT_BANDS;
        }
    }
}
