<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRuleGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
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
        $items = [];
        foreach ($rows as $row) {
            $key = (string) ($row->cluster_key ?? '');
            if ($key === '') {
                continue;
            }
            $label = $this->displayLabel($key, (string) ($row->sample_phrase ?? ''));
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
                (int) ($row->group_diversity ?? 0),
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
                'groups' => $this->decodeGroups((string) ($row->group_labels ?? '')),
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
        $groupJoin = '';
        $groupSelect = '0 as group_diversity, \'\' as group_labels';
        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_rule_group_members')
            && Schema::connection('omi_seo_ai')->hasTable('seo_keyword_rule_groups')) {
            $groupSelect = 'COUNT(DISTINCT m.group_id) as group_diversity, GROUP_CONCAT(DISTINCT g.label ORDER BY g.sort_order SEPARATOR \'|\') as group_labels';
            $groupJoin = ' LEFT JOIN seo_keyword_rule_group_members m ON m.keyword_id = c.keyword_id'
                .' LEFT JOIN seo_keyword_rule_groups g ON g.id = m.group_id AND g.is_active = 1';
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
            .' '.$groupSelect
            .' FROM seo_keyword_classifications c'
            .' INNER JOIN keywords k ON k.id = c.keyword_id'
            .$articleJoin
            .$groupJoin
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

    public function displayLabel(string $clusterKey, string $samplePhrase = ''): string
    {
        $sample = trim($samplePhrase);
        if ($sample !== '') {
            return (new KeywordCanonicalizer())->prettyLabel($sample);
        }

        $label = trim(str_replace('_', ' ', $clusterKey));

        return $label !== '' ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : $clusterKey;
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

    /**
     * @return list<string>
     */
    private function decodeGroups(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $raw))));
    }
}
