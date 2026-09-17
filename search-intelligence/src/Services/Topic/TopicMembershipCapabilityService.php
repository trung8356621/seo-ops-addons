<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Contracts\TopicMembershipCapability;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipQuery;

final class TopicMembershipCapabilityService implements TopicMembershipCapability
{
    public function __construct(
        private readonly TopicMembershipQuery $query,
    ) {}

    public function topicIdsByKeywordId(int $siteId, array $keywordIds): array
    {
        return $this->query->topicIdsByKeywordId($siteId, $keywordIds);
    }

    public function isTopicMember(int $siteId, int $keywordId): bool
    {
        if ($siteId <= 0 || $keywordId <= 0) {
            return false;
        }

        $map = $this->query->topicIdsByKeywordId($siteId, [$keywordId]);

        return isset($map[$keywordId]);
    }

    public function keywordIdsForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        return SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
