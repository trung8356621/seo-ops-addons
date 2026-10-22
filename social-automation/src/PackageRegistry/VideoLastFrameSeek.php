<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Prefer a decodable frame near the end — not t=0 and not the empty EOF sample.
 */
final class VideoLastFrameSeek
{
    public const END_EPSILON_SECONDS = 0.25;

    public function seekSeconds(float $durationSeconds): float
    {
        if ($durationSeconds <= 0.0) {
            return 0.0;
        }
        if ($durationSeconds <= self::END_EPSILON_SECONDS) {
            return max(0.0, $durationSeconds * 0.5);
        }

        return max(0.0, $durationSeconds - self::END_EPSILON_SECONDS);
    }
}
