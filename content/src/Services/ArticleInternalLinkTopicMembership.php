<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use App\Core\Capability\CapabilityRegistry;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Contracts\TopicMembershipCapability;

/**
 * Topic membership for Internal Link Stage 2 — always site-scoped.
 */
final class ArticleInternalLinkTopicMembership
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
    ) {}

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, int> keyword_id => topic_id
     */
    public function topicIdsByKeywordId(int $siteId, array $keywordIds): array
    {
        $cap = $this->capabilities->getAs(
            TopicMembershipCapability::ID,
            TopicMembershipCapability::class,
        );
        if (! $cap instanceof TopicMembershipCapability || $siteId <= 0) {
            return [];
        }

        return $cap->topicIdsByKeywordId($siteId, $keywordIds);
    }

    /**
     * @return list<int>
     */
    public function keywordIdsForSite(int $siteId): array
    {
        $cap = $this->capabilities->getAs(
            TopicMembershipCapability::ID,
            TopicMembershipCapability::class,
        );
        if (! $cap instanceof TopicMembershipCapability || $siteId <= 0) {
            return [];
        }

        return $cap->keywordIdsForSite($siteId);
    }

    /**
     * @deprecated Use topicIdsByKeywordId($siteId, $keywordIds)
     * @param  list<int>  $keywordIds
     * @return array<int, int>
     */
    public function clusterKeysByKeywordId(array $keywordIds): array
    {
        unset($keywordIds);

        return [];
    }

    /**
     * @param  array<int, int>  $topicIdsByKeywordId
     */
    public function isTopicKeyword(Keyword $keyword, array $topicIdsByKeywordId): bool
    {
        return isset($topicIdsByKeywordId[(int) $keyword->id]);
    }
}
