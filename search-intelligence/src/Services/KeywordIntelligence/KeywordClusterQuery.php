<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
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
    public function summary(?int $siteId): array
    {
        return $this->eligibility->summaryMetrics($siteId);
    }

    /**
     * @param  array{search?: string, coverage?: string, has_articles?: bool}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateClusters(?int $siteId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        if (! $this->classificationsReady()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $coverage = trim((string) ($filters['coverage'] ?? ''));
        $hasArticles = (bool) ($filters['has_articles'] ?? false);

        $rows = $this->clusterAggregates($siteId);
        $canonicalByKey = $this->canonicalLabelsForKeys(
            $siteId,
            array_values(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->cluster_key ?? '')),
                $rows,
            ))),
        );
        $items = [];
        foreach ($rows as $row) {
            $key = (string) ($row->cluster_key ?? '');
            if ($key === '') {
                continue;
            }
            $label = $canonicalByKey[$key]
                ?? $this->displayLabel($key, (string) ($row->sample_phrase ?? ''), $siteId);
            if ($search !== '' && ! str_contains(mb_strtolower($label.' '.$key), mb_strtolower($search))) {
                continue;
            }
            $articleCount = (int) ($row->article_count ?? 0);
            if ($hasArticles && $articleCount < 1) {
                continue;
            }
            $score = $this->coverageBuilder->score(
                (int) ($row->keyword_count ?? 0),
                $articleCount,
                (int) ($row->dna_branch_count ?? 0),
                (int) ($row->intent_diversity ?? 0),
            );
            if ($coverage !== '' && $score !== $coverage) {
                continue;
            }
            $items[] = [
                'cluster_key' => $key,
                'label' => $label,
                'keyword_count' => (int) ($row->keyword_count ?? 0),
                'article_count' => $articleCount,
                'intent' => (string) ($row->top_intent ?? ''),
                'coverage' => $score,
                'groups' => [],
            ];
        }

        $page = max(1, (int) request()->integer('page', 1));
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * Avoid loading 14k clusters into PHP when possible — aggregate in SQL first.
     *
     * @return list<object>
     */
    public function clusterAggregates(?int $siteId, int $limit = 500): array
    {
        if (! $this->classificationsReady()) {
            return [];
        }

        $keywordIds = $this->keywordIdSubquery($siteId);
        $dnaJoin = '';
        $dnaSelect = '0 as dna_branch_count';
        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
            $dnaSelect = 'COUNT(DISTINCT d.normalized_value) as dna_branch_count';
            $dnaJoin = ' LEFT JOIN seo_keyword_dna d ON d.keyword_id = c.keyword_id AND d.cluster_key = c.cluster_key';
        }

        $articleJoin = '';
        $articleSelect = '0 as article_count';
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $articleSelect = 'COUNT(DISTINCT lm.target_article_id) as article_count';
            $articleJoin = ' LEFT JOIN seo_link_maps lm ON lm.keyword_id = c.keyword_id AND lm.target_article_id IS NOT NULL';
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
            .' WHERE c.cluster_key IS NOT NULL AND c.cluster_key <> \'\''
            .' AND c.keyword_id IN ('.$keywordIds->toSql().')'
            .' GROUP BY c.cluster_key'
            .' ORDER BY keyword_count DESC'
            .' LIMIT '.(int) $limit;

        return DB::connection('omi_seo_ai')->select($sql, $keywordIds->getBindings());
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
     * Fallback: cluster_key pretty → last-resort sample member phrase.
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

        $fromKey = trim(str_replace('_', ' ', $clusterKey));
        if ($fromKey !== '') {
            return mb_convert_case($fromKey, MB_CASE_TITLE, 'UTF-8');
        }

        $sample = trim($samplePhrase);

        return $sample !== ''
            ? (new KeywordCanonicalizer())->prettyLabel($sample)
            : $clusterKey;
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
    public function memberKeywordIds(?int $siteId, string $clusterKey): array
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || ! $this->classificationsReady()) {
            return [];
        }

        $query = SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey);

        if ($siteId !== null && $siteId > 0) {
            $ids = Keyword::query()->forSite($siteId)->select('id');
            $query->whereIn('keyword_id', $ids);
        }

        return $query->limit(5000)->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function clusterExists(string $clusterKey): bool
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || ! $this->classificationsReady()) {
            return false;
        }

        return SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey)
            ->exists();
    }

    private function keywordIdSubquery(?int $siteId): Builder
    {
        return KeywordClusterSiteScope::keywordIdSubquery($siteId);
    }

    private function keywordBase(?int $siteId)
    {
        return KeywordClusterSiteScope::apply(Keyword::query(), $siteId);
    }

    private function classificationJoin(?int $siteId)
    {
        $query = DB::connection('omi_seo_ai')->table('seo_keyword_classifications as c');
        $ids = $this->keywordIdSubquery($siteId);

        return $query->whereIn('c.keyword_id', $ids);
    }
}
