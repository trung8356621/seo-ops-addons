<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;

/**
 * Domain-scoped Idea Coverage Graph read model (Cluster → DNA → content coverage).
 *
 * Query-time aggregates only — no persistent cache / graph DB.
 */
final class TopicIdeaCoverageService
{
    private const EXAMPLE_LIMIT = 3;

    private const DNA_PLANNING_LIMIT = 8;

    public function __construct(
        private readonly KeywordClusterQuery $clusterQuery,
        private readonly CanonicalClusterResolverService $resolver,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')
            && $this->clusterQuery->classificationsReady();
    }

    /**
     * Full coverage for one cluster (detail UI).
     *
     * @return array{
     *     cluster_key: string,
     *     label: string,
     *     keyword_count: int,
     *     article_count: int,
     *     dna_branch_count: int,
     *     base_keyword_count: int,
     *     base_article_count: int,
     *     base_content_coverage: string,
     *     covered_branch_count: int,
     *     uncovered_branch_count: int,
     *     dna_branches: list<array{
     *         value: string,
     *         normalized_value: string,
     *         keyword_count: int,
     *         article_count: int,
     *         content_coverage: string,
     *         examples: list<string>
     *     }>
     * }|null
     */
    public function forCluster(int $siteId, string $clusterKey): ?array
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '' || ! $this->tablesReady()) {
            return null;
        }

        $memberIds = $this->clusterQuery->memberKeywordIds($siteId, $clusterKey);
        if ($memberIds === []) {
            return null;
        }

        $label = $this->resolver->canonicalForCluster($siteId, $clusterKey)
            ?? $this->clusterQuery->displayLabel($clusterKey);

        $keywordCount = count($memberIds);
        $articleCount = $this->articleCountForKeywords($memberIds);
        $branches = $this->dnaBranchesForCluster($siteId, $clusterKey, $memberIds);

        $keywordsWithDna = $this->keywordIdsHavingDna($siteId, $clusterKey, $memberIds);
        $baseIds = array_values(array_diff($memberIds, $keywordsWithDna));
        $baseKeywordCount = count($baseIds);
        $baseArticleCount = $this->articleCountForKeywords($baseIds);

        $coveredBranches = 0;
        $uncoveredBranches = 0;
        foreach ($branches as $branch) {
            if ($branch['article_count'] > 0) {
                $coveredBranches++;
            } else {
                $uncoveredBranches++;
            }
        }

        return [
            'cluster_key' => $clusterKey,
            'label' => $label,
            'keyword_count' => $keywordCount,
            'article_count' => $articleCount,
            'dna_branch_count' => count($branches),
            'base_keyword_count' => $baseKeywordCount,
            'base_article_count' => $baseArticleCount,
            'base_content_coverage' => TopicIdeaContentCoverageStatus::fromArticleCount($baseArticleCount),
            'covered_branch_count' => $coveredBranches,
            'uncovered_branch_count' => $uncoveredBranches,
            'dna_branches' => $branches,
        ];
    }

    /**
     * Compact index summaries for many cluster keys (one grouped query path).
     *
     * @param  list<string>  $clusterKeys
     * @return array<string, array{
     *     dna_branch_count: int,
     *     covered_branch_count: int,
     *     uncovered_branch_count: int
     * }>
     */
    public function summariesForKeys(int $siteId, array $clusterKeys): array
    {
        $clusterKeys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $k): string => trim((string) $k),
            $clusterKeys,
        ))));

        if ($siteId <= 0 || $clusterKeys === [] || ! $this->tablesReady()) {
            return [];
        }

        $rows = DB::connection('omi_seo_ai')->table('seo_keyword_dna as d')
            ->leftJoin('seo_link_maps as lm', function ($join): void {
                $join->on('lm.keyword_id', '=', 'd.keyword_id')
                    ->whereNotNull('lm.target_article_id');
            })
            ->where('d.site_id', $siteId)
            ->whereIn('d.cluster_key', $clusterKeys)
            ->groupBy('d.cluster_key', 'd.normalized_value')
            ->selectRaw('d.cluster_key, d.normalized_value, COUNT(DISTINCT lm.target_article_id) as article_count')
            ->get();

        /** @var array<string, array{dna_branch_count: int, covered_branch_count: int, uncovered_branch_count: int}> $out */
        $out = [];
        foreach ($clusterKeys as $key) {
            $out[$key] = [
                'dna_branch_count' => 0,
                'covered_branch_count' => 0,
                'uncovered_branch_count' => 0,
            ];
        }

        foreach ($rows as $row) {
            $key = (string) $row->cluster_key;
            if (! isset($out[$key])) {
                continue;
            }
            $out[$key]['dna_branch_count']++;
            if ((int) $row->article_count > 0) {
                $out[$key]['covered_branch_count']++;
            } else {
                $out[$key]['uncovered_branch_count']++;
            }
        }

        return $out;
    }

    /**
     * Compact planning context for New Content (relevant cluster labels only).
     *
     * @param  list<string>  $clusterLabels
     * @return list<array{
     *     cluster: string,
     *     core_articles: int,
     *     dna: list<array{value: string, articles: int, coverage: string}>
     * }>
     */
    public function planningCompact(int $siteId, array $clusterLabels, int $limitPerCluster = self::DNA_PLANNING_LIMIT): array
    {
        if ($siteId <= 0 || $clusterLabels === [] || ! $this->tablesReady()) {
            return [];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }

        $labels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $l): string => trim((string) $l),
            $clusterLabels,
        ))));

        $metas = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->whereIn('canonical_phrase', $labels)
            ->get(['cluster_key', 'canonical_phrase']);

        $out = [];
        foreach ($metas as $meta) {
            $detail = $this->forCluster($siteId, (string) $meta->cluster_key);
            if ($detail === null) {
                continue;
            }

            $dna = [];
            foreach (array_slice($detail['dna_branches'], 0, $limitPerCluster) as $branch) {
                $dna[] = [
                    'value' => $branch['value'],
                    'articles' => $branch['article_count'],
                    'coverage' => $branch['content_coverage'],
                ];
            }

            $out[] = [
                'cluster' => (string) $meta->canonical_phrase,
                'core_articles' => $detail['base_article_count'],
                'dna' => $dna,
            ];
        }

        return $out;
    }

    /**
     * Driver-agnostic branch aggregate (one DNA query + one article map + phrases).
     *
     * @param  list<int>  $memberIds
     * @return list<array{
     *     value: string,
     *     normalized_value: string,
     *     keyword_count: int,
     *     article_count: int,
     *     content_coverage: string,
     *     examples: list<string>
     * }>
     */
    private function dnaBranchesForCluster(int $siteId, string $clusterKey, array $memberIds): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna') || $memberIds === []) {
            return [];
        }

        $dnaRows = SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->whereIn('keyword_id', $memberIds)
            ->get(['keyword_id', 'value', 'normalized_value']);

        if ($dnaRows->isEmpty()) {
            return [];
        }

        $phrases = DB::connection('omi_seo_ai')->table('keywords')
            ->whereIn('id', $memberIds)
            ->pluck('phrase', 'id')
            ->map(static fn ($p): string => (string) $p)
            ->all();

        /** @var array<int, list<int>> $articlesByKeyword */
        $articlesByKeyword = [];
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $linkRows = DB::connection('omi_seo_ai')->table('seo_link_maps')
                ->whereIn('keyword_id', $memberIds)
                ->whereNotNull('target_article_id')
                ->select(['keyword_id', 'target_article_id'])
                ->get();
            foreach ($linkRows as $link) {
                $kid = (int) $link->keyword_id;
                $articlesByKeyword[$kid] ??= [];
                $articlesByKeyword[$kid][(int) $link->target_article_id] = (int) $link->target_article_id;
            }
        }

        /** @var array<string, array{value: string, keyword_ids: array<int, true>, articles: array<int, true>, examples: list<string>}> $groups */
        $groups = [];
        foreach ($dnaRows as $row) {
            $norm = (string) $row->normalized_value;
            if ($norm === '') {
                continue;
            }
            $kid = (int) $row->keyword_id;
            $groups[$norm] ??= [
                'value' => (string) $row->value,
                'keyword_ids' => [],
                'articles' => [],
                'examples' => [],
            ];
            if (mb_strlen((string) $row->value) < mb_strlen($groups[$norm]['value'])) {
                $groups[$norm]['value'] = (string) $row->value;
            }
            $groups[$norm]['keyword_ids'][$kid] = true;
            foreach ($articlesByKeyword[$kid] ?? [] as $aid) {
                $groups[$norm]['articles'][$aid] = true;
            }
            $phrase = trim((string) ($phrases[$kid] ?? ''));
            if ($phrase !== '' && ! in_array($phrase, $groups[$norm]['examples'], true)
                && count($groups[$norm]['examples']) < self::EXAMPLE_LIMIT) {
                $groups[$norm]['examples'][] = $phrase;
            }
        }

        $out = [];
        foreach ($groups as $norm => $group) {
            $articleCount = count($group['articles']);
            $out[] = [
                'value' => $group['value'],
                'normalized_value' => $norm,
                'keyword_count' => count($group['keyword_ids']),
                'article_count' => $articleCount,
                'content_coverage' => TopicIdeaContentCoverageStatus::fromArticleCount($articleCount),
                'examples' => $group['examples'],
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => ($b['keyword_count'] <=> $a['keyword_count'])
                ?: strcmp($a['value'], $b['value']),
        );

        return $out;
    }

    /**
     * @param  list<int>  $memberIds
     * @return list<int>
     */
    private function keywordIdsHavingDna(int $siteId, string $clusterKey, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->whereIn('keyword_id', $memberIds)
            ->distinct()
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function articleCountForKeywords(array $keywordIds): int
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return 0;
        }

        return (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('target_article_id')
            ->distinct()
            ->count('target_article_id');
    }
}
