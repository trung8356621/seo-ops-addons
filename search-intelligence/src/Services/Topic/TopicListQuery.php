<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordTopicAssignmentStats;

/**
 * Site-scoped Topic list read model for Filament Topic index.
 *
 * Identity = seo_topics.id (numeric Topic Core), never retired cluster identity.
 * Lists ALL Topics including mcp_excluded (raw management).
 * Topical Share / coverage follow MCP-eligible Topics + Keywords only.
 */
final class TopicListQuery
{
    public function __construct(
        private readonly TopicLinkedArticleCounter $articleCounter,
        private readonly TopicInternalLinkCounter $internalLinkCounter = new TopicInternalLinkCounter,
        private readonly TopicTagMetricsResolver $tagMetrics = new TopicTagMetricsResolver,
        private readonly ?TopicUserTagService $userTags = null,
        private readonly ?SkipKeywordFromMcpService $mcpSkip = null,
        private readonly ?TopicMcpExclusionService $topicMcp = null,
    ) {}

    private function userTags(): TopicUserTagService
    {
        return $this->userTags ?? app(TopicUserTagService::class);
    }

    private function mcpSkip(): SkipKeywordFromMcpService
    {
        return $this->mcpSkip ?? app(SkipKeywordFromMcpService::class);
    }

    private function topicMcp(): TopicMcpExclusionService
    {
        return $this->topicMcp ?? app(TopicMcpExclusionService::class);
    }

    /**
     * @param  array{
     *     search?: string,
     *     sort?: string,
     *     has_articles?: bool,
     *     lock_filter?: string,
     *     tag_ids?: list<int|string>,
     *     intent?: string,
     *     coverage?: string,
     *     source?: string,
     *     per_page?: int,
     *     page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $siteId, array $filters = []): LengthAwarePaginator
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return new Paginator([], 0, max(1, (int) ($filters['per_page'] ?? 25)));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'name_asc');
        $hasArticles = (bool) ($filters['has_articles'] ?? false);
        $lockFilter = (string) ($filters['lock_filter'] ?? '');
        $tagIds = $this->normalizeTagIds($filters['tag_ids'] ?? []);
        $intentFilter = strtolower(trim((string) ($filters['intent'] ?? '')));
        $coverageFilter = strtolower(trim((string) ($filters['coverage'] ?? '')));
        $sourceFilter = strtolower(trim((string) ($filters['source'] ?? '')));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        $query = SeoTopic::query()
            ->where('site_id', $siteId)
            ->select(['id', 'site_id', 'name', 'source', 'status', 'is_locked', 'created_at', 'updated_at']);

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if ($lockFilter === 'topic_locked') {
            $query->where('is_locked', true);
        } elseif ($lockFilter === 'unlocked') {
            $query->where('is_locked', false);
        }

        if ($sourceFilter === 'auto' || $sourceFilter === 'manual') {
            $query->where('source', $sourceFilter);
        }

        // Custom tags: AND semantics — Topic must contain every selected tag_id.
        // Only site-owned tags are accepted (validated via seo_topic_tags.site_id).
        if ($tagIds !== [] && TopicUserTagService::tablesReady()) {
            $siteTagIds = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag::query()
                ->where('site_id', $siteId)
                ->whereIn('id', $tagIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (count($siteTagIds) !== count($tagIds)) {
                // Forged/cross-site tag id → empty result (hard site isolation).
                return new Paginator([], 0, $perPage);
            }
            foreach ($siteTagIds as $tagId) {
                $query->whereExists(function ($sub) use ($tagId): void {
                    $sub->selectRaw('1')
                        ->from('seo_topic_tag_assignments')
                        ->whereColumn('seo_topic_tag_assignments.topic_id', 'seo_topics.id')
                        ->where('seo_topic_tag_assignments.tag_id', $tagId);
                });
            }
        }

        $topics = $query->orderBy('id')->get();
        if ($topics->isEmpty()) {
            return new Paginator([], 0, $perPage);
        }

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Site-wide MCP-eligible article counts so Topical Share denominator matches landscape SSOT.
        // Excluded Topics remain listed (raw management) but do not contribute to share/coverage.
        $allSiteTopicIds = SeoTopic::query()
            ->where('site_id', $siteId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $excludedTopics = $this->topicMcp()->excludedTopicIdMap($siteId, $allSiteTopicIds);
        $mcpEligibleTopicIds = array_values(array_filter(
            $allSiteTopicIds,
            static fn (int $id): bool => ! isset($excludedTopics[$id]),
        ));
        $excludedKeywordIds = $this->mcpExcludedKeywordIdsForTopics($siteId, $mcpEligibleTopicIds);
        $mcpArticleCounts = $this->articleCounter->countForTopics($siteId, $mcpEligibleTopicIds, $excludedKeywordIds);
        $shares = (new TopicTopicalShareCalculator)->percentages($mcpArticleCounts);
        // Raw inventory counts for management rows (includes excluded Topic / Keyword links).
        // article_count = DISTINCT Focus Articles; internal_link_count = actual internal edges.
        $rawArticleCounts = $this->articleCounter->countForTopics($siteId, $topicIds);
        $rawInternalLinkCounts = $this->internalLinkCounter->countForTopics($siteId, $topicIds);

        $memberCounts = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->selectRaw('topic_id, COUNT(*) as member_count, SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked_member_count')
            ->groupBy('topic_id')
            ->get()
            ->keyBy(static fn ($row): int => (int) $row->topic_id);

        $tagMetrics = $this->tagMetrics->forTopics($siteId, $mcpEligibleTopicIds, $mcpArticleCounts, $excludedKeywordIds);
        $userTagsByTopic = $this->userTags()->mapForTopics($siteId, $topicIds);

        $rows = [];
        foreach ($topics as $topic) {
            $topicId = (int) $topic->id;
            $counts = $memberCounts->get($topicId);
            $keywordCount = (int) ($counts->member_count ?? 0);
            $lockedMemberCount = (int) ($counts->locked_member_count ?? 0);
            $articleCount = (int) ($rawArticleCounts[$topicId] ?? 0);
            $internalLinkCount = (int) ($rawInternalLinkCounts[$topicId] ?? 0);
            $isTopicLocked = (bool) $topic->is_locked;
            $isMcpExcluded = isset($excludedTopics[$topicId]);
            $tags = $tagMetrics[$topicId] ?? [
                'intent' => '',
                'coverage' => 'unknown',
                'canonical_source' => 'auto',
            ];

            if ($hasArticles && $articleCount <= 0) {
                continue;
            }
            if ($lockFilter === 'membership_locked' && ($isTopicLocked || $lockedMemberCount <= 0)) {
                continue;
            }
            if (! $isMcpExcluded && $intentFilter !== '' && strtolower((string) ($tags['intent'] ?? '')) !== $intentFilter) {
                continue;
            }
            if (! $isMcpExcluded && $coverageFilter !== '' && strtolower((string) ($tags['coverage'] ?? '')) !== $coverageFilter) {
                continue;
            }

            $rows[] = [
                'topic_id' => $topicId,
                'site_id' => $siteId,
                'name' => (string) $topic->name,
                'label' => (string) $topic->name,
                'status' => (string) $topic->status,
                'is_locked' => $isTopicLocked,
                'mcp_excluded' => $isMcpExcluded,
                'has_membership_locks' => $lockedMemberCount > 0,
                'locked_member_count' => $lockedMemberCount,
                'keyword_count' => $keywordCount,
                'article_count' => $articleCount,
                'internal_link_count' => $internalLinkCount,
                'internal_links' => $internalLinkCount,
                'intent' => $isMcpExcluded ? '' : (string) ($tags['intent'] ?? ''),
                'coverage' => $isMcpExcluded ? 'unknown' : (string) ($tags['coverage'] ?? 'unknown'),
                'canonical_source' => (string) ($tags['canonical_source'] ?? 'auto'),
                'intent_diversity' => $isMcpExcluded ? 0 : (int) ($tags['intent_diversity'] ?? 0),
                'dna_branch_count' => $isMcpExcluded ? 0 : (int) ($tags['dna_branch_count'] ?? 0),
                'user_tags' => $userTagsByTopic[$topicId] ?? [],
                'topical_share' => $isMcpExcluded ? 0.0 : (float) ($shares[$topicId] ?? 0.0),
                'state' => $keywordCount === 0 ? 'planned' : 'active',
                'updated_at' => $topic->updated_at?->toIso8601String(),
            ];
        }

        $rows = $this->sortRows($rows, $sort);
        $total = count($rows);
        $page = max(1, (int) ($filters['page'] ?? Paginator::resolveCurrentPage()));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new Paginator($slice, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return array{
     *     topic_count: int,
     *     seo_eligible_keywords: int,
     *     inventory_total: int,
     *     assigned: int,
     *     unassigned: int,
     *     topic_locked: int,
     *     membership_locked: int,
     *     seo_eligible_clustering: int
     * }
     */
    public function summary(int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return [
                'topic_count' => 0,
                'seo_eligible_keywords' => 0,
                'inventory_total' => 0,
                'assigned' => 0,
                'unassigned' => 0,
                'clustered' => 0,
                'unclustered' => 0,
                'topic_locked' => 0,
                'membership_locked' => 0,
                'seo_eligible_clustering' => 0,
            ];
        }

