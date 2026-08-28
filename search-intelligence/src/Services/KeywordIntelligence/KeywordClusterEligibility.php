<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;

final class KeywordClusterEligibility
{
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
    public function summaryMetrics(?int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        $total = count($keywordIds);

        if ($total === 0 || ! KeywordClassificationVisibility::tableReady()) {
            return [
                'total_keywords' => $total,
                'classified_keywords' => 0,
                'seo_eligible_keywords' => 0,
                'clustered' => 0,
                'unclustered' => 0,
                'unclassified_keywords' => $total,
                'non_seo_keywords' => 0,
                'non_seo_but_clustered' => 0,
                'topic_clusters' => 0,
                'hidden_keywords' => 0,
                'system_groups' => 0,
                'custom_groups' => 0,
            ];
        }

        $hiddenMap = app(HideKeywordFromSeoService::class)->hiddenKeywordIdMap($keywordIds);
        $hiddenIds = array_keys($hiddenMap);
        $visibleIds = array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => ! isset($hiddenMap[$id]),
        ));

        $classificationQuery = SeoKeywordClassification::query()->whereIn('keyword_id', $keywordIds);
        $classified = (clone $classificationQuery)->count();
        $unclassified = max(0, $total - $classified);

        $visibleClassification = SeoKeywordClassification::query()->whereIn(
            'keyword_id',
            $visibleIds === [] ? [0] : $visibleIds,
        );

        $seoEligible = (clone $visibleClassification)
            ->tap(fn (Builder $query): Builder => $this->applySeoEligibleScope($query))
            ->count();

        $nonSeo = max(0, $classified - count($hiddenIds) - $seoEligible);
        if ($nonSeo < 0) {
            $nonSeo = max(0, $classified - $seoEligible);
        }

        $clustered = (clone $visibleClassification)
            ->tap(fn (Builder $query): Builder => $this->applySeoEligibleScope($query))
            ->tap(fn (Builder $query): Builder => $this->applyClusteredScope($query))
            ->count();

        $unclustered = (clone $visibleClassification)
            ->tap(fn (Builder $query): Builder => $this->applySeoEligibleScope($query))
            ->tap(fn (Builder $query): Builder => $this->applyUnclusteredScope($query))
            ->count();

        $nonSeoButClustered = (clone $visibleClassification)
            ->tap(fn (Builder $query): Builder => $this->applyNonSeoScope($query))
            ->tap(fn (Builder $query): Builder => $this->applyClusteredScope($query))
            ->count();

        $topicClusters = (int) (clone $visibleClassification)
            ->tap(fn (Builder $query): Builder => $this->applyClusteredScope($query))
            ->distinct()
            ->count('cluster_key');

        return [
            'total_keywords' => $total,
            'classified_keywords' => $classified,
            'seo_eligible_keywords' => $seoEligible,
            'clustered' => $clustered,
            'unclustered' => $unclustered,
            'unclassified_keywords' => $unclassified,
            'non_seo_keywords' => $nonSeo,
            'non_seo_but_clustered' => $nonSeoButClustered,
            'topic_clusters' => $topicClusters,
            'hidden_keywords' => count($hiddenIds),
            'system_groups' => 0,
            'custom_groups' => 0,
        ];
    }

    public function isProposalCandidate(SeoKeywordClassification $row): bool
    {
        if (! $this->isSeoEligible($row)) {
            return false;
        }

        if (app(HideKeywordFromSeoService::class)->isHidden((int) $row->keyword_id)) {
            return false;
        }

        $clusterKey = trim((string) ($row->cluster_key ?? ''));

        return $clusterKey === '';
    }

    public function isSeoEligible(SeoKeywordClassification $row): bool
    {
        if (KeywordClassificationVisibility::hasSeoKeywordColumn() && $row->is_seo_keyword !== null) {
            return (bool) $row->is_seo_keyword;
        }

        return in_array((string) ($row->phrase_kind ?? ''), [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            KeywordRuleClassifier::KIND_QUERY,
            KeywordRuleClassifier::KIND_BRAND_ENTITY,
        ], true);
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applyUnclusteredSeoKeywordScope(Builder $query): Builder
    {
        if (! KeywordClassificationVisibility::tableReady()) {
            return $query->whereRaw('0 = 1');
        }

        return $this->applyNotHiddenKeywordScope($query)->whereHas(
            'seoClassification',
            function (Builder $classification): void {
                $this->applySeoEligibleScope($classification);
                $this->applyUnclusteredScope($classification);
            },
        );
    }

    /**
     * Soft-hidden keywords stay in DB but leave default SEO / clustering pools.
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function applyNotHiddenKeywordScope(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'metas',
            static function (Builder $meta): void {
                $meta->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::SeoHidden->value)
                    ->where('meta_value', '1');
            },
        );
    }

    /**
     * @param  Builder<SeoKeywordClassification>  $query
     * @return Builder<SeoKeywordClassification>
     */
    public function applySeoEligibleScope(Builder $query): Builder
    {
        if (KeywordClassificationVisibility::hasSeoKeywordColumn()) {
            return $query->where('is_seo_keyword', true);
        }

        return $query->whereIn('phrase_kind', [
            KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
            KeywordRuleClassifier::KIND_QUERY,
            KeywordRuleClassifier::KIND_BRAND_ENTITY,
        ]);
    }

    /**
     * @param  Builder<SeoKeywordClassification>  $query
     * @return Builder<SeoKeywordClassification>
     */
    public function applyNonSeoScope(Builder $query): Builder
    {
        if (KeywordClassificationVisibility::hasSeoKeywordColumn()) {
            return $query->where(function (Builder $inner): void {
                $inner->where('is_seo_keyword', false)->orWhereNull('is_seo_keyword');
            });
        }

        return $query->where(function (Builder $inner): void {
            $inner->whereNotIn('phrase_kind', [
                KeywordRuleClassifier::KIND_KEYWORD_PHRASE,
                KeywordRuleClassifier::KIND_QUERY,
                KeywordRuleClassifier::KIND_BRAND_ENTITY,
            ])->orWhereNull('phrase_kind');
        });
    }

    /**
     * @param  Builder<SeoKeywordClassification>  $query
     * @return Builder<SeoKeywordClassification>
     */
    public function applyUnclusteredScope(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('cluster_key')->orWhere('cluster_key', '');
        });
    }

    /**
     * @param  Builder<SeoKeywordClassification>  $query
     * @return Builder<SeoKeywordClassification>
     */
    public function applyClusteredScope(Builder $query): Builder
    {
        return $query->whereNotNull('cluster_key')->where('cluster_key', '!=', '');
    }

    /**
     * @return array<string, array{seo_true: int, seo_false: int}>
     */
    public function phraseKindDistribution(?int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === [] || ! KeywordClassificationVisibility::tableReady()) {
            return [];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->get(['phrase_kind', 'is_seo_keyword']);

        $distribution = [];
        foreach ($rows as $row) {
            $kind = trim((string) ($row->phrase_kind ?? 'unknown'));
            if ($kind === '') {
                $kind = 'unknown';
            }

            if (! isset($distribution[$kind])) {
                $distribution[$kind] = ['seo_true' => 0, 'seo_false' => 0];
            }

            $isSeo = $this->isSeoEligible($row);
            if ($isSeo) {
                $distribution[$kind]['seo_true']++;
            } else {
                $distribution[$kind]['seo_false']++;
            }
        }

        ksort($distribution, SORT_STRING);

        return $distribution;
    }
}
