<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;

final class KeywordDnaService
{
    public function __construct(
        private readonly KeywordDnaExtractor $extractor,
        private readonly CanonicalClusterResolverService $resolver,
        private readonly KeywordClusterQuery $clusterQuery,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna');
    }

    public function rebuildForKeyword(
        int $siteId,
        int $keywordId,
        string $clusterKey,
        string $keywordPhrase,
        ?string $clusterCanonical = null,
    ): int {
        if ($siteId <= 0 || $keywordId <= 0 || ! $this->tablesReady()) {
            return 0;
        }

        $canonical = $clusterCanonical ?? $this->resolver->canonicalForCluster($siteId, $clusterKey) ?? '';
        if ($canonical === '') {
            return 0;
        }

        $extracted = $this->extractor->extract($keywordPhrase, $canonical);

        SeoKeywordDna::query()
            ->where('keyword_id', $keywordId)
            ->delete();

        $created = 0;
        foreach ($extracted as $row) {
            SeoKeywordDna::query()->create([
                'site_id' => $siteId,
                'keyword_id' => $keywordId,
                'cluster_key' => $clusterKey,
                'value' => $row['value'],
                'normalized_value' => $row['normalized_value'],
                'facet_type' => $row['facet_type'],
                'confidence' => $row['confidence'],
                'source' => 'deterministic',
            ]);
            $created++;
        }

        return $created;
    }

    public function rebuildForCluster(int $siteId, string $clusterKey, ?string $canonical = null): int
    {
        if ($siteId <= 0 || trim($clusterKey) === '' || ! $this->tablesReady()) {
            return 0;
        }

        $canonical ??= $this->resolver->canonicalForCluster($siteId, $clusterKey);
        if ($canonical === null || $canonical === '') {
            return 0;
        }

        $ids = $this->clusterQuery->memberKeywordIds($siteId, $clusterKey);
        if ($ids === []) {
            return 0;
        }

        $keywords = Keyword::query()->whereIn('id', $ids)->get(['id', 'phrase']);
        $total = 0;
        foreach ($keywords as $keyword) {
            $total += $this->rebuildForKeyword(
                siteId: $siteId,
                keywordId: (int) $keyword->id,
                clusterKey: $clusterKey,
                keywordPhrase: (string) $keyword->phrase,
                clusterCanonical: $canonical,
            );
        }

        return $total;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, list<string>>
     */
    public function displayValuesForKeywords(array $keywordIds): array
    {
        if ($keywordIds === [] || ! $this->tablesReady()) {
            return [];
        }

        $rows = SeoKeywordDna::query()
            ->whereIn('keyword_id', $keywordIds)
            ->orderBy('value')
            ->get(['keyword_id', 'value']);

        $out = [];
        foreach ($rows as $row) {
            $kid = (int) $row->keyword_id;
            $out[$kid] ??= [];
            $out[$kid][] = (string) $row->value;
        }

        return $out;
    }

    /**
     * @return list<array{value: string, count: int}>
     */
    public function coverageForCluster(int $siteId, string $clusterKey): array
    {
        if ($siteId <= 0 || trim($clusterKey) === '' || ! $this->tablesReady()) {
            return [];
        }

        $rows = SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->selectRaw('MIN(value) as value, normalized_value, COUNT(DISTINCT keyword_id) as kw_count')
            ->groupBy('normalized_value')
            ->orderByDesc('kw_count')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'value' => (string) $row->value,
                'count' => (int) $row->kw_count,
            ];
        }

        return $out;
    }

    /**
     * Compact DNA coverage for planning context.
     *
     * @param  list<string>  $clusterLabels
     * @return array<string, list<array{value: string, count: int}>>
     */
    public function coverageForClusterLabels(int $siteId, array $clusterLabels, int $limitPerCluster = 10): array
    {
        if ($siteId <= 0 || $clusterLabels === [] || ! $this->tablesReady()) {
            return [];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }

        $out = [];
        foreach ($clusterLabels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }

            $meta = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta::query()
                ->where('site_id', $siteId)
                ->where('canonical_phrase', $label)
                ->first();

            if (! $meta instanceof \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta) {
                continue;
            }

            $coverage = array_slice(
                $this->coverageForCluster($siteId, (string) $meta->cluster_key),
                0,
                $limitPerCluster,
            );
            if ($coverage !== []) {
                $out[$label] = $coverage;
            }
        }

        return $out;
    }
}
