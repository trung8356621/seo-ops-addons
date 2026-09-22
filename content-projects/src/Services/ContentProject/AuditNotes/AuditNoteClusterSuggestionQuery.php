<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\TopicHistoryReadModel;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;

/**
 * SEO Audit Notes suggestions — Topic Core adapter via Keyword Landscape SSOT.
 *
 * Public API keeps legacy field names (`cluster_ref`, `mcp_share`) as transport
 * compatibility. Live identity is always topic:{seo_topics.id}.
 *
 * Business landscape truth comes from KeywordLandscapeReadModel only.
 * This class owns Audit Notes presentation: filter / search / pagination / planned history.
 */
final class AuditNoteClusterSuggestionQuery
{
    public const PER_PAGE = 25;

    public const DNA_LIMIT = 30;

    public function __construct(
        private readonly KeywordLandscapeReadModel $landscape,
        private readonly TopicHistoryReadModel $topicHistory = new TopicHistoryReadModel,
    ) {}

    /**
     * @param  array{
     *   search?: string,
     *   filter?: string,
     *   page?: int
     * }  $filters
     * @return array{
     *   total: int,
     *   paginator: LengthAwarePaginator,
     *   rows: list<array{
     *     topic_id: int,
     *     cluster_ref: string,
     *     cluster_name: string,
     *     mcp_share: float,
     *     dna_count: int,
     *     article_count: int,
     *     has_focus_article: bool,
     *     planned_history_count: int,
     *     already_planned: bool
     *   }>
     * }
     */
    public function paginate(int $siteId, array $filters = [], int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            $empty = new Paginator([], 0, $perPage, 1);

            return ['total' => 0, 'paginator' => $empty, 'rows' => []];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $filter = trim((string) ($filters['filter'] ?? 'all'));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $items = $this->buildSuggestionItems($siteId);
        $items = $this->applyFilter($items, $filter);
        $items = $this->applySearch($siteId, $items, $search);

        usort(
            $items,
            static function (array $a, array $b): int {
                $byShare = ((float) $a['mcp_share']) <=> ((float) $b['mcp_share']);
                if ($byShare !== 0) {
                    return $byShare;
                }

                return strcmp(
                    mb_strtolower((string) $a['cluster_name'], 'UTF-8'),
                    mb_strtolower((string) $b['cluster_name'], 'UTF-8'),
                );
            },
        );

        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        $paginator = new Paginator($slice, $total, $perPage, $page, [
            'path' => '/',
            'pageName' => 'auditNotesPage',
        ]);

        return [
            'total' => $total,
            'paginator' => $paginator,
            'rows' => array_values($slice),
        ];
    }

    /**
     * @return array{
     *   topic_id: int,
     *   cluster_ref: string,
     *   cluster_name: string,
     *   mcp_share: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool,
     *   planned_history_count: int,
     *   already_planned: bool,
     *   cluster_dna: list<array{phrase: string, weight: int}>
     * }|null
     */
    public function findSuggestion(int $siteId, string $clusterRef): ?array
    {
        $clusterRef = trim($clusterRef);
        $topicId = TopicPlanningRef::decode($clusterRef);
        if ($siteId <= 0 || $topicId === null) {
            return null;
        }

        $topic = $this->landscape->findTopic($siteId, $topicId, true);
        if (! $topic instanceof KeywordLandscapeTopic) {
            return null;
        }

        return $this->mapTopicToSuggestion($topic, $this->topicHistory->plannedCountsByTopicRef($siteId), true);
    }

