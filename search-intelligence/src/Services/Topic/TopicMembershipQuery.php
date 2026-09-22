<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Site-scoped Topic membership reads.
 */
final class TopicMembershipQuery
{
    /**
     * @param  list<int>  $keywordIds
     * @return array<int, int> keyword_id => topic_id (only for this site)
     */
    public function topicIdsByKeywordId(int $siteId, array $keywordIds): array
    {
        if ($siteId <= 0 || $keywordIds === []) {
            return [];
        }

        $keywordIds = array_values(array_unique(array_filter(
            array_map('intval', $keywordIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($keywordIds === []) {
            return [];
        }

        $rows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('keyword_id', $keywordIds)
            ->get(['keyword_id', 'topic_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->keyword_id] = (int) $row->topic_id;
        }

        return $out;
    }

    public function topicForKeyword(int $siteId, int $keywordId): ?SeoTopic
    {
        if ($siteId <= 0 || $keywordId <= 0) {
            return null;
        }

        $membership = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->first();
        if (! $membership instanceof SeoTopicKeyword) {
            return null;
        }

        $topic = SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', (int) $membership->topic_id)
            ->first();

        return $topic instanceof SeoTopic ? $topic : null;
    }

    /**
     * @return list<int>
     */
    public function keywordIdsForTopic(int $siteId, int $topicId): array
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return [];
        }

        return SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, int> topic_id => keyword_count
     */
    public function keywordCountsByTopicIds(int $siteId, array $topicIds): array
    {
        if ($siteId <= 0 || $topicIds === []) {
            return [];
        }

        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($topicIds === []) {
            return [];
        }

        $rows = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->selectRaw('topic_id, COUNT(*) as keyword_count')
            ->groupBy('topic_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->topic_id] = (int) $row->keyword_count;
        }

        return $out;
    }
}
