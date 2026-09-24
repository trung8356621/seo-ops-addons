<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;

/**
 * Site-scoped INTERNAL link edge counts for Topic detail/list.
 *
 * Counts actual linkMap rows (edges), not distinct source articles.
 * Excludes ignored maps, external, and wiki_trust.
 */
final class TopicInternalLinkCounter
{
    /**
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     */
    public function countForTopic(int $siteId, int $topicId, array|null $excludeKeywordIds = null): int
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return 0;
        }

        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && ! isset($exclude[$id]))
            ->values()
            ->all();

        return $this->countForKeywords($siteId, $keywordIds);
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     */
    public function countForKeywords(int $siteId, array $keywordIds, array|null $excludeKeywordIds = null): int
    {
        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);
        $keywordIds = array_values(array_filter(
            array_map('intval', $keywordIds),
            static fn (int $id): bool => $id > 0 && ! isset($exclude[$id]),
        ));
        if ($siteId <= 0 || $keywordIds === []) {
            return 0;
        }

        return (int) SeoLinkMap::query()
            ->whereIn('keyword_id', $keywordIds)
            ->where('link_type', SeoLinkMapType::Internal->value)
            ->where('status', '!=', SeoLinkMapStatus::Ignored->value)
            ->whereHas(
                'sourceArticle',
                static fn ($q) => $q->where('site_id', $siteId)->whereNull('deleted_at'),
            )
            ->count();
    }

    /**
     * @param  list<int>  $topicIds
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     * @return array<int, int> topic_id => internal edge count
     */
    public function countForTopics(int $siteId, array $topicIds, array|null $excludeKeywordIds = null): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        $out = array_fill_keys($topicIds, 0);
        if ($siteId <= 0 || $topicIds === []) {
            return $out;
        }

        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);

        $memberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id']);

        /** @var array<int, list<int>> $byTopic */
        $byTopic = [];
        /** @var list<int> $allKeywordIds */
        $allKeywordIds = [];
        foreach ($memberships as $row) {
            $topicId = (int) $row->topic_id;
            $keywordId = (int) $row->keyword_id;
            if ($keywordId <= 0 || isset($exclude[$keywordId])) {
                continue;
            }
            $byTopic[$topicId][] = $keywordId;
            $allKeywordIds[] = $keywordId;
        }
        $allKeywordIds = array_values(array_unique($allKeywordIds));
        if ($allKeywordIds === []) {
            return $out;
        }

        $maps = SeoLinkMap::query()
            ->whereIn('keyword_id', $allKeywordIds)
            ->where('link_type', SeoLinkMapType::Internal->value)
            ->where('status', '!=', SeoLinkMapStatus::Ignored->value)
            ->whereHas(
                'sourceArticle',
                static fn ($q) => $q->where('site_id', $siteId)->whereNull('deleted_at'),
            )
            ->get(['keyword_id']);

        /** @var array<int, int> $edgesByKeyword */
        $edgesByKeyword = [];
        foreach ($maps as $map) {
            $keywordId = (int) $map->keyword_id;
            $edgesByKeyword[$keywordId] = ($edgesByKeyword[$keywordId] ?? 0) + 1;
        }

        foreach ($byTopic as $topicId => $keywordIds) {
            $total = 0;
            foreach ($keywordIds as $keywordId) {
                $total += $edgesByKeyword[$keywordId] ?? 0;
            }
            $out[$topicId] = $total;
        }

        return $out;
    }

    /**
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     * @return array<int, true>
     */
    private function normalizeExcludeMap(array|null $excludeKeywordIds): array
    {
        if ($excludeKeywordIds === null || $excludeKeywordIds === []) {
            return [];
        }

        $out = [];
        foreach ($excludeKeywordIds as $key => $value) {
            if (is_int($key) && $value === true) {
                if ($key > 0) {
                    $out[$key] = true;
                }

                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
