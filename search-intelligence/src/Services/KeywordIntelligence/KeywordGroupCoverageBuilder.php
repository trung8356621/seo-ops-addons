<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

final class KeywordGroupCoverageBuilder
{
    public function score(int $keywordCount, int $articleCount, int $groupDiversity, int $intentDiversity): string
    {
        if ($keywordCount <= 0) {
            return 'unknown';
        }

        $strong = $keywordCount >= 8 && $articleCount >= 3 && $groupDiversity >= 3 && $intentDiversity >= 2;
        if ($strong) {
            return 'strong';
        }

        $medium = $keywordCount >= 4 && ($articleCount >= 1 || $groupDiversity >= 2 || $intentDiversity >= 2);
        if ($medium) {
            return 'medium';
        }

        return 'weak';
    }
}
