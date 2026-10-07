<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Analysis request. siteRef is the current Laravel site id.
 * language is optional; today's Topic rebuild is not language-split, so callers pass null.
 *
 * industryMatchRules / globalMatchRules are the existing lexical rule bags.
 * The legacy provider applies them. A future semantic provider may ignore them.
 * They are not a model vendor, vector size, or database choice.
 */
final class TopicGroupingInput
{
    /**
     * @param  list<TopicGroupingCandidate>  $candidates
     * @param  list<TopicGroupingSeed>  $seeds
     * @param  list<TopicGroupingProtectedTopic>  $protectedTopics
     * @param  list<int>  $lockedKeywordRefs
     * @param  array<string, mixed>  $industryMatchRules
     * @param  array<string, mixed>  $globalMatchRules
     */
    public function __construct(
        public readonly int $siteRef,
        public readonly ?string $language,
        public readonly string $scope,
        public readonly array $candidates,
        public readonly array $seeds = [],
        public readonly array $protectedTopics = [],
        public readonly array $lockedKeywordRefs = [],
        public readonly ?TopicGroupingAnchor $anchor = null,
        public readonly array $industryMatchRules = [],
        public readonly array $globalMatchRules = [],
    ) {}
}