        $stats = app(KeywordTopicAssignmentStats::class)->forSite($siteId, $languageVariants);
        $topicLocked = (int) SeoTopic::query()->where('site_id', $siteId)->where('is_locked', true)->count();
        $membershipLocked = (int) SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true)
            ->count();

        return [
            // Primary UX denominator = Dictionary/UI inventory (language-aware when provided).
            'topic_count' => $stats['topic_count'],
            'seo_eligible_keywords' => $stats['inventory_total'],
            'inventory_total' => $stats['inventory_total'],
            'assigned' => $stats['assigned'],
            'unassigned' => $stats['unassigned'],
            'clustered' => $stats['assigned'],
            'unclustered' => $stats['unassigned'],
            'topic_locked' => $topicLocked,
            'membership_locked' => $membershipLocked,
            'seo_eligible_clustering' => $stats['seo_eligible_clustering'],
        ];
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<int>
     */
    private function normalizeTagIds(array $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $raw),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sort): array
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'name_desc' => strcmp((string) $b['name'], (string) $a['name']),
                'keywords_desc' => ((int) $b['keyword_count']) <=> ((int) $a['keyword_count']),
                'keywords_asc' => ((int) $a['keyword_count']) <=> ((int) $b['keyword_count']),
                'articles_desc' => ((int) $b['article_count']) <=> ((int) $a['article_count']),
                'articles_asc' => ((int) $a['article_count']) <=> ((int) $b['article_count']),
                'topical_share_desc' => ((float) $b['topical_share']) <=> ((float) $a['topical_share']),
                'topical_share_asc' => ((float) $a['topical_share']) <=> ((float) $b['topical_share']),
                default => strcmp((string) $a['name'], (string) $b['name']),
            };
        });

        return $rows;
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, true>
     */
    private function mcpExcludedKeywordIdsForTopics(int $siteId, array $topicIds): array
    {
        if ($siteId <= 0 || $topicIds === []) {
            return [];
        }

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $this->mcpSkip()->skippedKeywordIdMap($keywordIds);
    }
}
