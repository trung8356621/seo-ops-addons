<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * One proposed group. suggestedLabel is analysis text, not seo_topics.name.
 * existingTopicRef echoes a protected input ref so Laravel can line the group
 * up with inventory. Null means the analyzer did not bind an existing Topic.
 */
final class TopicGroupingGroup
{
    /** Correlation echo of protected input. Not a lock write. */
    public const META_IS_LOCKED = 'is_locked';

    /**
     * @param  list<TopicGroupingMember>  $members
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly string $suggestedLabel,
        public readonly array $members,
        public readonly ?int $existingTopicRef = null,
        public readonly array $metadata = [],
    ) {}
}
