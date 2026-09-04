<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;

final class KeywordClusterQuery
{
    public function __construct(
        private readonly KeywordGroupCoverageBuilder $coverageBuilder,
        private readonly KeywordClusterEligibility $eligibility,
    ) {}

    public function classificationsReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications');
    }

    /**
     * @return array{
     *     total_keywords: int,
     *     classified_keywords: int,
     *     seo_eligible_keywords: int,
     *     clustered: int,
     *     unclustered: int,
     *     unclassified_keywords: int,
     *     non_seo_keywords: int,
     *     non_seo_but_clustered: int,
     *     topic_clusters: int,
     *     system_groups: int,
     *     custom_groups: int,
     * }
     */
    public function summary(?int $siteId, ?array $languageVariants = null): array
    {
        // SSOT: Dictionary UI inventory IDs — never a broader forSite-only scope.
        $keywordIds = app(KeywordUiInventoryQuery::class)
            ->keywordIds($siteId, $languageVariants);

        return $this->eligibility->summaryMetricsForKeywordIds($keywordIds);
    }

    /**
     * @param  array{
     *     search?: string,
     *     coverage?: string,
     *     has_articles?: bool,
     *     sort?: string,
     *     projection?: 'mcp'|'seo',
     *     language_variants?: list<string>|null
     * }  $filters
     * @param  int|null  $page  Explicit page (Livewire). Null falls back to request query — avoid on Livewire re-renders.
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateClusters(
        ?int $siteId,
        array $filters,
        int $perPage = 25,
        ?string $path = null,
        ?int $page = null,
    ): LengthAwarePaginator {
        $resolvedPage = max(1, $page ?? (int) request()->integer('page', 1));

        if (! $this->classificationsReady()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $resolvedPage, [
                'path' => $path ?? '/',
            ]);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $coverage = trim((string) ($filters['coverage'] ?? ''));
        $hasArticles = (bool) ($filters['has_articles'] ?? false);
        $sort = trim((string) ($filters['sort'] ?? 'mcp_share_desc'));
        $projection = trim((string) ($filters['projection'] ?? 'mcp'));
        if ($projection !== 'seo') {
            $projection = 'mcp';
        }
        $languageVariants = is_array($filters['language_variants'] ?? null)
            ? $filters['language_variants']
            : null;

        $rows = $this->clusterAggregates($siteId, 500, $languageVariants);
        $shareMap = ($siteId !== null && $siteId > 0)
            ? app(SiteMcpClusterTopicalProfileBuilder::class)->topicalShareMap($siteId)
            : [];
        $planningMap = ($siteId !== null && $siteId > 0)
            ? $this->mcpPlanningCountMap($siteId)
            : [];
        $exclusionMap = ($siteId !== null && $siteId > 0)
            ? app(ClusterExclusionService::class)->flagsMapForSite($siteId)
            : [];
        $mcpGroupMap = ($siteId !== null && $siteId > 0)
            ? app(McpTopicGroupService::class)->membershipMapForSite($siteId)
            : [];
        $canonicalByKey = $this->canonicalLabelsForKeys(
            $siteId,
            array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->cluster_key ?? '')),
                $rows,
            ))),
        );
        $items = [];
        $seenKeys = [];
        foreach ($rows as $row) {
            $key = (string) ($row->cluster_key ?? '');
            if ($key === '') {
                continue;
            }
            $seenKeys[$key] = true;
            $label = $canonicalByKey[$key]
                ?? $this->displayLabel($key, (string) ($row->sample_phrase ?? ''), $siteId);
            // SEO projection filters by label; MCP projection filters after collapse (mask + members).
            if ($projection === 'seo' && $search !== ''
                && ! str_contains(mb_strtolower($label.' '.$key), mb_strtolower($search))) {
                continue;
            }
            $articleCount = (int) ($row->article_count ?? 0);
            if ($projection === 'seo' && $hasArticles && $articleCount < 1) {
                continue;
            }
            $keywordCount = (int) ($row->keyword_count ?? 0);
            $score = $this->coverageBuilder->score(
                $keywordCount,
                $articleCount,
                (int) ($row->dna_branch_count ?? 0),
                (int) ($row->intent_diversity ?? 0),
            );
            if ($coverage !== '' && $score !== $coverage) {
                continue;
            }
            $flags = $exclusionMap[$key] ?? ['mcp_excluded' => false, 'seo_excluded' => false];
            $items[] = $this->clusterListItem(
                $key,
                $label,
                $keywordCount,
                $articleCount,
                (string) ($row->top_intent ?? ''),
                $score,
                (float) ($shareMap[$key] ?? 0.0),
                $siteId,
                canonicalSource: $this->canonicalSourceForKey($siteId, $key),
                state: $keywordCount === 0 ? 'planned' : 'active',
                mcpExcluded: (bool) ($flags['mcp_excluded'] ?? false),
                seoExcluded: (bool) ($flags['seo_excluded'] ?? false),
                internalLinkCount: (int) ($row->internal_link_count ?? 0),
                mcpGroup: $mcpGroupMap[$key] ?? null,
                planningPendingCount: (int) ($planningMap[$key] ?? 0),
            );
        }

        foreach ($this->manualEmptyClusterRows($siteId) as $manualRow) {
            $key = (string) ($manualRow['cluster_key'] ?? '');
            if ($key === '' || isset($seenKeys[$key])) {
                continue;
            }
            $label = (string) ($manualRow['label'] ?? $key);
            if ($projection === 'seo' && $search !== ''
                && ! str_contains(mb_strtolower($label.' '.$key), mb_strtolower($search))) {
                continue;
            }
            if ($projection === 'seo' && $hasArticles) {
                continue;
            }
            if ($coverage !== '' && $coverage !== 'unknown') {
                continue;
            }
            $flags = $exclusionMap[$key] ?? ['mcp_excluded' => false, 'seo_excluded' => false];
            $items[] = $this->clusterListItem(
                $key,
                $label,
                0,
                0,
                '',
                'unknown',
                0.0,
                $siteId,
                SeoTopicClusterMeta::SOURCE_MANUAL,
                'planned',
                (bool) ($flags['mcp_excluded'] ?? false),
                (bool) ($flags['seo_excluded'] ?? false),
                0,
                $mcpGroupMap[$key] ?? null,
                planningPendingCount: (int) ($planningMap[$key] ?? 0),
            );
        }

        if ($projection === 'mcp' && $siteId !== null && $siteId > 0) {
            $items = $this->collapseToMcpProjection($siteId, $items, $search, $hasArticles, $coverage);
        }

        usort($items, static function (array $a, array $b) use ($sort): int {
            return self::compareClusterRows($a, $b, $sort);
        });

        $total = count($items);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        if ($resolvedPage > $lastPage) {
            $resolvedPage = $lastPage;
        }
        $slice = array_slice($items, ($resolvedPage - 1) * $perPage, $perPage);
        $resolvedPath = is_string($path) && $path !== ''
            ? $path
            : (string) (request()->routeIs('livewire.*') || str_contains((string) request()->path(), 'livewire/')
                ? '/'
                : request()->url());

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $resolvedPage,
            ['path' => $resolvedPath],
        );
    }

    /**
     * Collapse peer MCP groups into one projection row keyed by mask_name.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function collapseToMcpProjection(
        int $siteId,
        array $items,
        string $search,
        bool $hasArticles,
        string $coverage,
    ): array {
        $groups = app(McpTopicGroupService::class)->groupsForSite($siteId);
        if ($groups === []) {
            return $this->filterMcpProjectionSearch($items, $search);
        }

        $byKey = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['cluster_key'] ?? ''));
            if ($key !== '') {
                $byKey[$key] = $item;
            }
        }

        $consumed = [];
        $out = [];
        foreach ($groups as $group) {
            $memberKeys = $group['member_keys'];
            $members = [];
            foreach ($memberKeys as $memberKey) {
                if (! isset($byKey[$memberKey])) {
                    continue;
                }
                $row = $byKey[$memberKey];
                // Skip/SEO exclude members do not contribute to MCP projection row.
                if (! empty($row['mcp_excluded']) || ! empty($row['seo_excluded'])) {
                    continue;
                }
                $members[] = $row;
            }
            if ($members === []) {
                continue;
            }

            $contributingKeys = array_map(
                static fn (array $row): string => (string) $row['cluster_key'],
                $members,
            );
            foreach ($contributingKeys as $key) {
                $consumed[$key] = true;
            }

            $keywordIds = [];
            foreach ($contributingKeys as $key) {
                foreach ($this->memberKeywordIds($siteId, $key) as $id) {
                    $keywordIds[(int) $id] = true;
                }
            }
            $linkStats = $this->memberLinkStats(array_keys($keywordIds));
            $keywordCount = array_sum(array_map(
                static fn (array $row): int => (int) ($row['keyword_count'] ?? 0),
                $members,
            ));
            $articleCount = (int) ($linkStats['article_count'] ?? 0);
            $internalLinkCount = (int) ($linkStats['internal_link_count'] ?? 0);
            $intent = (string) ($members[0]['intent'] ?? '');
            $score = $this->coverageBuilder->score(
                $keywordCount,
                $articleCount,
                0,
                $intent !== '' ? 1 : 0,
            );
            $share = (float) ($members[0]['topical_share'] ?? 0.0);
            $planningPending = 0;
            foreach ($members as $memberRow) {
                $planningPending += (int) ($memberRow['planning_pending_count'] ?? 0);
            }
            $maskName = trim((string) ($group['mask_name'] ?? ''));
            if ($maskName === '') {
                $maskName = (string) ($members[0]['label'] ?? $group['group_ref']);
            }

            $memberCards = [];
            foreach ($memberKeys as $memberKey) {
                if (! isset($byKey[$memberKey])) {
                    continue;
                }
                $memberCards[] = [
                    'cluster_key' => $memberKey,
                    'label' => (string) ($byKey[$memberKey]['label'] ?? $memberKey),
                ];
            }

            $item = $this->clusterListItem(
                (string) $group['group_ref'],
                $maskName,
                $keywordCount,
                $articleCount,
                $intent,
                $score,
                $share,
                $siteId,
                canonicalSource: SeoTopicClusterMeta::SOURCE_MANUAL,
                state: $keywordCount === 0 ? 'planned' : 'active',
                mcpExcluded: false,
                seoExcluded: false,
                internalLinkCount: $internalLinkCount,
                mcpGroup: null,
                planningPendingCount: $planningPending,
            );
            $item['is_mcp_group'] = true;
            $item['mcp_member_count'] = count($memberCards);
            $item['mcp_members'] = $memberCards;
            $item['search_blob'] = mb_strtolower(
                $maskName.' '.implode(' ', array_column($memberCards, 'label')),
                'UTF-8',
            );

            if ($hasArticles && $articleCount < 1) {
                continue;
            }
            if ($coverage !== '' && $score !== $coverage) {
                continue;
            }
            $out[] = $item;
        }

        foreach ($items as $item) {
            $key = trim((string) ($item['cluster_key'] ?? ''));
            if ($key === '' || isset($consumed[$key])) {
                continue;
            }
            $item['is_mcp_group'] = false;
            $item['mcp_members'] = [];
            $item['mcp_member_count'] = 0;
            $item['search_blob'] = mb_strtolower((string) ($item['label'] ?? '').' '.$key, 'UTF-8');
            $out[] = $item;
        }

        return $this->filterMcpProjectionSearch($out, $search);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function filterMcpProjectionSearch(array $items, string $search): array
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');
        if ($needle === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static function (array $item) use ($needle): bool {
                $blob = (string) ($item['search_blob'] ?? '');
                if ($blob === '') {
                    $blob = mb_strtolower(
                        (string) ($item['label'] ?? '').' '.(string) ($item['cluster_key'] ?? ''),
                        'UTF-8',
                    );
                }

                return str_contains($blob, $needle);
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareClusterRows(array $a, array $b, string $sort): int
    {
        $excludedRank = static function (array $row): int {
            return ((bool) ($row['mcp_excluded'] ?? false) || (bool) ($row['seo_excluded'] ?? false)) ? 1 : 0;
        };

        // Excluded clusters sink to bottom for MCP share sorts.
        if (in_array($sort, ['mcp_share_desc', 'mcp_share_asc', ''], true)) {
            $byExcluded = $excludedRank($a) <=> $excludedRank($b);
            if ($byExcluded !== 0) {
                return $byExcluded;
            }
        }

        return match ($sort) {
            'mcp_share_asc' => ((float) ($a['topical_share'] ?? 0)) <=> ((float) ($b['topical_share'] ?? 0))
                ?: ((int) ($b['article_count'] ?? 0)) <=> ((int) ($a['article_count'] ?? 0)),
            'articles_desc' => ((int) ($b['article_count'] ?? 0)) <=> ((int) ($a['article_count'] ?? 0))
                ?: ((float) ($b['topical_share'] ?? 0)) <=> ((float) ($a['topical_share'] ?? 0)),
            'articles_asc' => ((int) ($a['article_count'] ?? 0)) <=> ((int) ($b['article_count'] ?? 0))
                ?: ((float) ($a['topical_share'] ?? 0)) <=> ((float) ($b['topical_share'] ?? 0)),
            'keywords_desc' => ((int) ($b['keyword_count'] ?? 0)) <=> ((int) ($a['keyword_count'] ?? 0)),
            'keywords_asc' => ((int) ($a['keyword_count'] ?? 0)) <=> ((int) ($b['keyword_count'] ?? 0)),
            'links_desc' => ((int) ($b['internal_link_count'] ?? 0)) <=> ((int) ($a['internal_link_count'] ?? 0)),
            'links_asc' => ((int) ($a['internal_link_count'] ?? 0)) <=> ((int) ($b['internal_link_count'] ?? 0)),
            'name_asc' => strcmp(mb_strtolower((string) ($a['label'] ?? '')), mb_strtolower((string) ($b['label'] ?? ''))),
            'name_desc' => strcmp(mb_strtolower((string) ($b['label'] ?? '')), mb_strtolower((string) ($a['label'] ?? ''))),
            default => ((float) ($b['topical_share'] ?? 0)) <=> ((float) ($a['topical_share'] ?? 0))
                ?: ((int) ($b['article_count'] ?? 0)) <=> ((int) ($a['article_count'] ?? 0))
                ?: ((int) ($b['keyword_count'] ?? 0)) <=> ((int) ($a['keyword_count'] ?? 0)),
        };
    }

    /**
     * Avoid loading 14k clusters into PHP when possible — aggregate in SQL first.
     *
     * @return list<object>
     */
    public function clusterAggregates(?int $siteId, int $limit = 500, ?array $languageVariants = null): array
    {
        if (! $this->classificationsReady()) {
            return [];
        }

        $languageBindings = ($languageVariants !== null && $languageVariants !== [])
            ? array_values($languageVariants)
            : [];
        // SSOT: Dictionary UI inventory for cluster list keyword counts.
        $keywordIds = app(KeywordUiInventoryQuery::class)
            ->keywordIdSubquery($siteId, $languageVariants);
        $dnaJoin = '';
        $dnaSelect = '0 as dna_branch_count';
        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
            $dnaSelect = 'COUNT(DISTINCT d.normalized_value) as dna_branch_count';
            $dnaJoin = ' LEFT JOIN seo_keyword_dna d ON d.keyword_id = c.keyword_id AND d.cluster_key = c.cluster_key';
        }

        $articleJoin = '';
        $articleSelect = '0 as article_count, 0 as internal_link_count';
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            if ($languageBindings !== []) {
                $articlePlaceholders = implode(',', array_fill(0, count($languageBindings), '?'));
                // article_count overwritten below from Focus Article SSOT (main_article_id ∪ target).
                $articleSelect = '0 as article_count,'
                    .' COUNT(lm_src.id) as internal_link_count';
                $articleJoin = ' LEFT JOIN seo_link_maps lm ON lm.keyword_id = c.keyword_id'
                    .' LEFT JOIN articles lm_src ON lm_src.id = lm.source_article_id'
                    .' AND lm_src.language IN ('.$articlePlaceholders.')';
            } else {
                $articleSelect = '0 as article_count,'
                    .' COUNT(lm.id) as internal_link_count';
                $articleJoin = ' LEFT JOIN seo_link_maps lm ON lm.keyword_id = c.keyword_id';
            }
        }

        $hiddenJoin = '';
        $hiddenWhere = '';
        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $hiddenJoin = ' LEFT JOIN keyword_meta hm ON hm.keyword_id = c.keyword_id'
                .' AND hm.meta_key = \''.\Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::SeoHidden->value.'\''
                .' AND hm.meta_value = \'1\'';
            $hiddenWhere = ' AND hm.keyword_id IS NULL';
        }

        $sql = 'SELECT c.cluster_key,'
            .' COUNT(DISTINCT c.keyword_id) as keyword_count,'
            .' MIN(k.phrase) as sample_phrase,'
            .' COUNT(DISTINCT c.seo_intent) as intent_diversity,'
            .' SUBSTRING_INDEX(GROUP_CONCAT(c.seo_intent ORDER BY c.keyword_id), \',\', 1) as top_intent,'
            .' '.$articleSelect.','
            .' '.$dnaSelect
            .' FROM seo_keyword_classifications c'
            .' INNER JOIN keywords k ON k.id = c.keyword_id'
            .$articleJoin
            .$dnaJoin
            .$hiddenJoin
            .' WHERE c.cluster_key IS NOT NULL AND c.cluster_key <> \'\''
            .' AND c.keyword_id IN ('.$keywordIds->toSql().')'
            .$hiddenWhere
            .' GROUP BY c.cluster_key'
            .' ORDER BY keyword_count DESC'
            .' LIMIT '.(int) $limit;

        $bindings = $languageBindings !== []
            ? array_merge($languageBindings, $keywordIds->getBindings())
            : $keywordIds->getBindings();

        $rows = DB::connection('omi_seo_ai')->select($sql, $bindings);

        $focusCounts = $this->focusArticleCountsByClusterKey(
            array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->cluster_key ?? '')),
                $rows,
            ))),
            $siteId,
            $languageVariants,
        );
        foreach ($rows as $row) {
            $key = trim((string) ($row->cluster_key ?? ''));
            $row->article_count = (int) ($focusCounts[$key] ?? 0);
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function countsForKeys(?int $siteId, array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if ($keys === [] || ! $this->classificationsReady()) {
            return [];
        }

        $keywordIds = $this->keywordIdSubquery($siteId);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT c.cluster_key, COUNT(DISTINCT c.keyword_id) as keyword_count'
            .' FROM seo_keyword_classifications c'
            .' WHERE c.cluster_key IN ('.$placeholders.')'
            .' AND c.keyword_id IN ('.$keywordIds->toSql().')'
            .' GROUP BY c.cluster_key';
        $rows = DB::connection('omi_seo_ai')->select($sql, array_merge($keys, $keywordIds->getBindings()));
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->cluster_key] = (int) $row->keyword_count;
        }

        return $out;
    }

    /**
     * Canonical cluster title SSOT: seo_topic_cluster_meta.canonical_phrase.
     * Fallback: cluster_key pretty (strip uniqueness hash) → last-resort sample member phrase.
     */
    public function displayLabel(string $clusterKey, string $samplePhrase = '', ?int $siteId = null): string
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '') {
            return '';
        }

        $canonical = $this->canonicalLabel($clusterKey, $siteId);
        if ($canonical !== '') {
            return $canonical;
        }

        $fromKey = $this->prettyLabelFromClusterKey($clusterKey);
        if ($fromKey !== '') {
            return $fromKey;
        }

        $sample = trim($samplePhrase);

        return $sample !== ''
            ? (new KeywordCanonicalizer())->prettyLabel($sample)
            : $clusterKey;
    }

    /**
     * Humanize cluster_key without TopicClusterClusterKeyGenerator uniqueness suffixes
     * (e.g. vai_canvas__785f3e → "Vai Canvas").
     */
    private function prettyLabelFromClusterKey(string $clusterKey): string
    {
        $slug = $clusterKey;
        // base__hex6, optionally followed by _hex disambiguators from generate().
        if (preg_match('/^(.+?)__[a-f0-9]{6}(?:_[a-f0-9]{4,})*$/i', $slug, $matches) === 1) {
            $slug = (string) $matches[1];
        }

        $fromKey = trim(str_replace('_', ' ', $slug));
        if ($fromKey === '') {
            return '';
        }

        return mb_convert_case($fromKey, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  list<string>  $clusterKeys
     * @return array<string, string>
     */
    public function canonicalLabelsForKeys(?int $siteId, array $clusterKeys): array
    {
        $clusterKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $clusterKeys,
        ))));
        if ($clusterKeys === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }

        $query = SeoTopicClusterMeta::query()->whereIn('cluster_key', $clusterKeys);
        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $out = [];
        foreach ($query->get(['cluster_key', 'canonical_phrase']) as $meta) {
            $key = trim((string) $meta->cluster_key);
            $phrase = trim((string) $meta->canonical_phrase);
            if ($key !== '' && $phrase !== '' && ! isset($out[$key])) {
                $out[$key] = $phrase;
            }
        }

        return $out;
    }

    public function canonicalLabel(string $clusterKey, ?int $siteId = null): string
    {
        $map = $this->canonicalLabelsForKeys($siteId, [$clusterKey]);

        return $map[trim($clusterKey)] ?? '';
    }

    public function unclusteredListUrl(?int $siteId): string
    {
        return app(DomainContextResolver::class)->appendSiteToUrl(
            \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource::getUrl('index').'?cluster=_none',
            $siteId,
        );
    }

    /**
     * @return list<int>
     */
    public function memberKeywordIds(?int $siteId, string $clusterKey, ?array $languageVariants = null): array
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || ! $this->classificationsReady()) {
            return [];
        }

        $query = SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey);

        if ($siteId !== null && $siteId > 0) {
            $ids = app(KeywordUiInventoryQuery::class)
                ->baseQuery($siteId, $languageVariants)
                ->select('id');
            $query->whereIn('keyword_id', $ids);
        }

        return $query->limit(5000)->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function clusterExists(string $clusterKey, ?int $siteId = null): bool
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || ! $this->classificationsReady()) {
            return false;
        }

        if (SeoKeywordClassification::query()->where('cluster_key', $clusterKey)->exists()) {
            return true;
        }

        if ($siteId === null || $siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return false;
        }

        return SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->exists();
    }

    /**
     * @return list<array{cluster_key: string, label: string}>
     */
    private function manualEmptyClusterRows(?int $siteId): array
    {
        if ($siteId === null || $siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }

        $rows = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->get(['cluster_key', 'canonical_phrase', 'canonical_source']);

        $out = [];
        foreach ($rows as $meta) {
            $key = trim((string) $meta->cluster_key);
            if ($key === '') {
                continue;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')
                && ! $meta->isManual()) {
                continue;
            }
            if (count($this->memberKeywordIds($siteId, $key)) > 0) {
                continue;
            }
            $label = trim((string) $meta->canonical_phrase);
            $out[] = [
                'cluster_key' => $key,
                'label' => $label !== '' ? $label : $this->displayLabel($key, '', $siteId),
            ];
        }

        return $out;
    }

    /**
     * Focus-article + internal-link counts for a set of member keywords.
     * SSOT used by Cluster Detail; Index SQL must mirror these definitions.
     *
     * - article_count: COUNT(DISTINCT article_id) from keyword_meta.main_article_id
     *   UNION seo_link_maps.target_article_id (same article never double-counted)
     * - internal_link_count: COUNT(*) of seo_link_maps rows for those keywords
     *
     * @param  list<int>  $keywordIds
     * @return array{article_count: int, internal_link_count: int}
     */
    public function memberLinkStats(array $keywordIds, ?array $languageVariants = null): array
    {
        $keywordIds = array_values(array_filter(array_map('intval', $keywordIds)));
        if ($keywordIds === []) {
            return [
                'article_count' => 0,
                'internal_link_count' => 0,
            ];
        }

        /** @var array<int, true> $articleIds */
        $articleIds = [];

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $mainQuery = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->whereIn('keyword_id', $keywordIds)
                ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '');

            foreach ($mainQuery->pluck('meta_value') as $raw) {
                $aid = (int) $raw;
                if ($aid > 0) {
                    $articleIds[$aid] = true;
                }
            }

            if ($languageVariants !== null && $languageVariants !== [] && $articleIds !== []) {
                $allowed = DB::connection('omi_seo_ai')->table('articles')
                    ->whereIn('id', array_keys($articleIds))
                    ->whereIn('language', $languageVariants)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $allowedMap = array_fill_keys($allowed, true);
                $articleIds = array_intersect_key($articleIds, $allowedMap);
            }
        }

        $linkCount = 0;
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $articleQuery = \Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap::query()
                ->whereIn('keyword_id', $keywordIds)
                ->whereNotNull('target_article_id');

            $linkQuery = \Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap::query()
                ->whereIn('keyword_id', $keywordIds);

            if ($languageVariants !== null && $languageVariants !== []) {
                $articleQuery->whereHas(
                    'targetArticle',
                    static fn (EloquentBuilder $q): EloquentBuilder => $q->whereIn('language', $languageVariants),
                );
                $linkQuery->whereHas(
                    'sourceArticle',
                    static fn (EloquentBuilder $q): EloquentBuilder => $q->whereIn('language', $languageVariants),
                );
            }

            foreach ((clone $articleQuery)->pluck('target_article_id') as $tid) {
                $aid = (int) $tid;
                if ($aid > 0) {
                    $articleIds[$aid] = true;
                }
            }
            $linkCount = (int) $linkQuery->count();
        }

        return [
            'article_count' => count($articleIds),
            'internal_link_count' => $linkCount,
        ];
    }

    /**
     * Distinct Focus Articles per Topic (cluster_key) for index cards / has_articles filter.
     *
     * @param  list<string>  $clusterKeys
     * @return array<string, int>
     */
    public function focusArticleCountsByClusterKey(
        array $clusterKeys,
        ?int $siteId = null,
        ?array $languageVariants = null,
    ): array {
        $clusterKeys = array_values(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $clusterKeys,
        )));
        if ($clusterKeys === [] || ! $this->classificationsReady()) {
            return [];
        }

        $keywordIds = $this->keywordIdSubquery($siteId);
        $placeholders = implode(',', array_fill(0, count($clusterKeys), '?'));
        $memberRows = DB::connection('omi_seo_ai')->select(
            'SELECT c.cluster_key, c.keyword_id'
            .' FROM seo_keyword_classifications c'
            .' WHERE c.cluster_key IN ('.$placeholders.')'
            .' AND c.keyword_id IN ('.$keywordIds->toSql().')',
            array_merge($clusterKeys, $keywordIds->getBindings()),
        );

        /** @var array<string, list<int>> $idsByCluster */
        $idsByCluster = [];
        /** @var list<int> $allKeywordIds */
        $allKeywordIds = [];
        foreach ($memberRows as $row) {
            $key = trim((string) ($row->cluster_key ?? ''));
            $kid = (int) ($row->keyword_id ?? 0);
            if ($key === '' || $kid <= 0) {
                continue;
            }
            $idsByCluster[$key][] = $kid;
            $allKeywordIds[] = $kid;
        }
        $allKeywordIds = array_values(array_unique($allKeywordIds));

        /** @var array<int, array<int, true>> $articlesByKeyword */
        $articlesByKeyword = [];
        if ($allKeywordIds !== [] && Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $metas = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->whereIn('keyword_id', $allKeywordIds)
                ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', '')
                ->get(['keyword_id', 'meta_value']);
            foreach ($metas as $meta) {
                $kid = (int) ($meta->keyword_id ?? 0);
                $aid = (int) ($meta->meta_value ?? 0);
                if ($kid > 0 && $aid > 0) {
                    $articlesByKeyword[$kid][$aid] = true;
                }
            }
        }
        if ($allKeywordIds !== [] && Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $linkQuery = DB::connection('omi_seo_ai')->table('seo_link_maps')
                ->whereIn('keyword_id', $allKeywordIds)
                ->whereNotNull('target_article_id');
            if ($languageVariants !== null && $languageVariants !== []) {
                $linkQuery->whereIn('target_article_id', function ($q) use ($languageVariants): void {
                    $q->select('id')->from('articles')->whereIn('language', $languageVariants);
                });
            }
            foreach ($linkQuery->get(['keyword_id', 'target_article_id']) as $link) {
                $kid = (int) ($link->keyword_id ?? 0);
                $aid = (int) ($link->target_article_id ?? 0);
                if ($kid > 0 && $aid > 0) {
                    $articlesByKeyword[$kid][$aid] = true;
                }
            }
        }

        if ($languageVariants !== null && $languageVariants !== [] && $articlesByKeyword !== []) {
            $allAids = [];
            foreach ($articlesByKeyword as $set) {
                foreach (array_keys($set) as $aid) {
                    $allAids[$aid] = true;
                }
            }
            $allowed = DB::connection('omi_seo_ai')->table('articles')
                ->whereIn('id', array_keys($allAids))
                ->whereIn('language', $languageVariants)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $allowedMap = array_fill_keys($allowed, true);
            foreach ($articlesByKeyword as $kid => $set) {
                $articlesByKeyword[$kid] = array_intersect_key($set, $allowedMap);
            }
        }

        $out = [];
        foreach ($idsByCluster as $key => $kids) {
            /** @var array<int, true> $distinct */
            $distinct = [];
            foreach ($kids as $kid) {
                foreach (array_keys($articlesByKeyword[$kid] ?? []) as $aid) {
                    $distinct[$aid] = true;
                }
            }
            $out[$key] = count($distinct);
        }

        return $out;
    }

    /**
     * @param  array{
     *     group_ref: string,
     *     mask_name: string,
     *     member_count: int
     * }|null  $mcpGroup
     * @return array<string, mixed>
     */
    private function clusterListItem(
        string $clusterKey,
        string $label,
        int $keywordCount,
        int $articleCount,
        string $intent,
        string $coverage,
        float $topicalShare,
        ?int $siteId,
        string $canonicalSource = SeoTopicClusterMeta::SOURCE_AUTO,
        string $state = 'active',
        bool $mcpExcluded = false,
        bool $seoExcluded = false,
        int $internalLinkCount = 0,
        ?array $mcpGroup = null,
        int $planningPendingCount = 0,
    ): array {
        return [
            'cluster_key' => $clusterKey,
            'label' => $label,
            'keyword_count' => $keywordCount,
            'article_count' => $articleCount,
            'internal_link_count' => $internalLinkCount,
            'intent' => $intent,
            'coverage' => $coverage,
            'topical_share' => $topicalShare,
            'planning_pending_count' => max(0, $planningPendingCount),
            'canonical_source' => $canonicalSource,
            'state' => $state,
            'mcp_excluded' => $mcpExcluded,
            'seo_excluded' => $seoExcluded,
            'mcp_group' => $mcpGroup,
            'is_mcp_group' => false,
            'mcp_member_count' => 0,
            'mcp_members' => [],
            'groups' => [],
        ];
    }

    /**
     * Soft dependency on content-projects MCP planning signal (UI +N only).
     *
     * @return array<string, int>
     */
    private function mcpPlanningCountMap(int $siteId): array
    {
        $class = 'Omnichannel\\Addons\\ContentProjects\\Services\\ContentProject\\McpPlanning\\McpPlanningSignalService';
        if ($siteId <= 0 || ! class_exists($class)) {
            return [];
        }

        try {
            /** @var array<string, int> $map */
            $map = app($class)->countsByClusterKey($siteId);

            return is_array($map) ? $map : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function canonicalSourceForKey(?int $siteId, string $clusterKey): string
    {
        if ($siteId === null || $siteId <= 0 || $clusterKey === '') {
            return SeoTopicClusterMeta::SOURCE_AUTO;
        }
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return SeoTopicClusterMeta::SOURCE_AUTO;
        }

        $source = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->value('canonical_source');

        return (string) ($source ?: SeoTopicClusterMeta::SOURCE_AUTO);
    }

    private function keywordIdSubquery(
        ?int $siteId,
        ?array $languageVariants = null,
        bool $requireLinkedSource = false,
    ): Builder {
        return KeywordClusterSiteScope::keywordIdSubquery(
            $siteId,
            $languageVariants,
            excludeSuggest: true,
            requireLinkedSource: $requireLinkedSource,
        );
    }

    private function keywordBase(?int $siteId, ?array $languageVariants = null, bool $requireLinkedSource = false)
    {
        return KeywordClusterSiteScope::apply(
            Keyword::query(),
            $siteId,
            $languageVariants,
            excludeSuggest: true,
            requireLinkedSource: $requireLinkedSource,
        );
    }

    private function classificationJoin(?int $siteId)
    {
        $query = DB::connection('omi_seo_ai')->table('seo_keyword_classifications as c');
        $ids = $this->keywordIdSubquery($siteId);

        return $query->whereIn('c.keyword_id', $ids);
    }
}
