<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * Site-level Topical Map overview (Topic nodes only — keyword leaves are lazy).
 *
 * @phpstan-type TopicNode array{
 *   id: int,
 *   name: string,
 *   mcp: float,
 *   dna_count: int,
 *   article_count: int,
 *   keyword_count: int,
 *   coverage: string,
 *   status: string,
 *   has_children: bool
 * }
 */
final class TopicalMapOverview
{
    /**
     * @param  list<TopicNode>  $topics
     */
    public function __construct(
        public readonly int $siteId,
        public readonly array $topics,
        public readonly int $topicCount,
        public readonly int $totalArticles,
        public readonly int $totalKeywords,
        public readonly ?string $sourceUpdatedAt,
    ) {}

    /**
     * @return array{
     *   site_id: int,
     *   summary: array{topic_count: int, total_articles: int, total_keywords: int, source_updated_at: string|null},
     *   topics: list<TopicNode>
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
            ],
            'topics' => $this->topics,
        ];
    }
}
