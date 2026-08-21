<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

final class KeywordClusterProposalReader
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordClusterTokenAnalyzer $tokenAnalyzer,
        private readonly KeywordClusterEligibility $eligibility,
    ) {}

    /**
     * @return array{
     *     protected_cluster_count: int,
     *     protected_clustered_keywords: int,
     *     profiles: list<KeywordClusterTokenProfile>,
     * }
     */
    public function loadContext(int $siteId): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return [
                'protected_cluster_count' => 0,
                'protected_clustered_keywords' => 0,
                'profiles' => [],
            ];
        }

        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [
                'protected_cluster_count' => 0,
                'protected_clustered_keywords' => 0,
                'profiles' => [],
            ];
        }

        $protectedStats = $this->protectedStats($keywordIds);
        $groupMap = $this->groupKeysByKeyword($keywordIds);
        $profiles = [];

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->where(function ($query): void {
                $query->whereNull('cluster_key')->orWhere('cluster_key', '');
            })
            ->with(['keyword'])
            ->get();

        foreach ($rows as $row) {
            if (! $row instanceof SeoKeywordClassification) {
                continue;
            }
            if (! $this->eligibility->isProposalCandidate($row)) {
                continue;
            }

            $keyword = $row->keyword;
            $phrase = trim((string) ($keyword?->phrase ?? $row->normalized_text ?? ''));
            if ($phrase === '') {
                continue;
            }

            $normalizedText = trim((string) ($row->normalized_text ?? ''));
            $foldedText = trim((string) ($row->folded_text ?? ''));
            if ($normalizedText === '' || $foldedText === '') {
                $norm = $this->normalizer->normalize($phrase);
                $normalizedText = $norm['normalized_text'];
                $foldedText = $norm['folded_text'];
            }

            $analysis = $this->tokenAnalyzer->analyze($foldedText);
            if ($analysis['tokens'] === []) {
                continue;
            }

            $profiles[] = new KeywordClusterTokenProfile(
                keywordId: (int) $row->keyword_id,
                phrase: $phrase,
                normalizedText: $normalizedText,
                foldedText: $foldedText,
                seoIntent: (string) ($row->seo_intent ?? ''),
                isAmbiguous: (bool) ($row->is_ambiguous ?? false),
                tokens: $analysis['tokens'],
                bigrams: $analysis['bigrams'],
                significantTokens: $analysis['significant_tokens'],
                significantPhrase: $analysis['significant_phrase'],
                groupKeys: $groupMap[(int) $row->keyword_id] ?? [],
            );
        }

        usort(
            $profiles,
            static fn (KeywordClusterTokenProfile $a, KeywordClusterTokenProfile $b): int => $a->keywordId <=> $b->keywordId,
        );

        return [
            'protected_cluster_count' => $protectedStats['cluster_count'],
            'protected_clustered_keywords' => $protectedStats['keyword_count'],
            'profiles' => $profiles,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array{cluster_count: int, keyword_count: int}
     */
    private function protectedStats(array $keywordIds): array
    {
        $query = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '');

        $keywordCount = (clone $query)->count();
        $clusterCount = (int) (clone $query)->distinct()->count('cluster_key');

        return [
            'cluster_count' => $clusterCount,
            'keyword_count' => $keywordCount,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, list<string>>
     */
    private function groupKeysByKeyword(array $keywordIds): array
    {
        if ($keywordIds === []
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_rule_group_members')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_rule_groups')) {
            return [];
        }

        $rows = DB::connection('omi_seo_ai')->table('seo_keyword_rule_group_members as m')
            ->join('seo_keyword_rule_groups as g', 'g.id', '=', 'm.group_id')
            ->whereIn('m.keyword_id', $keywordIds)
            ->where('g.is_active', true)
            ->select(['m.keyword_id', 'g.group_key'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->keyword_id;
            $map[$keywordId] ??= [];
            $map[$keywordId][] = (string) $row->group_key;
        }

        foreach ($map as $keywordId => $keys) {
            $map[$keywordId] = array_values(array_unique($keys));
        }

        return $map;
    }
}
