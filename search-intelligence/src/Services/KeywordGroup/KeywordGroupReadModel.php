<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicLinkedArticleCounter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;

/**
 * Focused Group read paths — no full-inventory materialization.
 */
final class KeywordGroupReadModel
{
    public const DEFAULT_PER_PAGE = 20;

    public const MEMBER_PAGE_SIZE = 50;

    public const SEARCH_LIMIT = 20;

    public function __construct(
        private readonly ?TopicLinkedArticleCounter $focusCounter = null,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateGroups(
        int $siteId,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
        string $search = '',
    ): LengthAwarePaginator {
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);

        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return new Paginator([], 0, $perPage, $page);
        }

        // Stable identity order — mutable Group name must not reshuffle cards.
        $groupQuery = SeoKeywordGroup::query()->where('site_id', $siteId);
        $this->constrainGroupsToLanguage($groupQuery, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
        $this->constrainGroupsToSearch($groupQuery, $siteId, $search);
        $groupQuery->withCount([
            'memberships as memberships_count' => function ($query) use ($siteId, $languageVariants): void {
                $query->where('seo_keyword_group_keywords.site_id', $siteId);
                $this->constrainMembershipLanguage($query, $siteId, $languageVariants);
            },
        ]);
        if (KeywordGroupSchema::topicLinkReady()) {
            $groupQuery->withCount('topics');
        }
        $paginator = $groupQuery
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $repIds = $paginator->getCollection()
            ->pluck('representative_keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $phrases = $repIds === []
            ? []
            : Keyword::query()->whereIn('id', $repIds)->pluck('phrase', 'id')->all();

        $groupIds = $paginator->getCollection()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $memberPreview = $this->firstMemberBatches($siteId, $groupIds, self::MEMBER_PAGE_SIZE, $languageVariants);

        $rows = $paginator->getCollection()->map(function ($group) use ($phrases, $memberPreview): array {
            $groupId = (int) $group->id;
            $repId = (int) ($group->representative_keyword_id ?? 0);
            $preview = $memberPreview[$groupId] ?? ['members' => [], 'has_more' => false];

            return [
                'id' => $groupId,
                'name' => (string) $group->name,
                'source' => (string) $group->source,
                'is_locked' => (bool) $group->is_locked,
                'is_manual' => KeywordGroupSource::isManual($group->source),
                'member_count' => (int) ($group->memberships_count ?? 0),
                'topic_count' => (int) ($group->topics_count ?? 0),
                'representative_keyword_id' => $repId > 0 ? $repId : null,
                'representative_phrase' => $repId > 0 ? (string) ($phrases[$repId] ?? '') : '',
                'members' => $preview['members'],
                'members_has_more' => $preview['has_more'],
            ];
        });

        $paginator->setCollection($rows);

        return $paginator;
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    /**
     * @param  list<string>|null  $languageVariants
     */
    public function groupVisible(
        int $siteId,
        int $groupId,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): bool {
        if ($siteId <= 0 || $groupId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return false;
        }

        $query = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId);
        $this->constrainGroupsToLanguage($query, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);

        return $query->exists();
    }

    /**
     * @param  Builder<SeoKeywordGroup>  $query
     * @param  list<string>|null  $languageVariants
     */
    public function constrainVisibleGroups(
        Builder $query,
        int $siteId,
        ?array $languageVariants,
        ?string $languageCode,
        bool $acceptLegacyNullLanguage,
    ): void {
        $this->constrainGroupsToLanguage($query, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
    }

    public function groupCount(
        int $siteId,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): int {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return 0;
        }

        $query = SeoKeywordGroup::query()->where('site_id', $siteId);
        $this->constrainGroupsToLanguage($query, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);

        return (int) $query->count();
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @param  list<int>  $groupIds
     * @return array<int, array{members: list<array<string, mixed>>, has_more: bool}>
     */
    private function firstMemberBatches(int $siteId, array $groupIds, int $limit, ?array $languageVariants = null): array
    {
        $out = [];
        foreach ($groupIds as $groupId) {
            if ($groupId > 0) {
                $out[$groupId] = ['members' => [], 'has_more' => false];
            }
        }
        if ($out === [] || ! KeywordGroupSchema::tablesReady()) {
            return $out;
        }

        $limit = max(1, min(200, $limit));
        $select = ['group_id', 'keyword_id'];
        if (KeywordGroupSchema::topicCandidateReady()) {
            $select[] = 'is_topic_candidate';
        }
        if (KeywordGroupSchema::topicCandidateOverrideReady()) {
            $select[] = 'topic_candidate_override';
        }
        $membershipQuery = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('group_id', array_keys($out));
        $this->constrainMembershipLanguage($membershipQuery, $siteId, $languageVariants);
        $memberships = $membershipQuery
            ->orderBy('group_id')
            ->orderBy('keyword_id')
            ->get($select);

        $counts = [];
        $selectedIds = [];
        /** @var list<array{group_id: int, keyword_id: int, override: ?bool}> $pending */
        $pending = [];
        foreach ($memberships as $membership) {
            $groupId = (int) $membership->group_id;
            $keywordId = (int) $membership->keyword_id;
            $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
            if (count($out[$groupId]['members']) >= $limit) {
                continue;
            }
            $override = $this->readOverride($membership);
            $pending[] = [
                'group_id' => $groupId,
                'keyword_id' => $keywordId,
                'override' => $override,
            ];
            $selectedIds[$keywordId] = $keywordId;
        }

        $phrases = $selectedIds === []
            ? []
            : Keyword::query()->whereIn('id', array_values($selectedIds))->pluck('phrase', 'id')->all();
        $focusMap = $this->batchFocusMap($siteId, array_values($selectedIds));

        foreach ($pending as $row) {
            $groupId = $row['group_id'];
            $keywordId = $row['keyword_id'];
            $out[$groupId]['members'][] = $this->memberPayload(
                $keywordId,
                (string) ($phrases[$keywordId] ?? ''),
                $row['override'],
                isset($focusMap[$keywordId]),
            );
        }

        foreach ($out as $groupId => $payload) {
            $out[$groupId]['has_more'] = ($counts[$groupId] ?? 0) > $limit;
        }

        return $out;
    }

    /**
     * 1-based page that contains the Group, or null when not found.
     */
    public function pageForGroup(
        int $siteId,
        int $groupId,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): ?int {
        if ($siteId <= 0 || $groupId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return null;
        }

        $perPage = max(1, min(50, $perPage));
        $existsQuery = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId);
        $this->constrainGroupsToLanguage($existsQuery, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
        if (! $existsQuery->exists()) {
            return null;
        }

        // Same authority as paginateGroups(): orderBy id.
        $positionQuery = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->where('id', '<=', $groupId);
        $this->constrainGroupsToLanguage($positionQuery, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
        $position = (int) $positionQuery->count();

        if ($position <= 0) {
            return null;
        }

        return (int) max(1, (int) ceil($position / $perPage));
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return array{
     *     members: list<array<string, mixed>>,
     *     has_more: bool,
     *     total: int
     * }
     */
    public function groupMembers(
        int $siteId,
        int $groupId,
        int $limit = self::MEMBER_PAGE_SIZE,
        int $offset = 0,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        if ($siteId <= 0 || $groupId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return ['members' => [], 'has_more' => false, 'total' => 0];
        }

        $exists = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId);
        $this->constrainGroupsToLanguage($exists, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
        if (! $exists->exists()) {
            return ['members' => [], 'has_more' => false, 'total' => 0];
        }

        $totalQuery = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId);
        $this->constrainMembershipLanguage($totalQuery, $siteId, $languageVariants);
        $total = (int) $totalQuery->count();

        $select = ['keyword_id'];
        if (KeywordGroupSchema::topicCandidateReady()) {
            $select[] = 'is_topic_candidate';
        }
        if (KeywordGroupSchema::topicCandidateOverrideReady()) {
            $select[] = 'topic_candidate_override';
        }
        $membershipQuery = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->where('group_id', $groupId);
        $this->constrainMembershipLanguage($membershipQuery, $siteId, $languageVariants);
        $memberships = $membershipQuery
            ->orderBy('keyword_id')
            ->offset($offset)
            ->limit($limit)
            ->get($select);

        $ids = $memberships->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
        $phrases = $ids === []
            ? []
            : Keyword::query()->whereIn('id', $ids)->pluck('phrase', 'id')->all();
        $focusMap = $this->batchFocusMap($siteId, $ids);

        $members = [];
        foreach ($memberships as $membership) {
            $keywordId = (int) $membership->keyword_id;
            $members[] = $this->memberPayload(
                $keywordId,
                (string) ($phrases[$keywordId] ?? ''),
                $this->readOverride($membership),
                isset($focusMap[$keywordId]),
            );
        }

        return [
            'members' => $members,
            'has_more' => ($offset + count($members)) < $total,
            'total' => $total,
        ];
    }

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function unassignedCount(
        int $siteId,
        ?array $languageVariants = null,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): int {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return app(KeywordUiInventoryQuery::class)->count($siteId, $languageVariants);
        }

        return (int) $this->unassignedBaseQuery($siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage)->count();
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{keyword_id: int, phrase: string}>
     */
    public function searchUnassigned(
        int $siteId,
        string $query,
        ?array $languageVariants = null,
        int $limit = self::SEARCH_LIMIT,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): array {
        $limit = max(1, min(50, $limit));
        $needle = trim($query);
        if ($siteId <= 0 || $needle === '') {
            return [];
        }

        $like = '%'.addcslashes(mb_strtolower($needle, 'UTF-8'), '%_\\').'%';
        $rows = $this->unassignedBaseQuery($siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage)
            ->whereRaw('LOWER(phrase) LIKE ?', [$like])
            ->orderBy('phrase')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'phrase']);

        $out = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->id;
            $phrase = trim((string) $row->phrase);
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
            ];
        }

