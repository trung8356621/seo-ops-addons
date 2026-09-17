<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Contracts;

/**
 * Cross-addon Topic membership reads — always site-scoped.
 */
interface TopicMembershipCapability
{
    public const ID = 'search.topic';

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, int> keyword_id => topic_id
     */
    public function topicIdsByKeywordId(int $siteId, array $keywordIds): array;

    public function isTopicMember(int $siteId, int $keywordId): bool;

    /**
     * @return list<int>
     */
    public function keywordIdsForSite(int $siteId): array;
}
