<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapTopicChildren;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;

/**
 * Keywords / Topical Map presentation boundary over Keyword Landscape SSOT.
 *
 * Does not invent Pillar hierarchy.
 * Structure = Site → Tag → Topic. Network = Site → Topic → DNA (canonical phrases).
 * Network edges = Site→Topic and Topic→DNA only (no invented semantics).
 * Lazy keyword children remain available for non-Network consumers.
 */
final class TopicalMapReadModel
{
    public const MAX_TOPIC_NODES = 500;

    /** Defensive full-graph DNA cap (per-Topic Landscape DNA_LIMIT still applies). */
    public const MAX_NETWORK_DNA_NODES = 1500;

    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
        private readonly TopicMembershipQuery $membership,
        private readonly TopicUserTagService $topicTags = new TopicUserTagService,
        private readonly ?SkipKeywordFromMcpService $mcpSkip = null,
    ) {}

    private function mcpSkip(): SkipKeywordFromMcpService
    {
        return $this->mcpSkip ?? app(SkipKeywordFromMcpService::class);
    }

    public function overview(int $siteId): TopicalMapOverview
    {
        if ($siteId <= 0) {
            return new TopicalMapOverview($siteId, [], 0, 0, 0, null);
        }

        // Include canonical Topic DNA phrases so Network can render Site→Topic→DNA immediately.
        $landscape = $this->landscape->forSite($siteId, true);
        $topics = $landscape->topics;
        $truncated = false;
        if (count($topics) > self::MAX_TOPIC_NODES) {
            $topics = array_slice($topics, 0, self::MAX_TOPIC_NODES);
            $truncated = true;
        }

        $topicIds = array_map(static fn ($t): int => $t->id, $topics);
        $keywordCounts = $this->mcpEligibleKeywordCountsByTopicIds($siteId, $topicIds);
        $tagsByTopic = $this->topicTags->mapForTopics($siteId, $topicIds);
        // Tag facets for Map = MCP-eligible Topics only (not raw site inventory).
        $facetRows = $this->buildMcpEligibleTagFacets($tagsByTopic);
        $untaggedCount = 0;
        foreach ($topics as $topic) {
            $topicTags = $tagsByTopic[$topic->id] ?? [];
            if ($topicTags === []) {
                $untaggedCount++;
            }
        }

        $nodes = [];
        $totalArticles = 0;
        $totalKeywords = 0;
        foreach ($topics as $topic) {
            $kwCount = (int) ($keywordCounts[$topic->id] ?? 0);
            $totalArticles += $topic->articleCount;
            $totalKeywords += $kwCount;
            $topicTags = $tagsByTopic[$topic->id] ?? [];
            $nodes[] = [
                'id' => $topic->id,
                'name' => $topic->name,
                'mcp' => $topic->mcp,
                'dna_count' => $topic->dnaCount,
                'dna' => $topic->dna,
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
        $keywordIds = $this->mcpEligibleKeywordIdsForTopic($siteId, $topicId);
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
     * Full-site Network graph: Site → Topic → canonical DNA phrases.
     * No Keyword children, no Topic focus / drill neighborhood.
     *
     * @param  list<int>  $topicIds  Empty = all overview Topics.
     * @return array{
     *   nodes: list<array<string, mixed>>,
     *   links: list<array{source: string, target: string}>,
     *   truncated: bool,
     *   dna_truncated: bool,
     *   showing_topics: int,
     *   total_topics: int,
     *   showing_dna: int,
     *   total_dna: int
     * }
     */
    public function membershipNeighborhood(int $siteId, array $topicIds = [], int $maxTopics = self::MAX_TOPIC_NODES, int $maxDnaNodes = self::MAX_NETWORK_DNA_NODES): array
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
            $focus = $allTopics;
            if (count($focus) > max(1, $maxTopics)) {
                $focus = array_slice($focus, 0, max(1, $maxTopics));
            }
        }

        // Prefer high-MCP Topics first so a defensive DNA cap keeps the important branches.
        usort(
            $focus,
            static fn (array $a, array $b): int => (((float) $b['mcp']) <=> ((float) $a['mcp']))
                ?: strcmp((string) $a['name'], (string) $b['name']),
        );

        $nodes = [
            [
                'id' => 'site:'.$siteId,
                'name' => 'Site',
                'category' => 'site',
                'value' => 1.0,
            ],
        ];
        $links = [];
        $dnaBudget = max(1, $maxDnaNodes);
        $totalDna = 0;
        $showingDna = 0;

        foreach ($focus as $topic) {
            $tid = (int) $topic['id'];
            $topicNodeId = 'topic:'.$tid;
            $nodes[] = [
                'id' => $topicNodeId,
                'name' => (string) $topic['name'],
                'category' => 'topic',
                'value' => max(0.0, (float) $topic['mcp']),
                'mcp' => (float) $topic['mcp'],
                'dna_count' => (int) ($topic['dna_count'] ?? 0),
                'article_count' => (int) ($topic['article_count'] ?? 0),
                'keyword_count' => (int) ($topic['keyword_count'] ?? 0),
                'tags' => is_array($topic['tags'] ?? null) ? $topic['tags'] : [],
            ];
            $links[] = ['source' => 'site:'.$siteId, 'target' => $topicNodeId];

            $dnaRows = is_array($topic['dna'] ?? null) ? $topic['dna'] : [];
            $totalDna += count($dnaRows);
            foreach ($dnaRows as $index => $row) {
                if ($showingDna >= $dnaBudget) {
                    break;
                }
                $phrase = trim((string) ($row['phrase'] ?? ''));
                if ($phrase === '') {
                    continue;
                }
                $dnaId = 'dna:'.$tid.':'.$index;
                $nodes[] = [
                    'id' => $dnaId,
                    'name' => $phrase,
                    'category' => 'dna',
                    'value' => 1.0,
                    'topic_id' => $tid,
                    'topic_name' => (string) $topic['name'],
                ];
                $links[] = ['source' => $topicNodeId, 'target' => $dnaId];
                $showingDna++;
            }
        }

        $dnaTruncated = $totalDna > $showingDna;

        return [
            'nodes' => $nodes,
            'links' => $links,
            'truncated' => $dnaTruncated || $totalTopics > count($focus),
            'dna_truncated' => $dnaTruncated,
            'showing_topics' => count($focus),
            'total_topics' => $totalTopics,
            'showing_dna' => $showingDna,
            'total_dna' => $totalDna,
        ];
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, int>
     */
    private function mcpEligibleKeywordCountsByTopicIds(int $siteId, array $topicIds): array
    {
        $raw = $this->membership->keywordCountsByTopicIds($siteId, $topicIds);
        if ($raw === []) {
            return [];
        }

        $out = [];
        foreach ($topicIds as $topicId) {
            $topicId = (int) $topicId;
            if ($topicId <= 0) {
                continue;
            }
            $out[$topicId] = count($this->mcpEligibleKeywordIdsForTopic($siteId, $topicId));
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function mcpEligibleKeywordIdsForTopic(int $siteId, int $topicId): array
    {
        $keywordIds = $this->membership->keywordIdsForTopic($siteId, $topicId);
        if ($keywordIds === []) {
            return [];
        }

        $skipped = $this->mcpSkip()->skippedKeywordIdMap($keywordIds);

        return array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => $id > 0 && ! isset($skipped[$id]),
        ));
    }

    /**
     * @param  array<int, list<array{id: int, name: string}>>  $tagsByTopic
     * @return list<array{id: int, name: string, topic_count: int}>
     */
    private function buildMcpEligibleTagFacets(array $tagsByTopic): array
    {
        /** @var array<int, array{id: int, name: string, topic_count: int}> $byId */
        $byId = [];
        foreach ($tagsByTopic as $tags) {
            foreach ($tags as $tag) {
                $id = (int) ($tag['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (! isset($byId[$id])) {
                    $byId[$id] = [
                        'id' => $id,
                        'name' => (string) ($tag['name'] ?? ''),
                        'topic_count' => 0,
                    ];
                }
                $byId[$id]['topic_count']++;
            }
        }

        $rows = array_values($byId);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }
}
