<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordIntelligenceDebouncer
{
    public function shouldDispatch(bool $created, bool $phraseChanged, int $pendingJobsForSite): bool
    {
        if (! $created && ! $phraseChanged) {
            return false;
        }

        return $pendingJobsForSite === 0;
    }

    public function jobsForChangedSet(int $changedCount): int
    {
        return $changedCount > 0 ? 1 : 0;
    }
}