        return $out;
    }

    /**
     * Bounded unassigned inventory for rename-triggered semantic neighbor search.
     *
     * Prefers lexical token overlap when inventory is large, then caps candidates.
     *
     * @param  list<string>|null  $languageVariants
     * @return list<array{keyword_id: int, phrase: string}>
     */
    public function unassignedCandidatesForSemanticSearch(
        int $siteId,
        string $query,
        ?array $languageVariants = null,
        int $maxCandidates = 1000,
    ): array {
        $maxCandidates = max(1, min(2000, $maxCandidates));
        if ($siteId <= 0) {
            return [];
        }

        $base = $this->unassignedBaseQuery($siteId, $languageVariants);
        $tokens = $this->queryTokens($query);
        if ($tokens !== []) {
            $base->where(function (Builder $inner) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.addcslashes($token, '%_\\').'%';
                    $inner->orWhereRaw('LOWER(phrase) LIKE ?', [$like]);
                }
            });
        }

        $rows = $base->orderBy('id')->limit($maxCandidates)->get(['id', 'phrase']);
        if ($rows->isEmpty() && $tokens !== []) {
            $rows = $this->unassignedBaseQuery($siteId, $languageVariants)
                ->orderBy('id')
                ->limit($maxCandidates)
                ->get(['id', 'phrase']);
        }

        $out = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->id;
            $phrase = trim((string) $row->phrase);
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     keyword_id: int,
     *     phrase: string,
     *     has_focus_article: bool,
     *     topic_candidate_override: ?bool,
     *     effective_topic_candidate: bool,
     *     is_topic_candidate: bool
     * }
     */
    private function memberPayload(int $keywordId, string $phrase, ?bool $override, bool $hasFocus): array
    {
        $effective = KeywordGroupSchema::topicCandidateOverrideReady()
            ? KeywordGroupTopicCandidatePolicy::effectiveCandidate($hasFocus, $override)
            : ($override !== false);

        return [
            'keyword_id' => $keywordId,
            'phrase' => $phrase,
            'has_focus_article' => $hasFocus,
            'topic_candidate_override' => KeywordGroupSchema::topicCandidateOverrideReady() ? $override : null,
            'effective_topic_candidate' => $effective,
            // BC: legacy "blocked" flag — false only when FORCE_BLOCK.
            'is_topic_candidate' => KeywordGroupTopicCandidatePolicy::legacyIsTopicCandidate($override),
        ];
    }

    private function readOverride(SeoKeywordGroupKeyword $membership): ?bool
    {
        if (KeywordGroupSchema::topicCandidateOverrideReady()) {
            return $membership->topicCandidateOverride();
        }
        if (KeywordGroupSchema::topicCandidateReady()) {
            return (bool) ($membership->is_topic_candidate ?? true) ? null : false;
        }

        return null;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, int>
     */
    private function batchFocusMap(int $siteId, array $keywordIds): array
    {
        if ($keywordIds === [] || ! KeywordGroupSchema::topicCandidateOverrideReady()) {
            return [];
        }

        $counter = $this->focusCounter ?? app(TopicLinkedArticleCounter::class);

        return $counter->focusArticleIdMapForKeywords($siteId, $keywordIds);
    }

    /**
     * @return list<string>
     */
    private function queryTokens(string $query): array
    {
        $normalized = mb_strtolower(trim($query), 'UTF-8');
        if ($normalized === '') {
            return [];
        }
        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 2) {
                continue;
            }
            $tokens[$part] = $part;
        }

        return array_values($tokens);
    }

    /**
     * Filter groups by Group name only (SQL LIKE). Not member keywords / topics.
     *
     * @param  Builder<SeoKeywordGroup>  $query
     */
    private function constrainGroupsToSearch(Builder $query, int $siteId, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.addcslashes(mb_strtolower($needle, 'UTF-8'), '%_\\').'%';
        $query->whereRaw('LOWER(name) LIKE ?', [$like]);
    }

    /**
     * Semantic groups follow the analysis run for the selected language.
     * Manual groups stay membership-scoped and are not relabeled.
     *
     * @param  Builder<SeoKeywordGroup>  $query
     * @param  list<string>|null  $languageVariants
     */
    private function constrainGroupsToLanguage(
        Builder $query,
        int $siteId,
        ?array $languageVariants,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): void {
        $languageCode = $this->normalizeLanguageCode($languageCode);
        if ($languageVariants === null && $languageCode === null) {
            return;
        }

        $query->where(function (Builder $scope) use ($siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage): void {
            $scope->where(function (Builder $semantic) use ($siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage): void {
                $semantic->where('source', KeywordGroupSource::SEMANTIC);
                $this->constrainSemanticGroups($semantic, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
            })->orWhere(function (Builder $manual) use ($siteId, $languageVariants): void {
                $manual->where('source', '!=', KeywordGroupSource::SEMANTIC);
                $this->constrainGroupsByMembership($manual, $siteId, $languageVariants);
            });
        });
    }

    /**
     * @param  Builder<SeoKeywordGroup>  $query
     * @param  list<string>|null  $languageVariants
     */
    private function constrainSemanticGroups(
        Builder $query,
        int $siteId,
        ?array $languageVariants,
        ?string $languageCode,
        bool $acceptLegacyNullLanguage,
    ): void {
        if ($languageCode === null) {
            $this->constrainGroupsByMembership($query, $siteId, $languageVariants);

            return;
        }

        $hashes = $this->semanticDatasetHashes($siteId, $languageCode, $acceptLegacyNullLanguage);
        $query->where(function (Builder $matched) use ($siteId, $languageVariants, $hashes): void {
            if ($hashes !== []) {
                $matched->whereIn('input_hash', $hashes);
                $matched->orWhere(function (Builder $untagged) use ($siteId, $languageVariants): void {
                    $this->constrainUntaggedSemanticGroup($untagged, $siteId, $languageVariants);
                });

                return;
            }

            $this->constrainUntaggedSemanticGroup($matched, $siteId, $languageVariants);
        });
    }

    /**
     * @param  Builder<SeoKeywordGroup>  $query
     * @param  list<string>|null  $languageVariants
     */
    private function constrainUntaggedSemanticGroup(Builder $query, int $siteId, ?array $languageVariants): void
    {
        $query->where(function (Builder $blank): void {
            $blank->whereNull('input_hash')->orWhere('input_hash', '');
        });
        $this->constrainGroupsByMembership($query, $siteId, $languageVariants);
    }

    /**
     * @param  Builder<SeoKeywordGroup>  $query
     * @param  list<string>|null  $languageVariants
     */
    private function constrainGroupsByMembership(Builder $query, int $siteId, ?array $languageVariants): void
    {
        if ($languageVariants === null) {
            return;
        }

        $query->whereHas('memberships', function ($memberships) use ($siteId, $languageVariants): void {
            $memberships->where('seo_keyword_group_keywords.site_id', $siteId);
            $this->constrainMembershipLanguage($memberships, $siteId, $languageVariants);
        });
    }

    /**
     * @return list<string>
     */
    private function semanticDatasetHashes(int $siteId, string $languageCode, bool $acceptLegacyNullLanguage): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_group_analysis_runs')) {
            return [];
        }

        $query = SeoKeywordGroupAnalysisRun::query()
            ->where('site_id', $siteId)
            ->whereNotNull('dataset_hash')
            ->where('dataset_hash', '!=', '');
        $query->where(function (Builder $languages) use ($languageCode, $acceptLegacyNullLanguage): void {
            $languages->where('language', $languageCode);
            if ($acceptLegacyNullLanguage) {
                $languages->orWhereNull('language');
            }
        });

        return $query->pluck('dataset_hash')
            ->map(static fn ($hash): string => (string) $hash)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeLanguageCode(?string $languageCode): ?string
    {
        $languageCode = trim((string) $languageCode);

        return $languageCode === '' ? null : mb_substr($languageCode, 0, 32);
    }

    /**
     * @param  Builder<SeoKeywordGroupKeyword>  $query
     * @param  list<string>|null  $languageVariants
     */
    private function constrainMembershipLanguage(Builder $query, int $siteId, ?array $languageVariants): void
    {
        if ($languageVariants === null) {
            return;
        }

        $query->whereIn(
            'keyword_id',
            app(KeywordUiInventoryQuery::class)->keywordIdSubquery($siteId, $languageVariants),
        );
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return Builder<Keyword>
     */
    private function unassignedBaseQuery(
        int $siteId,
        ?array $languageVariants,
        ?string $languageCode = null,
        bool $acceptLegacyNullLanguage = false,
    ): Builder {
        $query = app(KeywordUiInventoryQuery::class)->baseQuery($siteId, $languageVariants);
        if (! KeywordGroupSchema::tablesReady()) {
            return $query;
        }

        $assigned = SeoKeywordGroupKeyword::query()
            ->where('seo_keyword_group_keywords.site_id', $siteId)
            ->select('seo_keyword_group_keywords.keyword_id');
        $languageCode = $this->normalizeLanguageCode($languageCode);
        if ($languageCode !== null) {
            $visibleGroups = SeoKeywordGroup::query()->where('site_id', $siteId);
            $this->constrainGroupsToLanguage($visibleGroups, $siteId, $languageVariants, $languageCode, $acceptLegacyNullLanguage);
            $visibleIds = $visibleGroups->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($visibleIds === []) {
                return $query;
            }
            $assigned->whereIn('group_id', $visibleIds);
        }

        return $query->whereNotIn('id', $assigned);
    }
}
