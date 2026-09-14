<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * SSOT: primary model area for a task/profile + secondary area for FREE-FIRST paid fallback.
 *
 * Independent of SPLIT/SINGLE shape. Media areas do not receive text secondary fallback.
 */
final class AiFallbackAreaResolver
{
    public function primaryAreaFor(AiExecutionProfile $profile): AiModelArea
    {
        return AiModelArea::fromProfile($profile);
    }

    public function primaryAreaForHook(?string $hookKey, ?AiExecutionProfile $fallbackProfile = null): AiModelArea
    {
        if ($hookKey !== null && trim($hookKey) !== '') {
            $profile = (new PromptExecutionProfileResolver())->resolve(null, $hookKey);

            return $this->primaryAreaFor($profile);
        }

        if ($fallbackProfile !== null) {
            return $this->primaryAreaFor($fallbackProfile);
        }

        return AiModelArea::TextFast;
    }

    /**
     * Paid-fallback lane after PRIMARY free phase. Null = do not apply cross-lane split
     * (caller keeps full PRIMARY sortable — used for Image/Video).
     */
    public function secondaryAreaFor(AiModelArea $primary): ?AiModelArea
    {
        return match ($primary) {
            AiModelArea::TextLongform, AiModelArea::TextReasoning => AiModelArea::TextFast,
            AiModelArea::TextFast, AiModelArea::Text => AiModelArea::TextFast,
            AiModelArea::Image, AiModelArea::Video => null,
        };
    }

    public function secondaryAreaForProfile(AiExecutionProfile $profile): ?AiModelArea
    {
        return $this->secondaryAreaFor($this->primaryAreaFor($profile));
    }

    public function profileForArea(AiModelArea $area): ?AiExecutionProfile
    {
        return match ($area) {
            AiModelArea::TextFast, AiModelArea::Text => AiExecutionProfile::TextFast,
            AiModelArea::TextLongform => AiExecutionProfile::TextLongform,
            AiModelArea::TextReasoning => AiExecutionProfile::TextReasoning,
            default => null,
        };
    }

    public function secondaryProfileFor(AiExecutionProfile $primary): ?AiExecutionProfile
    {
        $secondary = $this->secondaryAreaForProfile($primary);
        if ($secondary === null) {
            return null;
        }

        return $this->profileForArea($secondary);
    }

    /**
     * Whether FREE-FIRST should replace PRIMARY paid siblings with the secondary paid lane.
     */
    public function usesSecondaryPaidLane(AiModelArea $primary): bool
    {
        return $this->secondaryAreaFor($primary) !== null;
    }
}
