<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Deterministic Topic coverage label from Topic Core metrics (presentation only).
 */
final class TopicCoverageCalculator
{
    /**
     * @return 'unknown'|'strong'|'medium'|'weak'
     */
    public function coverage(
        int $keywordCount,
        int $articleCount,
        int $dnaBranchCount,
        int $intentDiversity,
    ): string {
        if ($keywordCount <= 0) {
            return 'unknown';
        }

        if ($keywordCount >= 8
            && $articleCount >= 3
            && $dnaBranchCount >= 3
            && $intentDiversity >= 2
        ) {
            return 'strong';
        }

        if ($keywordCount >= 4
            && ($articleCount >= 1 || $dnaBranchCount >= 2 || $intentDiversity >= 2)
        ) {
            return 'medium';
        }

        return 'weak';
    }
}
