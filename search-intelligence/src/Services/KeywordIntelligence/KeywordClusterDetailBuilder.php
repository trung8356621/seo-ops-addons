<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;

final class KeywordClusterDetailBuilder
{
    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly KeywordGroupCoverageBuilder $coverageBuilder,
        private readonly ?KeywordDnaService $dnaService = null,
        private readonly ?TopicIdeaCoverageService $ideaCoverage = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(?int $siteId, string $clusterKey): ?array
    {
        $clusterKey = trim($clusterKey);
        if ($clusterKey === '' || ! $this->clusters->classificationsReady()) {
            return null;
        }

        $keywordIds = $this->clusters->memberKeywordIds($siteId, $clusterKey);
        $keywordCount = count($keywordIds);
        $isManualEmpty = $keywordCount === 0 && $this->manualClusterMeta($siteId, $clusterKey) !== null;
        if ($keywordCount === 0 && ! $isManualEmpty) {
            return null;
        }
        $articleCount = 0;
        $linkCount = 0;
        if ($keywordIds !== []) {
            $linkStats = $this->clusters->memberLinkStats($keywordIds);
            $articleCount = (int) $linkStats['article_count'];
            $linkCount = (int) $linkStats['internal_link_count'];
        }

        $intents = $keywordIds === [] ? [] : SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->selectRaw('seo_intent, COUNT(*) as total')
            ->groupBy('seo_intent')
            ->pluck('total', 'seo_intent')
            ->all();

        $primary = $keywordIds === [] ? null : SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->orderByRaw('LENGTH(COALESCE(normalized_text, \'\')) ASC')
            ->first();
        $primaryKeyword = $primary instanceof SeoKeywordClassification
            ? Keyword::query()->find((int) $primary->keyword_id)
            : null;

        $lastAnalyzed = $keywordIds === [] ? null : SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->max('classified_at');

        $intentDiversity = count(array_filter(array_keys($intents), static fn ($key): bool => trim((string) $key) !== ''));
        $topIntent = '';
        $topCount = 0;
        foreach ($intents as $intent => $count) {
            if ((int) $count > $topCount && trim((string) $intent) !== '') {
                $topCount = (int) $count;
                $topIntent = (string) $intent;
            }
        }

        $sample = $primaryKeyword instanceof Keyword ? (string) $primaryKeyword->phrase : '';
        $label = $this->clusters->displayLabel($clusterKey, $sample, $siteId);

        $idea = null;
        $coverage = $this->ideaCoverage
            ?? (app()->bound(TopicIdeaCoverageService::class) ? app(TopicIdeaCoverageService::class) : null);
        if ($coverage instanceof TopicIdeaCoverageService && $siteId !== null && $siteId > 0) {
            $idea = $coverage->forCluster($siteId, $clusterKey);
        }

        $dnaCoverage = [];
        if ($idea !== null) {
            foreach ($idea['dna_branches'] as $branch) {
                $dnaCoverage[] = [
                    'value' => $branch['value'],
                    'count' => $branch['keyword_count'],
                    'article_count' => $branch['article_count'],
                    'content_coverage' => $branch['content_coverage'],
                    'examples' => $branch['examples'],
                ];
            }
        } else {
            $dna = $this->dnaService ?? (app()->bound(KeywordDnaService::class) ? app(KeywordDnaService::class) : null);
            if ($dna instanceof KeywordDnaService && $siteId !== null && $siteId > 0) {
                $dnaCoverage = $dna->coverageForCluster($siteId, $clusterKey);
            }
        }

        $dnaBranchCount = (int) (($idea ?? [])['dna_branch_count'] ?? 0);

        return [
            'cluster_key' => $clusterKey,
            'label' => $label,
            'canonical_phrase' => $label,
            'canonical_source' => $this->canonicalSource($siteId, $clusterKey),
            'keyword_count' => $keywordCount,
            'article_count' => $articleCount,
            'internal_links' => $linkCount,
            'internal_link_count' => $linkCount,
            'primary_keyword' => $sample !== '' ? $sample : $clusterKey,
            'intent' => $topIntent,
            'intent_counts' => [
                'informational' => (int) ($intents['informational'] ?? 0),
                'commercial' => (int) ($intents['commercial'] ?? 0),
                'transactional' => (int) ($intents['transactional'] ?? 0),
                'navigational' => (int) ($intents['navigational'] ?? 0),
            ],
            'groups' => [],
            'coverage' => $this->coverageBuilder->score($keywordCount, $articleCount, $dnaBranchCount, $intentDiversity),
            'last_analyzed' => $lastAnalyzed,
            'dna_coverage' => $dnaCoverage,
            'idea_coverage' => $idea,
        ];
    }

    private function canonicalSource(?int $siteId, string $clusterKey): string
    {
        if ($siteId === null || $siteId <= 0) {
            return 'auto';
        }
        if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return 'auto';
        }

        $source = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->value('canonical_source');

        return (string) ($source ?: 'auto');
    }

    public function paginateKeywords(?int $siteId, string $clusterKey, int $perPage = 25): LengthAwarePaginator
    {
        $ids = $this->clusters->memberKeywordIds($siteId, $clusterKey);
        if ($ids === []) {
            return Keyword::query()->whereRaw('1 = 0')->paginate($perPage);
        }

        return Keyword::query()
            ->with(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver::tableEagerLoad())
            ->withCount(\Omnichannel\Addons\SearchFoundation\Models\Keyword::linkMapCountRelations())
            ->whereIn('id', $ids)
            ->orderBy('phrase')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manualClusterMeta(?int $siteId, string $clusterKey): ?array
    {
        if ($siteId === null || $siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return null;
        }

        $meta = \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
        if (! $meta instanceof \Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta) {
            return null;
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')
            && ! $meta->isManual()) {
            return null;
        }

        return [
            'canonical_phrase' => (string) $meta->canonical_phrase,
            'canonical_source' => (string) ($meta->canonical_source ?? 'auto'),
        ];
    }

}
