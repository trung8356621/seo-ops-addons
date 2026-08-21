<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $groupMap = $this->groupKeysByKeyword($keywordIds);
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
                groupKeys: $groupMap[$keywordId] ?? [],
            );
        }

        return $states;
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
            sort($map[$keywordId], SORT_STRING);
        }

        return $map;
    }
}
