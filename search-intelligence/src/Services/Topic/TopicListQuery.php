<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordTopicAssignmentStats;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;

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
     * @param  list<string>|null  $languageVariants  Same Keywords workspace language gate as Dictionary.
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $siteId, array $filters = [], ?array $languageVariants = null): LengthAwarePaginator
    {
        $intent = strtolower(trim((string) ($filters['intent'] ?? '')));
        $coverage = strtolower(trim((string) ($filters['coverage'] ?? '')));
        if ($intent !== '' || $coverage !== '') {
            return $this->paginateWithAdvancedMetricFilters($siteId, $filters, $languageVariants);
        }

        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return new Paginator([], 0, max(1, (int) ($filters['per_page'] ?? 25)));
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? Paginator::resolveCurrentPage()));
        $inventory = app(KeywordUiInventoryQuery::class);
        $allowedKeywordIds = ($languageVariants !== null && $languageVariants !== [])
            ? $inventory->keywordIdSubquery($siteId, $languageVariants)
            : null;

        $query = SeoTopic::query()
            ->where('site_id', $siteId)
            ->select(['id', 'site_id', 'name', 'source', 'status', 'is_locked', 'created_at', 'updated_at']);
        if (TopicMcpExclusionService::columnReady()) {
            $query->addSelect('mcp_excluded');
        }
        if (KeywordGroupSchema::topicLinkReady()) {
            $query->addSelect('keyword_group_id');
        }

        $this->applyBaseFilters($query, $siteId, $filters, $allowedKeywordIds);
        $this->addListAggregates($query, $siteId, $allowedKeywordIds);
        $this->applyDatabaseSort($query, (string) ($filters['sort'] ?? 'name_asc'));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $topics = $paginator->getCollection();
        if ($topics->isEmpty()) {
            return $paginator;
        }

        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $languageExcludeKeywordIds = $this->resolveLanguageExcludeKeywordIdsForTopics(
            $siteId,
            $topicIds,
            $allowedKeywordIds,
        );
        $pageKeywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $mcpExcludedKeywordIds = $this->mcpSkip()->skippedKeywordIdMap($pageKeywordIds);
        $eligibleExclusions = $mcpExcludedKeywordIds + $languageExcludeKeywordIds;

        $rawArticleCounts = $this->articleCounter->countForTopics(
            $siteId,
            $topicIds,
            $languageExcludeKeywordIds !== [] ? $languageExcludeKeywordIds : null,
        );
        $internalLinkCounts = $this->internalLinkCounter->countForTopics(
            $siteId,
            $topicIds,
            $languageExcludeKeywordIds !== [] ? $languageExcludeKeywordIds : null,
        );
        $eligibleTopicIds = $topics
            ->reject(static fn ($topic): bool => (bool) ($topic->mcp_excluded ?? false))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $eligibleArticleCounts = $this->articleCounter->countForTopics(
            $siteId,
            $eligibleTopicIds,
            $eligibleExclusions !== [] ? $eligibleExclusions : null,
        );
        $shareDenominator = $this->eligibleArticleDenominator($siteId, $allowedKeywordIds);
        $tagMetrics = $this->tagMetrics->forTopics(
            $siteId,
            $eligibleTopicIds,
            $eligibleArticleCounts,
            $eligibleExclusions !== [] ? $eligibleExclusions : null,
        );
        $userTagsByTopic = $this->userTags()->mapForTopics($siteId, $topicIds);
        $groupNames = $this->keywordGroupNames($siteId, $topics);

        $rows = $topics->map(function ($topic) use (
            $siteId,
            $rawArticleCounts,
            $internalLinkCounts,
            $eligibleArticleCounts,
            $shareDenominator,
            $tagMetrics,
            $userTagsByTopic,
            $groupNames,
        ): array {
            $topicId = (int) $topic->id;
            $isMcpExcluded = (bool) ($topic->mcp_excluded ?? false);
            $keywordCount = (int) ($topic->keyword_count ?? 0);
            $lockedMemberCount = (int) ($topic->locked_member_count ?? 0);
            $articleCount = (int) ($rawArticleCounts[$topicId] ?? 0);
            $internalLinkCount = (int) ($internalLinkCounts[$topicId] ?? 0);
            $tags = $tagMetrics[$topicId] ?? [
                'intent' => '',
                'coverage' => 'unknown',
                'canonical_source' => (string) ($topic->source ?? 'auto'),
            ];

            return [
                'topic_id' => $topicId,
                'site_id' => $siteId,
                'name' => (string) $topic->name,
                'label' => (string) $topic->name,
                'status' => (string) $topic->status,
                'is_locked' => (bool) $topic->is_locked,
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
                'topical_share' => $isMcpExcluded || $shareDenominator <= 0
                    ? 0.0
                    : round(((int) ($eligibleArticleCounts[$topicId] ?? 0) / $shareDenominator) * 100, 1),
                'state' => $keywordCount === 0 ? 'planned' : 'active',
                'updated_at' => $topic->updated_at?->toIso8601String(),
            ] + $this->keywordGroupFields($topic, $groupNames);
        });

        $paginator->setCollection($rows);

        return $paginator;
    }

    /**
     * Intent and coverage depend on derived TopicTagMetricsResolver state. Until those
     * metrics become queryable, only explicitly activating either filter uses this path.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $languageVariants
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateWithAdvancedMetricFilters(int $siteId, array $filters = [], ?array $languageVariants = null): LengthAwarePaginator
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
        $allowedKeywordIds = $this->resolveLanguageAllowedKeywordIds($siteId, $languageVariants);
        $languageExcludeKeywordIds = $this->resolveLanguageExcludeKeywordIds($siteId, $allowedKeywordIds);

        $query = SeoTopic::query()
            ->where('site_id', $siteId)
            ->select(['id', 'site_id', 'name', 'source', 'status', 'is_locked', 'created_at', 'updated_at']);
        if (KeywordGroupSchema::topicLinkReady()) {
            $query->addSelect('keyword_group_id');
        }

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

        // MCP-eligible article counts for Topical Share — language-scoped when workspace filter set.
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
        if ($languageExcludeKeywordIds !== []) {
            $excludedKeywordIds = $excludedKeywordIds + $languageExcludeKeywordIds;
        }
        $mcpArticleCounts = $this->articleCounter->countForTopics($siteId, $mcpEligibleTopicIds, $excludedKeywordIds);
        $shares = (new TopicTopicalShareCalculator)->percentages($mcpArticleCounts);
        // Raw inventory counts for management rows (includes excluded Topic / Keyword links).
        // article_count = DISTINCT Focus Articles; internal_link_count = actual internal edges.
        $rawArticleCounts = $this->articleCounter->countForTopics($siteId, $topicIds, $languageExcludeKeywordIds !== [] ? $languageExcludeKeywordIds : null);
        $rawInternalLinkCounts = $this->internalLinkCounter->countForTopics($siteId, $topicIds, $languageExcludeKeywordIds !== [] ? $languageExcludeKeywordIds : null);

        $memberQuery = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds);
        if ($allowedKeywordIds !== null) {
            if ($allowedKeywordIds === []) {
                $memberQuery->whereRaw('1 = 0');
            } else {
                $memberQuery->whereIn('keyword_id', $allowedKeywordIds);
            }
        }
        $memberCounts = $memberQuery
            ->selectRaw('topic_id, COUNT(*) as member_count, SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked_member_count')
            ->groupBy('topic_id')
            ->get()
            ->keyBy(static fn ($row): int => (int) $row->topic_id);

        $rawMemberTotals = [];
        if ($allowedKeywordIds !== null) {
            $rawMemberTotals = SeoTopicKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('topic_id', $topicIds)
                ->selectRaw('topic_id, COUNT(*) as member_count')
                ->groupBy('topic_id')
                ->pluck('member_count', 'topic_id')
                ->map(static fn ($count): int => (int) $count)
                ->all();
        }

        $tagMetrics = $this->tagMetrics->forTopics($siteId, $mcpEligibleTopicIds, $mcpArticleCounts, $excludedKeywordIds);
        $userTagsByTopic = $this->userTags()->mapForTopics($siteId, $topicIds);
        $groupNames = $this->keywordGroupNames($siteId, $topics);

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

            // Language landscape: hide Topics that only have memberships outside the selected language.
            // Empty Topics (0 members total) remain visible for management.
            if ($allowedKeywordIds !== null) {
                $totalMembers = (int) ($rawMemberTotals[$topicId] ?? 0);
                if ($keywordCount === 0 && $totalMembers > 0) {
                    continue;
                }
            }

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
            ] + $this->keywordGroupFields($topic, $groupNames);
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
     * @param  iterable<int, SeoTopic>  $topics
     * @return array<int, string>
     */
    private function keywordGroupNames(int $siteId, iterable $topics): array
    {
        if (! KeywordGroupSchema::topicLinkReady()) {
            return [];
        }

        $ids = [];
        foreach ($topics as $topic) {
            $id = (int) ($topic->keyword_group_id ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        return SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereIn('id', array_values($ids))
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * @param  array<int, string>  $groupNames
     * @return array{keyword_group_id: ?int, keyword_group_name: ?string}
     */
    private function keywordGroupFields(object $topic, array $groupNames): array
    {
        $id = (int) ($topic->keyword_group_id ?? 0);
        $name = trim((string) ($groupNames[$id] ?? ''));
        if ($id <= 0 || $name === '') {
            return [
                'keyword_group_id' => null,
                'keyword_group_name' => null,
            ];
        }

        return [
            'keyword_group_id' => $id,
            'keyword_group_name' => $name,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyBaseFilters(
        Builder $query,
        int $siteId,
        array $filters,
        ?QueryBuilder $allowedKeywordIds,
    ): void {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        $lockFilter = (string) ($filters['lock_filter'] ?? '');
        if ($lockFilter === 'topic_locked') {
            $query->where('is_locked', true);
        } elseif ($lockFilter === 'unlocked') {
            $query->where('is_locked', false);
        } elseif ($lockFilter === 'membership_locked') {
            $query->where('is_locked', false)->whereExists(function (QueryBuilder $members) use ($siteId, $allowedKeywordIds): void {
                $members->selectRaw('1')
                    ->from('seo_topic_keywords as locked_members')
                    ->whereColumn('locked_members.topic_id', 'seo_topics.id')
                    ->where('locked_members.site_id', $siteId)
                    ->where('locked_members.is_locked', true);
                if ($allowedKeywordIds !== null) {
                    $members->whereIn('locked_members.keyword_id', clone $allowedKeywordIds);
                }
            });
        }

        $source = strtolower(trim((string) ($filters['source'] ?? '')));
        if ($source === 'auto' || $source === 'manual') {
            $query->where('source', $source);
        }

        $tagIds = $this->normalizeTagIds($filters['tag_ids'] ?? []);
        if ($tagIds !== [] && TopicUserTagService::tablesReady()) {
            $siteTagIds = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag::query()
                ->where('site_id', $siteId)
                ->whereIn('id', $tagIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (count($siteTagIds) !== count($tagIds)) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($siteTagIds as $tagId) {
                    $query->whereExists(function (QueryBuilder $tags) use ($tagId): void {
                        $tags->selectRaw('1')
                            ->from('seo_topic_tag_assignments')
                            ->whereColumn('seo_topic_tag_assignments.topic_id', 'seo_topics.id')
                            ->where('seo_topic_tag_assignments.tag_id', $tagId);
                    });
                }
            }
        }

        if ($allowedKeywordIds !== null) {
            $query->where(function (Builder $language) use ($siteId, $allowedKeywordIds): void {
                $language->whereNotExists(function (QueryBuilder $members) use ($siteId): void {
                    $members->selectRaw('1')
                        ->from('seo_topic_keywords as any_members')
                        ->whereColumn('any_members.topic_id', 'seo_topics.id')
                        ->where('any_members.site_id', $siteId);
                })->orWhereExists(function (QueryBuilder $members) use ($siteId, $allowedKeywordIds): void {
                    $members->selectRaw('1')
                        ->from('seo_topic_keywords as language_members')
                        ->whereColumn('language_members.topic_id', 'seo_topics.id')
                        ->where('language_members.site_id', $siteId)
                        ->whereIn('language_members.keyword_id', clone $allowedKeywordIds);
                });
            });
        }

        if ((bool) ($filters['has_articles'] ?? false)) {
            $query->whereExists($this->focusArticleBindingQuery($siteId, $allowedKeywordIds, false));
        }
    }

    private function addListAggregates(Builder $query, int $siteId, ?QueryBuilder $allowedKeywordIds): void
    {
        $query->selectSub($this->membershipCountQuery($siteId, $allowedKeywordIds), 'keyword_count');
        $query->selectSub($this->lockedMembershipCountQuery($siteId, $allowedKeywordIds), 'locked_member_count');
        $query->selectSub($this->articleCountQuery($siteId, $allowedKeywordIds, false), 'article_count_sort');
        $query->selectSub($this->articleCountQuery($siteId, $allowedKeywordIds, true), 'eligible_article_count_sort');
    }

    private function applyDatabaseSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'keywords_desc' => $query->orderByDesc('keyword_count')->orderBy('name')->orderBy('id'),
            'keywords_asc' => $query->orderBy('keyword_count')->orderBy('name')->orderBy('id'),
            'articles_desc' => $query->orderByDesc('article_count_sort')->orderBy('name')->orderBy('id'),
            'articles_asc' => $query->orderBy('article_count_sort')->orderBy('name')->orderBy('id'),
            'topical_share_desc' => TopicMcpExclusionService::columnReady()
                ? $query->orderByRaw('CASE WHEN mcp_excluded = 1 THEN 0 ELSE eligible_article_count_sort END DESC')->orderBy('name')->orderBy('id')
                : $query->orderByDesc('eligible_article_count_sort')->orderBy('name')->orderBy('id'),
            'topical_share_asc' => TopicMcpExclusionService::columnReady()
                ? $query->orderByRaw('CASE WHEN mcp_excluded = 1 THEN 0 ELSE eligible_article_count_sort END ASC')->orderBy('name')->orderBy('id')
                : $query->orderBy('eligible_article_count_sort')->orderBy('name')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }

    private function membershipCountQuery(int $siteId, ?QueryBuilder $allowedKeywordIds): QueryBuilder
    {
        $query = DB::connection('omi_seo_ai')->table('seo_topic_keywords as count_members')
            ->selectRaw('COUNT(*)')
            ->whereColumn('count_members.topic_id', 'seo_topics.id')
            ->where('count_members.site_id', $siteId);
        if ($allowedKeywordIds !== null) {
            $query->whereIn('count_members.keyword_id', clone $allowedKeywordIds);
        }

        return $query;
    }

    private function lockedMembershipCountQuery(int $siteId, ?QueryBuilder $allowedKeywordIds): QueryBuilder
    {
        $query = $this->membershipCountQuery($siteId, $allowedKeywordIds);
        $query->where('count_members.is_locked', true);

        return $query;
    }

    private function articleCountQuery(
        int $siteId,
        ?QueryBuilder $allowedKeywordIds,
        bool $mcpEligible,
        string $topicColumn = 'seo_topics.id',
    ): QueryBuilder
    {
        return $this->focusArticleBindingQuery($siteId, $allowedKeywordIds, $mcpEligible, $topicColumn)
            ->selectRaw('COUNT(DISTINCT focus_articles.id)');
    }

    private function focusArticleBindingQuery(
        int $siteId,
        ?QueryBuilder $allowedKeywordIds,
        bool $mcpEligible,
        string $topicColumn = 'seo_topics.id',
    ): QueryBuilder {
        $siteKey = KeywordMetaKey::siteMainArticleId($siteId);
        $legacyKey = KeywordMetaKey::MainArticleId->value;
        $query = DB::connection('omi_seo_ai')->table('seo_topic_keywords as focus_members')
            ->leftJoin('keyword_meta as scoped_focus', function ($join) use ($siteKey): void {
                $join->on('scoped_focus.keyword_id', '=', 'focus_members.keyword_id')
                    ->where('scoped_focus.meta_key', $siteKey);
            })
            ->leftJoin('keyword_meta as legacy_focus', function ($join) use ($legacyKey): void {
                $join->on('legacy_focus.keyword_id', '=', 'focus_members.keyword_id')
                    ->where('legacy_focus.meta_key', $legacyKey);
            })
            ->join('articles as focus_articles', function ($join): void {
                $join->on('focus_articles.id', '=', DB::raw('COALESCE(scoped_focus.meta_value, legacy_focus.meta_value)'));
            })
            ->whereColumn('focus_members.topic_id', $topicColumn)
            ->where('focus_members.site_id', $siteId)
            ->where('focus_articles.site_id', $siteId)
            ->whereNull('focus_articles.deleted_at');
        if ($allowedKeywordIds !== null) {
            $query->whereIn('focus_members.keyword_id', clone $allowedKeywordIds);
        }
        if ($mcpEligible) {
            $query->whereNotExists(function (QueryBuilder $meta): void {
                $meta->selectRaw('1')
                    ->from('keyword_meta as skipped_meta')
                    ->whereColumn('skipped_meta.keyword_id', 'focus_members.keyword_id')
                    ->where('skipped_meta.meta_key', KeywordMetaKey::McpExcluded->value)
                    ->where('skipped_meta.meta_value', '1');
            });
        }

        return $query;
    }

    private function eligibleArticleDenominator(int $siteId, ?QueryBuilder $allowedKeywordIds): int
    {
        $perTopic = DB::connection('omi_seo_ai')->query()
            ->from('seo_topics as denominator_topics')
            ->where('denominator_topics.site_id', $siteId)
            ->when(
                TopicMcpExclusionService::columnReady(),
                static fn (QueryBuilder $query): QueryBuilder => $query->where('denominator_topics.mcp_excluded', false),
            )
            ->select('denominator_topics.id')
            ->selectSub(
                $this->articleCountQuery($siteId, $allowedKeywordIds, true, 'denominator_topics.id'),
                'article_count',
            );

        return (int) DB::connection('omi_seo_ai')->query()
            ->fromSub($perTopic, 'eligible_topic_counts')
            ->sum('article_count');
    }

    private function resolveLanguageExcludeKeywordIdsForTopics(
        int $siteId,
        array $topicIds,
        ?QueryBuilder $allowedKeywordIds,
    ): array {
        if ($allowedKeywordIds === null || $topicIds === []) {
            return [];
        }

        $ids = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->whereNotIn('keyword_id', clone $allowedKeywordIds)
            ->pluck('keyword_id');
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
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
        $membershipLockedQuery = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('is_locked', true);
        $allowedKeywordIds = $this->resolveLanguageAllowedKeywordIds($siteId, $languageVariants);
        if ($allowedKeywordIds !== null) {
            if ($allowedKeywordIds === []) {
                $membershipLockedQuery->whereRaw('1 = 0');
            } else {
                $membershipLockedQuery->whereIn('keyword_id', $allowedKeywordIds);
            }
        }
        $membershipLocked = (int) $membershipLockedQuery->count();

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
     * Inventory keyword ids for the selected Keywords language, or null when no language gate.
     *
     * @param  list<string>|null  $languageVariants
     * @return list<int>|null
     */
    private function resolveLanguageAllowedKeywordIds(int $siteId, ?array $languageVariants): ?array
    {
        if ($languageVariants === null || $languageVariants === []) {
            return null;
        }

        return app(KeywordUiInventoryQuery::class)->keywordIds($siteId, $languageVariants);
    }

    /**
     * Member keywords outside the selected language landscape (for article/link exclude maps).
     *
     * @param  list<int>|null  $allowedKeywordIds
     * @return array<int, true>
     */
    private function resolveLanguageExcludeKeywordIds(int $siteId, ?array $allowedKeywordIds): array
    {
        if ($allowedKeywordIds === null || $siteId <= 0) {
            return [];
        }

        $allowedSet = array_fill_keys($allowedKeywordIds, true);
        $exclude = [];
        $memberIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->all();
        foreach ($memberIds as $keywordId) {
            if (! isset($allowedSet[$keywordId])) {
                $exclude[$keywordId] = true;
            }
        }

        return $exclude;
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
