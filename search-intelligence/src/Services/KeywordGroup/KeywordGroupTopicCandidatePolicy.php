<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Effective Topic-anchor eligibility for a Group membership.
 *
 * Override null = automatic policy (Focus required).
 * Override true = FORCE_ALLOW. Override false = FORCE_BLOCK.
 */
final class KeywordGroupTopicCandidatePolicy
{
    public static function effectiveCandidate(bool $hasFocus, ?bool $override): bool
    {
        if ($override === false) {
            return false;
        }
        if ($override === true) {
            return true;
        }

        return $hasFocus;
    }

    /**
     * Sync legacy is_topic_candidate for readers that still check the boolean.
     * false only when explicitly blocked; otherwise true (not a FORCE_ALLOW signal).
     */
    public static function legacyIsTopicCandidate(?bool $override): bool
    {
        return $override !== false;
    }
}
