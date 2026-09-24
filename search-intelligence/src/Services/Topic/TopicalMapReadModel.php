<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapTopicChildren;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;

/**
 * Keywords / Topical Map presentation boundary over Keyword Landscape SSOT.
 *
 * Does not invent Pillar hierarchy. Hierarchy = Site → Topic → Keyword (lazy).
 * Network edges = Topic membership only (canonical).
 */
final class TopicalMapReadModel
{
    public const MAX_TOPIC_NODES = 500;

    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
        private readonly TopicMembershipQuery $membership,
        private readonly TopicUserTagService $topicTags = new TopicUserTagService,
    ) {}

    public function overview(int $siteId): TopicalMapOverview
    {
        if ($siteId <= 0) {
            return new TopicalMapOverview($siteId, [], 0, 0, 0, null);
        }

        // Topic shell without DNA payload — DNA available on findTopic / children path.
        $landscape = $this->landscape->forSite($siteId, false);
        $topics = $landscape->topics;
        $truncated = false;
        if (count($topics) > self::MAX_TOPIC_NODES) {
            $topics = array_slice($topics, 0, self::MAX_TOPIC_NODES);
            $truncated = true;
        }

        $topicIds = array_map(static fn ($t): int => $t->id, $topics);
        $keywordCounts = $this->membership->keywordCountsByTopicIds($siteId, $topicIds);
        $tagsByTopic = $this->topicTags->mapForTopics($siteId, $topicIds);
        $tagFacets = $this->topicTags->listForSite($siteId);
        $facetRows = array_map(
            static fn (array $tag): array => [
                'id' => (int) $tag['id'],
                'name' => (string) $tag['name'],
                'topic_count' => (int) $tag['topic_count'],
            ],
            $tagFacets,
        );

        $nodes = [];
        $totalArticles = 0;
        $totalKeywords = 0;
        $untaggedCount = 0;
        foreach ($topics as $topic) {
            $kwCount = (int) ($keywordCounts[$topic->id] ?? 0);
            $totalArticles += $topic->articleCount;
            $totalKeywords += $kwCount;
            $topicTags = $tagsByTopic[$topic->id] ?? [];
            if ($topicTags === []) {
                $untaggedCount++;
            }
            $nodes[] = [
                'id' => $topic->id,
                'name' => $topic->name,
                'mcp' => $topic->mcp,
                'dna_count' => $topic->dnaCount,
                'article_count' => $topic->articleCount,
                'keyword_count' => $kwCount,
                'coverage' => $topic->coverage,
                'status' => $topic->status,
                'has_children' => $kwCount > 0 || $topic->dnaCount > 0,
                'tags' => $topicTags,
            ];
        }

        return new TopicalMapOverview(
            siteId: $siteId,
            topics: $nodes,
            topicCount: $truncated ? count($landscape->topics) : count($nodes),
            totalArticles: $totalArticles,
            totalKeywords: $totalKeywords,
            sourceUpdatedAt: $landscape->sourceUpdatedAt,
            tagFacets: $facetRows,
            untaggedCount: $untaggedCount,
        );
    }

    /**
     * Filter overview topics by selected tag facet ids (OR). Empty selection / "all" = no filter.
     *
     * @param  list<int>  $selectedTagIds  Positive tag ids; include 0 for Untagged facet.
     * @return list<array<string, mixed>>
     */
    public function filterTopicsByTags(array $topics, array $selectedTagIds, bool $includeUntagged = false): array
    {
        $selectedTagIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedTagIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($selectedTagIds === [] && ! $includeUntagged) {
            return $topics;
        }

        return array_values(array_filter($topics, static function (array $topic) use ($selectedTagIds, $includeUntagged): bool {
            $tags = is_array($topic['tags'] ?? null) ? $topic['tags'] : [];
            if ($tags === []) {
                return $includeUntagged;
            }
            if ($selectedTagIds === []) {
                return false;
            }
            foreach ($tags as $tag) {
                if (in_array((int) ($tag['id'] ?? 0), $selectedTagIds, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function topicChildren(int $siteId, int $topicId, int $limit = TopicalMapTopicChildren::MAX_CHILDREN): ?TopicalMapTopicChildren
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return null;
        }

        $topic = $this->landscape->findTopic($siteId, $topicId, false);
        if ($topic === null) {
            return null;
        }

        $limit = max(1, min(TopicalMapTopicChildren::MAX_CHILDREN, $limit));
        $keywordIds = $this->membership->keywordIdsForTopic($siteId, $topicId);
        $total = count($keywordIds);
        $slice = array_slice($keywordIds, 0, $limit);

        $phrases = $slice === []
            ? []
            : Keyword::query()
                ->whereIn('id', $slice)
                ->pluck('phrase', 'id')
                ->all();

        // Article counts via existing detail path (per-keyword eager would be heavier).
        $children = [];
        foreach ($slice as $keywordId) {
            $name = trim((string) ($phrases[$keywordId] ?? ''));
            if ($name === '') {
                continue;
            }
            $children[] = [
                'type' => 'keyword',
                'id' => (int) $keywordId,
                'name' => $name,
                'article_count' => 0,
            ];
        }

        return new TopicalMapTopicChildren(
            siteId: $siteId,
            topicId: $topicId,
            topicName: $topic->name,
            children: $children,
            total: $total,
            truncated: $total > count($children),
        );
    }

    /**
     * Canonical membership edges for Network view (Topic ↔ Keyword).
     * Only includes children already loaded for focus topics — not a full-site dump.
     *
     * @param  list<int>  $topicIds
     * @return array{
     *   nodes: list<array{id: string, name: string, category: string, value: float}>,
     *   links: list<array{source: string, target: string}>,
     *   truncated: bool,
     *   showing_topics: int,
     *   total_topics: int
     * }
     */
    public function membershipNeighborhood(int $siteId, array $topicIds = [], int $maxTopics = 40, int $maxKeywordsPerTopic = 25): array
    {
        $overview = $this->overview($siteId);
        $allTopics = $overview->topics;
        $totalTopics = count($allTopics);

        if ($topicIds !== []) {
            $wanted = array_fill_keys(array_map('intval', $topicIds), true);
            $focus = array_values(array_filter(
                $allTopics,
                static fn (array $t): bool => isset($wanted[(int) $t['id']]),
            ));
        } else {
            // Prefer high MCP topics first for readable default neighborhood.
            usort($allTopics, static fn (array $a, array $b): int => ($b['mcp'] <=> $a['mcp']) ?: strcmp($a['name'], $b['name']));
            $focus = array_slice($allTopics, 0, max(1, $maxTopics));
        }

        $nodes = [
            [
                'id' => 'site:'.$siteId,
                'name' => 'Site',
                'category' => 'site',
                'value' => 1.0,
            ],
        ];
        $links = [];
        $seen = ['site:'.$siteId => true];

        foreach ($focus as $topic) {
            $tid = (int) $topic['id'];
            $topicNodeId = 'topic:'.$tid;
            if (! isset($seen[$topicNodeId])) {
                $nodes[] = [
                    'id' => $topicNodeId,
                    'name' => (string) $topic['name'],
                    'category' => 'topic',
                    'value' => max(1.0, (float) $topic['mcp']),
                ];
                $seen[$topicNodeId] = true;
            }
            $links[] = ['source' => 'site:'.$siteId, 'target' => $topicNodeId];

            $children = $this->topicChildren($siteId, $tid, $maxKeywordsPerTopic);
            if ($children === null) {
                continue;
            }
            foreach ($children->children as $child) {
                $kid = 'keyword:'.(int) $child['id'];
                if (! isset($seen[$kid])) {
                    $nodes[] = [
                        'id' => $kid,
                        'name' => (string) $child['name'],
                        'category' => 'keyword',
                        'value' => 1.0,
                    ];
                    $seen[$kid] = true;
                }
                $links[] = ['source' => $topicNodeId, 'target' => $kid];
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
            'truncated' => $totalTopics > count($focus),
            'showing_topics' => count($focus),
            'total_topics' => $totalTopics,
        ];
    }
}
