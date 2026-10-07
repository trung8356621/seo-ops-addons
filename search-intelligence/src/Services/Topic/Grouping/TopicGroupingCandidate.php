<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/** One keyword text submitted for grouping analysis. */
final class TopicGroupingCandidate
{
    public function __construct(
        public readonly int $keywordRef,
        public readonly string $text,
    ) {}
}
