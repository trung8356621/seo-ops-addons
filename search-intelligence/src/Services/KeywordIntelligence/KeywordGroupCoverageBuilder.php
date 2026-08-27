<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

/**
 * Topic Cluster content-coverage score.
 * Diversity signal is DNA branch count (not legacy Rule Groups).
 */
final class KeywordGroupCoverageBuilder
{
    public function score(int $keywordCount, int $articleCount, int $dnaBranchCount, int $intentDiversity): string
    {
        if ($keywordCount <= 0) {
            return 'unknown';
        }

        $strong = $keywordCount >= 8 && $articleCount >= 3 && $dnaBranchCount >= 3 && $intentDiversity >= 2;
        if ($strong) {
            return 'strong';
        }

        $medium = $keywordCount >= 4 && ($articleCount >= 1 || $dnaBranchCount >= 2 || $intentDiversity >= 2);
        if ($medium) {
            return 'medium';
        }

        return 'weak';
    }
}
