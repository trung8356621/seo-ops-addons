<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;

final class TopicClusterProposalMemberStateLoader
{
    /**
     * @param  list<int>  $keywordIds
     * @return array<int, TopicClusterProposalMemberState>
     */
    public function loadForSite(int $siteId, array $keywordIds): array
    {
        if ($siteId <= 0 || $keywordIds === []) {
            return [];
        }

        $allowedIds = array_fill_keys(KeywordClusterSiteScope::keywordIds($siteId), true);
        $keywordIds = array_values(array_filter(
            $keywordIds,
            static fn (int $id): bool => isset($allowedIds[$id]),
        ));
        sort($keywordIds, SORT_NUMERIC);

        if ($keywordIds === []) {
            return [];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->get()
            ->keyBy('keyword_id');

        $states = [];
        foreach ($keywordIds as $keywordId) {
            $row = $rows->get($keywordId);
            if (! $row instanceof SeoKeywordClassification) {
                continue;
            }

            $states[$keywordId] = new TopicClusterProposalMemberState(
                keywordId: $keywordId,
                classificationHash: (string) ($row->classification_hash ?? ''),
                inputHash: (string) ($row->input_hash ?? ''),
                phraseKind: (string) ($row->phrase_kind ?? ''),
                seoIntent: (string) ($row->seo_intent ?? ''),
                isSeoKeyword: (bool) ($row->is_seo_keyword ?? true),
                isAmbiguous: (bool) ($row->is_ambiguous ?? false),
                clusterKey: trim((string) ($row->cluster_key ?? '')),
                groupKeys: [],
            );
        }

        return $states;
    }
}
