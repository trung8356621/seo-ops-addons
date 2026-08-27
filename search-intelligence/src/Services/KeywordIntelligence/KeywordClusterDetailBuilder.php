<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
        if ($keywordIds === []) {
            return null;
        }

        $keywordCount = count($keywordIds);
        $articleCount = 0;
        $linkCount = 0;
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $articleCount = (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
                ->whereIn('keyword_id', $keywordIds)
                ->whereNotNull('target_article_id')
                ->distinct()
                ->count('target_article_id');
            $linkCount = (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
                ->whereIn('keyword_id', $keywordIds)
                ->count();
        }

        $intents = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->selectRaw('seo_intent, COUNT(*) as total')
            ->groupBy('seo_intent')
            ->pluck('total', 'seo_intent')
            ->all();

        $primary = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->orderByRaw('LENGTH(COALESCE(normalized_text, \'\')) ASC')
            ->first();
        $primaryKeyword = $primary instanceof SeoKeywordClassification
            ? Keyword::query()->find((int) $primary->keyword_id)
            : null;

        $lastAnalyzed = SeoKeywordClassification::query()
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
            'keyword_count' => $keywordCount,
            'article_count' => $articleCount,
            'internal_links' => $linkCount,
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

}
