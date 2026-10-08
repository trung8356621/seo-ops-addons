<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * One inventory text considered for Keyword Grouping.
 * Not a Keyword Eloquent model.
 */
final class KeywordGroupingEligibilityCandidate
{
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
    ) {}
}
