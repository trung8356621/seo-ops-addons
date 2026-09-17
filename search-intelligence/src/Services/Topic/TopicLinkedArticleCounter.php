<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Site-scoped linked-article counts for Topic detail.
 *
 * NEVER aggregate by keyword_id alone — always require site_id.
 */
final class TopicLinkedArticleCounter
{
    /**
     * Distinct source articles on this site that link any Topic member keyword.
     */
    public function countForTopic(int $siteId, int $topicId): int
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return 0;
        }

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->countForKeywords($siteId, $keywordIds);
    }

    /**
     * @param  list<int>  $keywordIds
     */
    public function countForKeywords(int $siteId, array $keywordIds): int
    {
        if ($siteId <= 0 || $keywordIds === []) {
            return 0;
        }

        return (int) SeoLinkMap::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('source_article_id')
            ->whereHas(
                'sourceArticle',
                static fn ($q) => $q->where('site_id', $siteId)->whereNull('deleted_at'),
            )
            ->distinct()
            ->count('source_article_id');
    }

    /**
     * Batch counts for many topics on one site (no N+1).
     *
     * @param  list<int>  $topicIds
     * @return array<int, int> topic_id => count
     */
    public function countForTopics(int $siteId, array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        $out = array_fill_keys($topicIds, 0);
        if ($siteId <= 0 || $topicIds === []) {
            return $out;
        }

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
            $byTopic[$topicId][] = $keywordId;
            $allKeywordIds[] = $keywordId;
        }
        $allKeywordIds = array_values(array_unique($allKeywordIds));
        if ($allKeywordIds === []) {
            return $out;
        }

        $maps = SeoLinkMap::query()
            ->whereIn('keyword_id', $allKeywordIds)
            ->whereNotNull('source_article_id')
            ->whereHas(
                'sourceArticle',
                static fn ($q) => $q->where('site_id', $siteId)->whereNull('deleted_at'),
            )
            ->get(['keyword_id', 'source_article_id']);

        /** @var array<int, array<int, true>> $articlesByKeyword */
        $articlesByKeyword = [];
        foreach ($maps as $map) {
            $articlesByKeyword[(int) $map->keyword_id][(int) $map->source_article_id] = true;
        }

        foreach ($byTopic as $topicId => $keywordIds) {
            /** @var array<int, true> $articles */
            $articles = [];
            foreach ($keywordIds as $keywordId) {
                foreach ($articlesByKeyword[$keywordId] ?? [] as $articleId => $_) {
                    $articles[$articleId] = true;
                }
            }
            $out[$topicId] = count($articles);
        }

        return $out;
    }
}
