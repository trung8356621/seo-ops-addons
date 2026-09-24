<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * Site-level Topical Map overview (Topic nodes only — keyword leaves are lazy).
 *
 * @phpstan-type TopicTag array{id: int, name: string}
 * @phpstan-type TagFacet array{id: int, name: string, topic_count: int}
 * @phpstan-type TopicNode array{
 *   id: int,
 *   name: string,
 *   mcp: float,
 *   dna_count: int,
 *   article_count: int,
 *   keyword_count: int,
 *   coverage: string,
 *   status: string,
 *   has_children: bool,
 *   tags: list<TopicTag>
 * }
 */
final class TopicalMapOverview
{
    /**
     * @param  list<TopicNode>  $topics
     * @param  list<TagFacet>  $tagFacets
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $topics,
        public readonly int $topicCount,
        public readonly int $totalArticles,
        public readonly int $totalKeywords,
        public readonly ?string $sourceUpdatedAt,
        public readonly array $tagFacets = [],
        public readonly int $untaggedCount = 0,
    ) {}

    /**
     * @return array{
     *   site_id: int,
     *   summary: array{
     *     topic_count: int,
     *     total_articles: int,
     *     total_keywords: int,
     *     source_updated_at: string|null,
     *     untagged_count: int
     *   },
     *   topics: list<TopicNode>,
     *   tag_facets: list<TagFacet>
     * }
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'summary' => [
                'topic_count' => $this->topicCount,
                'total_articles' => $this->totalArticles,
                'total_keywords' => $this->totalKeywords,
                'source_updated_at' => $this->sourceUpdatedAt,
                'untagged_count' => $this->untaggedCount,
            ],
            'topics' => $this->topics,
            'tag_facets' => $this->tagFacets,
        ];
    }
}
