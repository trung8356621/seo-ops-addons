<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SiteMcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterExclusionService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupCoverageBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\McpTopicGroupService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;

/**
 * Keyword Cluster → compact Site MCP topical profile (SSOT).
 *
 * MCP share = published article assignments / total published assignments
 * across MCP-eligible clusters (not keyword count).
 */
final class SiteMcpClusterTopicalProfileBuilder
{
    public const SOURCE = 'keyword_clusters.v1';

    public const MAX_COMPACT_DNA = 5;

    /** Prompt compression: keep heaviest active topics + all planned. */
    public const MAX_PROMPT_ACTIVE_TOPICS = 20;

    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly KeywordGroupCoverageBuilder $coverageBuilder,
        private readonly ?KeywordDnaService $dnaService = null,
    ) {}

    /**
     * @param  array{group_ref?: string, mask_name: string, member_keys: list<string>}|null  $draftGroup
     * @return array{
     *     source: string,
     *     built_at: string,
     *     total_clustered_keywords: int,
     *     total_published_articles: int,
     *     topics: list<array<string, mixed>>
     * }
     */
    public function build(int $siteId, ?array $draftGroup = null): array
    {
        if ($siteId <= 0 || ! $this->clusters->classificationsReady()) {
            return $this->emptyProfile();
        }

        $keywordCounts = $this->eligibleKeywordCountsByCluster($siteId);
        $articleIdsByCluster = $this->publishedArticleIdsByCluster($siteId);
        $manualKeys = $this->manualClusterKeys($siteId);
        $exclusionMap = app(ClusterExclusionService::class)->flagsMapForSite($siteId);
        $metaLabels = $this->canonicalLabelsIncludingEmptyManual($siteId);

        $candidates = [];
        foreach ($keywordCounts as $key => $count) {
            $isManual = isset($manualKeys[$key]);
            if ($count < 2 && ! $isManual) {
                continue;
            }
            $flags = $exclusionMap[$key] ?? ['mcp_excluded' => false, 'seo_excluded' => false];
            if ($flags['seo_excluded'] || $flags['mcp_excluded']) {
                continue;
            }
            $candidates[$key] = [
                'cluster_key' => $key,
                'keyword_count' => $count,
                'article_ids' => $articleIdsByCluster[$key] ?? [],
                'source' => $isManual ? SeoTopicClusterMeta::SOURCE_MANUAL : SeoTopicClusterMeta::SOURCE_AUTO,
                'member_keys' => [$key],
                'mask_name' => null,
            ];
        }

        foreach ($manualKeys as $key => $_) {
            if (isset($candidates[$key])) {
                continue;
            }
            $flags = $exclusionMap[$key] ?? ['mcp_excluded' => false, 'seo_excluded' => false];
            if ($flags['seo_excluded'] || $flags['mcp_excluded']) {
                continue;
            }
            $candidates[$key] = [
                'cluster_key' => $key,
                'keyword_count' => 0,
                'article_ids' => $articleIdsByCluster[$key] ?? [],
                'source' => SeoTopicClusterMeta::SOURCE_MANUAL,
                'member_keys' => [$key],
                'mask_name' => null,
            ];
        }

        // Compress Site MCP topics via manual MCP groups (does not mutate real clusters).
        $candidates = $this->applyMcpGroups($siteId, $candidates, $draftGroup);

        $denominator = 0;
        foreach ($candidates as $row) {
            $denominator += count($row['article_ids'] ?? []);
        }

        $labelKeys = [];
        foreach ($candidates as $key => $row) {
            if (trim((string) ($row['mask_name'] ?? '')) === '') {
                $labelKeys[] = $key;
            }
        }
        $labels = $this->clusters->canonicalLabelsForKeys($siteId, $labelKeys);
        foreach ($metaLabels as $key => $phrase) {
            if (! isset($labels[$key]) && $phrase !== '') {
                $labels[$key] = $phrase;
            }
        }

        $topics = [];
        $keywordDenominator = 0;
        foreach ($candidates as $key => $row) {
            $keywordCount = (int) $row['keyword_count'];
            $articleCount = count($row['article_ids'] ?? []);
            $memberKeys = array_values(array_filter(array_map(
                static fn (mixed $k): string => trim((string) $k),
                is_array($row['member_keys'] ?? null) ? $row['member_keys'] : [$key],
            )));
            $keywordDenominator += $keywordCount;
            $weight = self::weightForCount($articleCount, $denominator);
            $name = trim((string) ($row['mask_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($labels[$key] ?? ''));
            }
            if ($name === '') {
                $name = $this->clusters->displayLabel($key, '', $siteId);
            }
            $source = (string) $row['source'];
            $state = $keywordCount === 0 ? 'planned' : 'active';
            $intent = $this->dominantIntentForMembers($siteId, $memberKeys);
            $dnaBranches = $this->compactDnaForMembers($siteId, $memberKeys);
            $coverage = $this->coverageBuilder->score(
                $keywordCount,
                $articleCount,
                count($dnaBranches),
                $intent !== '' ? 1 : 0,
            );

            $topics[] = [
                'cluster_ref' => $key,
                'name' => $name,
                'weight' => $weight,
                'keyword_count' => $keywordCount,
                'article_count' => $articleCount,
                'source' => $source,
                'state' => $state,
                'priority' => $state === 'planned' ? 'high' : null,
                'intent' => $intent,
                'coverage' => $coverage,
                'dna' => $dnaBranches,
                'mcp_excluded' => false,
                'seo_excluded' => false,
                'mcp_group_members' => $memberKeys,
            ];
        }

        usort($topics, static function (array $a, array $b): int {
            $byWeight = $b['weight'] <=> $a['weight'];
            if ($byWeight !== 0) {
                return $byWeight;
            }
            $byArticles = $b['article_count'] <=> $a['article_count'];
            if ($byArticles !== 0) {
                return $byArticles;
            }
            $byCount = $b['keyword_count'] <=> $a['keyword_count'];
            if ($byCount !== 0) {
                return $byCount;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'source' => self::SOURCE,
            'built_at' => gmdate('c'),
            'total_clustered_keywords' => $keywordDenominator,
            'total_published_articles' => $denominator,
            'topics' => array_values($topics),
        ];
    }

    /**
     * Deterministic share: published_articles / total_published_in_mcp_eligible × 100.
     * 0 articles ⇒ 0% (invariant).
     */
    public static function weightForCount(int $articleCount, int $denominator): float
    {
        if ($articleCount <= 0 || $denominator <= 0) {
            return 0.0;
        }

        return round(($articleCount / $denominator) * 100, 1);
    }

    /**
     * Index/UI map: MCP-eligible shares; excluded clusters → 0.
     * Grouped members inherit the group's post-aggregation share.
     *
     * @return array<string, float>
     */
    public function topicalShareMap(int $siteId): array
    {
        $profile = $this->build($siteId);
        $out = [];
        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $weight = (float) ($topic['weight'] ?? 0);
            $members = is_array($topic['mcp_group_members'] ?? null)
                ? $topic['mcp_group_members']
                : [(string) ($topic['cluster_ref'] ?? '')];
            foreach ($members as $member) {
                $key = trim((string) $member);
                if ($key !== '') {
                    $out[$key] = $weight;
                }
            }
            $ref = trim((string) ($topic['cluster_ref'] ?? ''));
            if ($ref !== '') {
                $out[$ref] = $weight;
            }
        }

        $exclusionMap = app(ClusterExclusionService::class)->flagsMapForSite($siteId);
        foreach ($exclusionMap as $key => $flags) {
            if ($flags['mcp_excluded'] || $flags['seo_excluded']) {
                $out[$key] = 0.0;
            }
        }

        return $out;
    }

    /**
     * @param  array{
     *     topics?: list<array<string, mixed>>,
     *     total_clustered_keywords?: int
     * }  $profile
     * @return list<string>
     */
    public static function compactLines(array $profile, ?int $maxActive = null): array
    {
        $maxActive ??= self::MAX_PROMPT_ACTIVE_TOPICS;
        $lines = ['Topical profile:'];
        $topics = is_array($profile['topics'] ?? null) ? $profile['topics'] : [];
        if ($topics === []) {
            $lines[] = '(none)';

            return $lines;
        }

        $activeShown = 0;
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $state = (string) ($topic['state'] ?? 'active');
            if ($state === 'planned') {
                $priority = trim((string) ($topic['priority'] ?? 'high'));
                $lines[] = '- '.$name.' — planned/'.($priority !== '' ? $priority : 'high');
                continue;
            }
            if ($activeShown >= $maxActive) {
                continue;
            }
            $activeShown++;
            $weight = (float) ($topic['weight'] ?? 0);
            $display = fmod($weight, 1.0) === 0.0
                ? (string) (int) $weight
                : rtrim(rtrim(number_format($weight, 1, '.', ''), '0'), '.');
            $lines[] = '- '.$name.' — '.$display.'%';
        }

        return $lines;
    }

    /**
     * @param  array{topics?: list<array<string, mixed>>}  $profile
     * @return list<string>
     */
    public static function topicNames(array $profile): array
    {
        $out = [];
        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array{topics?: list<array<string, mixed>>}  $profile
     * @return list<array<string, mixed>>
     */
    public static function toMainTopicRecords(array $profile): array
    {
        $records = [];
        foreach (is_array($profile['topics'] ?? null) ? $profile['topics'] : [] as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $records[] = [
                'keyword' => $name,
                'name' => $name,
                'cluster_ref' => (string) ($topic['cluster_ref'] ?? ''),
                'weight' => (float) ($topic['weight'] ?? 0),
                'keyword_count' => (int) ($topic['keyword_count'] ?? 0),
                'article_count' => (int) ($topic['article_count'] ?? 0),
                'source' => (string) ($topic['source'] ?? SeoTopicClusterMeta::SOURCE_AUTO),
                'state' => (string) ($topic['state'] ?? 'active'),
                'priority' => $topic['priority'] ?? null,
                'intent' => (string) ($topic['intent'] ?? ''),
                'coverage' => (string) ($topic['coverage'] ?? ''),
                'dna' => is_array($topic['dna'] ?? null) ? array_values($topic['dna']) : [],
                'source_type' => 'keyword_cluster',
            ];
        }

        return $records;
    }

    /**
     * @return array{
     *     source: string,
     *     built_at: string,
     *     total_clustered_keywords: int,
     *     total_published_articles: int,
     *     topics: list<array<string, mixed>>
     * }
     */
    public function emptyProfile(): array
    {
        return [
            'source' => self::SOURCE,
            'built_at' => gmdate('c'),
            'total_clustered_keywords' => 0,
            'total_published_articles' => 0,
            'topics' => [],
        ];
    }

    /**
     * SEO-eligible keyword counts per cluster_key (excludes seo_hidden keywords).
     *
     * @return array<string, int>
     */
    private function eligibleKeywordCountsByCluster(int $siteId): array
    {
        $keywordIds = $this->seoEligibleKeywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $query = DB::connection('omi_seo_ai')->table('seo_keyword_classifications as c')
            ->whereIn('c.keyword_id', $keywordIds)
            ->whereNotNull('c.cluster_key')
            ->where('c.cluster_key', '!=', '');

        if (KeywordClassificationVisibility::hasSeoKeywordColumn()) {
            $query->where('c.is_seo_keyword', true);
        } else {
            $query->whereIn('c.phrase_kind', [
                KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
                KeywordRuleClassifier::KIND_QUERY,
                KeywordRuleClassifier::KIND_BRAND_ENTITY,
            ]);
        }

        $rows = $query
            ->selectRaw('c.cluster_key, COUNT(DISTINCT c.keyword_id) as keyword_count')
            ->groupBy('c.cluster_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row->cluster_key ?? ''));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int) ($row->keyword_count ?? 0);
        }

        return $out;
    }

    /**
     * Distinct published articles assigned to cluster via:
     * - keyword_meta.main_article_id
     * - seo_link_maps.target_article_id
     * for MCP-eligible member keywords (not seo_hidden, not mcp_excluded).
     *
     * @return array<string, int>
     */
    public function publishedArticleCountsByCluster(int $siteId): array
    {
        $out = [];
        foreach ($this->publishedArticleIdsByCluster($siteId) as $key => $ids) {
            $out[$key] = count($ids);
        }

        return $out;
    }

    /**
     * @return array<string, array<int, true>>
     */
    public function publishedArticleIdsByCluster(int $siteId): array
    {
        $keywordIds = $this->mcpEligibleKeywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $clusterByKeyword = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->pluck('cluster_key', 'keyword_id');

        if ($clusterByKeyword->isEmpty()) {
            return [];
        }

        /** @var array<string, array<int, true>> $articlesByCluster */
        $articlesByCluster = [];

        $append = static function (string $clusterKey, int $articleId) use (&$articlesByCluster): void {
            if ($clusterKey === '' || $articleId <= 0) {
                return;
            }
            $articlesByCluster[$clusterKey][$articleId] = true;
        };

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $mainMetas = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->whereIn('keyword_id', $keywordIds)
                ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                ->whereNotNull('meta_value')
                ->get(['keyword_id', 'meta_value']);

            $mainArticleIds = [];
            foreach ($mainMetas as $meta) {
                $articleId = (int) ($meta->meta_value ?? 0);
                if ($articleId > 0) {
                    $mainArticleIds[$articleId] = true;
                }
            }

            $publishedMainIds = $this->filterPublishedArticleIds(array_keys($mainArticleIds));
            foreach ($mainMetas as $meta) {
                $articleId = (int) ($meta->meta_value ?? 0);
                if (! isset($publishedMainIds[$articleId])) {
                    continue;
                }
                $kwId = (int) ($meta->keyword_id ?? 0);
                $clusterKey = trim((string) ($clusterByKeyword[$kwId] ?? ''));
                $append($clusterKey, $articleId);
            }
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $linkQuery = DB::connection('omi_seo_ai')->table('seo_link_maps as lm')
                ->join('articles as a', 'a.id', '=', 'lm.target_article_id')
                ->whereIn('lm.keyword_id', $keywordIds)
                ->whereNotNull('lm.target_article_id')
                ->whereNull('a.deleted_at');
            $this->applyPublishedArticleScope($linkQuery, 'a');
            $linkRows = $linkQuery->get(['lm.keyword_id', 'a.id as article_id']);

            foreach ($linkRows as $row) {
                $kwId = (int) ($row->keyword_id ?? 0);
                $clusterKey = trim((string) ($clusterByKeyword[$kwId] ?? ''));
                $append($clusterKey, (int) ($row->article_id ?? 0));
            }
        }

        return $articlesByCluster;
    }

    /**
     * Collapse candidate clusters using manual MCP groups before share/token calc.
     *
     * @param  array<string, array{
     *     cluster_key: string,
     *     keyword_count: int,
     *     article_ids: array<int, true>,
     *     source: string,
     *     member_keys: list<string>,
     *     mask_name: ?string
     * }>  $candidates
     * @param  array{group_ref?: string, mask_name: string, member_keys: list<string>}|null  $draftGroup
     * @return array<string, array{
     *     cluster_key: string,
     *     keyword_count: int,
     *     article_ids: array<int, true>,
     *     source: string,
     *     member_keys: list<string>,
     *     mask_name: ?string
     * }>
     */
    private function applyMcpGroups(int $siteId, array $candidates, ?array $draftGroup = null): array
    {
        $groups = app(McpTopicGroupService::class)->groupsForSite($siteId);
        if ($draftGroup !== null) {
            $groups = $this->mergeDraftGroup($groups, $draftGroup);
        }
        if ($groups === []) {
            return $candidates;
        }

        $consumed = [];
        $out = [];

        foreach ($groups as $group) {
            $contributing = [];
            foreach ($group['member_keys'] as $memberKey) {
                if (isset($candidates[$memberKey])) {
                    $contributing[] = $memberKey;
                }
            }
            if ($contributing === []) {
                continue;
            }

            $groupRef = trim((string) ($group['group_ref'] ?? ''));
            if ($groupRef === '') {
                $groupRef = 'mcp_'.substr(sha1(implode('|', $contributing)), 0, 16);
            }
            $maskName = trim((string) ($group['mask_name'] ?? ''));

            $articleIds = [];
            $keywordCount = 0;
            $source = SeoTopicClusterMeta::SOURCE_AUTO;
            foreach ($contributing as $memberKey) {
                $consumed[$memberKey] = true;
                $row = $candidates[$memberKey];
                $keywordCount += (int) ($row['keyword_count'] ?? 0);
                foreach ($row['article_ids'] ?? [] as $articleId => $_) {
                    $articleIds[(int) $articleId] = true;
                }
                if (($row['source'] ?? '') === SeoTopicClusterMeta::SOURCE_MANUAL) {
                    $source = SeoTopicClusterMeta::SOURCE_MANUAL;
                }
            }

            $out[$groupRef] = [
                'cluster_key' => $groupRef,
                'keyword_count' => $keywordCount,
                'article_ids' => $articleIds,
                'source' => $source,
                'member_keys' => $contributing,
                'mask_name' => $maskName !== '' ? $maskName : null,
            ];
        }

        foreach ($candidates as $key => $row) {
            if (isset($consumed[$key])) {
                continue;
            }
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * Overlay a draft MCP group onto persisted groups (no nested groups).
     *
     * @param  list<array{group_ref: string, mask_name: string, member_keys: list<string>}>  $groups
     * @param  array{group_ref?: string, mask_name: string, member_keys: list<string>}  $draft
     * @return list<array{group_ref: string, mask_name: string, member_keys: list<string>}>
     */
    private function mergeDraftGroup(array $groups, array $draft): array
    {
        $maskName = trim((string) ($draft['mask_name'] ?? ''));
        $members = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            is_array($draft['member_keys'] ?? null) ? $draft['member_keys'] : [],
        ))));
        if ($maskName === '' || count($members) < 2) {
            return $groups;
        }

        $groupRef = trim((string) ($draft['group_ref'] ?? ''));
        if ($groupRef === '') {
            $groupRef = 'draft_'.substr(sha1(implode('|', $members)), 0, 12);
        }

        $draftMemberSet = array_fill_keys($members, true);
        $out = [];
        foreach ($groups as $group) {
            $overlap = false;
            foreach ($group['member_keys'] as $key) {
                if (isset($draftMemberSet[$key])) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) {
                continue;
            }
            $out[] = $group;
        }
        $out[] = [
            'group_ref' => $groupRef,
            'mask_name' => $maskName,
            'member_keys' => $members,
        ];

        return $out;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, true>
     */
    private function filterPublishedArticleIds(array $articleIds): array
    {
        $articleIds = array_values(array_filter(array_map('intval', $articleIds)));
        if ($articleIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        $query = DB::connection('omi_seo_ai')->table('articles as a')
            ->whereIn('a.id', $articleIds)
            ->whereNull('a.deleted_at');
        $this->applyPublishedArticleScope($query, 'a');

        $out = [];
        foreach ($query->pluck('a.id') as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    private function applyPublishedArticleScope(mixed $query, string $alias): void
    {
        $hasStatus = Schema::connection('omi_seo_ai')->hasColumn('articles', 'status');
        $hasPublishedAt = Schema::connection('omi_seo_ai')->hasColumn('articles', 'published_at');

        if ($hasPublishedAt && $hasStatus) {
            $query->where(function ($inner) use ($alias): void {
                $inner->where("{$alias}.status", 'published')
                    ->orWhere(function ($publishedAt) use ($alias): void {
                        $publishedAt->whereNotNull("{$alias}.published_at")
                            ->where("{$alias}.published_at", '<=', now());
                    });
            });

            return;
        }

        if ($hasPublishedAt) {
            $query->whereNotNull("{$alias}.published_at")
                ->where("{$alias}.published_at", '<=', now());

            return;
        }

        if ($hasStatus) {
            $query->where("{$alias}.status", 'published');
        }
        // else: any non-deleted article (legacy / sqlite tests without published columns)
    }

    /**
     * @return list<int>
     */
    private function seoEligibleKeywordIds(int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $hiddenMap = app(HideKeywordFromSeoService::class)->hiddenKeywordIdMap($keywordIds);

        return array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => ! isset($hiddenMap[$id]),
        ));
    }

    /**
     * SEO-eligible and not MCP-skipped.
     *
     * @return list<int>
     */
    private function mcpEligibleKeywordIds(int $siteId): array
    {
        $keywordIds = $this->seoEligibleKeywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $skipped = app(SkipKeywordFromMcpService::class)->skippedKeywordIdMap($keywordIds);

        return array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => ! isset($skipped[$id]),
        ));
    }

    /**
     * @return array<string, true>
     */
    private function manualClusterKeys(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            return [];
        }

        $query = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('canonical_source', SeoTopicClusterMeta::SOURCE_MANUAL);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'seo_excluded')) {
            $query->where(function ($q): void {
                $q->where('seo_excluded', false)->orWhereNull('seo_excluded');
            });
        }

        $keys = $query->pluck('cluster_key');

        $out = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function canonicalLabelsIncludingEmptyManual(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }

        $out = [];
        $rows = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->get(['cluster_key', 'canonical_phrase']);
        foreach ($rows as $row) {
            $key = trim((string) $row->cluster_key);
            $phrase = trim((string) $row->canonical_phrase);
            if ($key !== '' && $phrase !== '') {
                $out[$key] = $phrase;
            }
        }

        return $out;
    }

    private function dominantIntent(int $siteId, string $clusterKey): string
    {
        return $this->dominantIntentForMembers($siteId, [$clusterKey]);
    }

    /**
     * @param  list<string>  $clusterKeys
     */
    private function dominantIntentForMembers(int $siteId, array $clusterKeys): string
    {
        $keywordIds = [];
        foreach ($clusterKeys as $clusterKey) {
            foreach ($this->clusters->memberKeywordIds($siteId, $clusterKey) as $id) {
                $keywordIds[(int) $id] = true;
            }
        }
        $ids = array_keys($keywordIds);
        if ($ids === []) {
            return '';
        }

        $rows = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->whereIn('keyword_id', $ids)
            ->whereNotNull('seo_intent')
            ->where('seo_intent', '!=', '')
            ->selectRaw('seo_intent, COUNT(*) as total')
            ->groupBy('seo_intent')
            ->orderByDesc('total')
            ->limit(1)
            ->get();

        return trim((string) ($rows[0]->seo_intent ?? ''));
    }

    /**
     * @return list<string>
     */
    private function compactDna(int $siteId, string $clusterKey): array
    {
        return $this->compactDnaForMembers($siteId, [$clusterKey]);
    }

    /**
     * Union compact DNA of member clusters → normalize → dedupe → compact.
     * Does not persist back into cluster DNA.
     *
     * @param  list<string>  $clusterKeys
     * @return list<string>
     */
    private function compactDnaForMembers(int $siteId, array $clusterKeys): array
    {
        $seen = [];
        $out = [];
        foreach ($clusterKeys as $clusterKey) {
            foreach ($this->compactDnaBranches($siteId, $clusterKey) as $value) {
                $norm = mb_strtolower($value, 'UTF-8');
                if ($norm === '' || isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $out[] = $value;
                if (count($out) >= self::MAX_COMPACT_DNA) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function compactDnaBranches(int $siteId, string $clusterKey): array
    {
        $dna = $this->dnaService
            ?? (app()->bound(KeywordDnaService::class) ? app(KeywordDnaService::class) : null);
        if (! $dna instanceof KeywordDnaService) {
            return [];
        }

        $branches = $dna->coverageForCluster($siteId, $clusterKey);
        $out = [];
        foreach (array_slice($branches, 0, self::MAX_COMPACT_DNA) as $branch) {
            $value = trim((string) ($branch['value'] ?? ''));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