    /**
     * Exact normalized Topic-name match for Planner plan clone (no fuzzy).
     *
     * @return list<array{cluster_ref: string, cluster_name: string, mcp_share: float}>
     */
    public function findExactNormalizedNameMatches(int $siteId, string $normalizedName): array
    {
        $needle = AuditNoteDnaNormalizer::normalizeKey($normalizedName);
        if ($siteId <= 0 || $needle === '') {
            return [];
        }

        $out = [];
        foreach ($this->buildSuggestionItems($siteId) as $item) {
            $nameKey = AuditNoteDnaNormalizer::normalizeKey((string) ($item['cluster_name'] ?? ''));
            if ($nameKey === '' || $nameKey !== $needle) {
                continue;
            }
            $out[] = [
                'cluster_ref' => (string) $item['cluster_ref'],
                'cluster_name' => (string) $item['cluster_name'],
                'mcp_share' => (float) $item['mcp_share'],
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function dnaPhrasesForCluster(int $siteId, string $clusterRef, int $limit = self::DNA_LIMIT): array
    {
        $topicId = TopicPlanningRef::decode($clusterRef);
        if ($siteId <= 0 || $topicId === null) {
            return [];
        }

        $topic = $this->landscape->findTopic($siteId, $topicId, true);
        if (! $topic instanceof KeywordLandscapeTopic) {
            return [];
        }

        $phrases = [];
        foreach (array_slice($topic->dna, 0, max(1, $limit)) as $row) {
            $phrase = trim((string) ($row['phrase'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * @return list<array{
     *   topic_id: int,
     *   cluster_ref: string,
     *   cluster_name: string,
     *   mcp_share: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool,
     *   planned_history_count: int,
     *   already_planned: bool
     * }>
     */
    private function buildSuggestionItems(int $siteId): array
    {
        // List rows do not need DNA payloads; dna_count comes from landscape metrics.
        $landscape = $this->landscape->forSite($siteId, false);
        $plannedByRef = $this->topicHistory->plannedCountsByTopicRef($siteId);
        $out = [];

        foreach ($landscape->topics as $topic) {
            $out[] = $this->mapTopicToSuggestion($topic, $plannedByRef, false);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $plannedByRef
     * @return array{
     *   topic_id: int,
     *   cluster_ref: string,
     *   cluster_name: string,
     *   mcp_share: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool,
     *   planned_history_count: int,
     *   already_planned: bool,
     *   cluster_dna?: list<array{phrase: string, weight: int}>
     * }
     */
    private function mapTopicToSuggestion(KeywordLandscapeTopic $topic, array $plannedByRef, bool $withDna): array
    {
        $ref = TopicPlanningRef::encode($topic->id);
        $plannedHistory = (int) ($plannedByRef[$ref] ?? 0);

        $row = [
            'topic_id' => $topic->id,
            'cluster_ref' => $ref,
            'cluster_name' => $topic->name,
            'mcp_share' => round($topic->mcp, 1),
            'dna_count' => $topic->dnaCount,
            'article_count' => $topic->articleCount,
            'has_focus_article' => $topic->hasFocusArticle,
            'planned_history_count' => $plannedHistory,
            'already_planned' => $plannedHistory > 0,
        ];

        if ($withDna) {
            $row['cluster_dna'] = array_slice($topic->dna, 0, self::DNA_LIMIT);
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $items, string $filter): array
    {
        return match ($filter) {
            'mcp_low' => array_values(array_filter(
                $items,
                static fn (array $row): bool => (float) ($row['mcp_share'] ?? 0) < 5.0,
            )),
            'has_focus' => array_values(array_filter(
                $items,
                static fn (array $row): bool => (bool) ($row['has_focus_article'] ?? false),
            )),
            'no_focus' => array_values(array_filter(
                $items,
                static fn (array $row): bool => ! (bool) ($row['has_focus_article'] ?? false),
            )),
            default => $items,
        };
    }

    /**
     * Search Topic name + DNA phrase → parent Topic.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applySearch(int $siteId, array $items, string $search): array
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');
        if ($needle === '') {
            return $items;
        }

        $dnaMatchedRefs = $this->topicRefsMatchingDna($siteId, $needle);

        return array_values(array_filter(
            $items,
            static function (array $row) use ($needle, $dnaMatchedRefs): bool {
                $ref = (string) ($row['cluster_ref'] ?? '');
                if ($ref !== '' && isset($dnaMatchedRefs[$ref])) {
                    return true;
                }
                $name = mb_strtolower((string) ($row['cluster_name'] ?? ''), 'UTF-8');

                return $name !== '' && str_contains($name, $needle);
            },
        ));
    }

    /**
     * @return array<string, true>
     */
    private function topicRefsMatchingDna(int $siteId, string $needle): array
    {
        if ($needle === '' || ! $this->topicDnaTableReady()) {
            return [];
        }

        $like = '%'.$needle.'%';
        $topicIds = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(value) LIKE ?', [$like])
            ->distinct()
            ->limit(500)
            ->pluck('topic_id');

        $out = [];
        foreach ($topicIds as $topicId) {
            $id = (int) $topicId;
            if ($id <= 0) {
                continue;
            }
            $ref = TopicPlanningRef::encode($id);
            if ($ref !== '') {
                $out[$ref] = true;
            }
        }

        return $out;
    }

    private function topicDnaTableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna');
    }
}
