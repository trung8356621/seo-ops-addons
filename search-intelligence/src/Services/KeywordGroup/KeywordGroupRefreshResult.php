<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupRefreshResult
{
    /**
     * @param  array<string, int>  $excludedByReason
     */
    public function __construct(
        public readonly int $groupCount,
        public readonly int $memberCount,
        public readonly int $preservedGroupCount,
        public readonly bool $skipped,
        public readonly int $candidateCount = 0,
        public readonly int $eligibleCount = 0,
        public readonly int $excludedCount = 0,
        public readonly array $excludedByReason = [],
    ) {}
}
