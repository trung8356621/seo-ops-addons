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

        $keywordIds = $this->keywordIdsForCluster($siteId, $clusterKey);
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

        $groups = [];
        if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_rule_group_members')) {
            $groups = DB::connection('omi_seo_ai')->table('seo_keyword_rule_group_members as m')
                ->join('seo_keyword_rule_groups as g', 'g.id', '=', 'm.group_id')
                ->whereIn('m.keyword_id', $keywordIds)
                ->where('g.is_active', true)
                ->groupBy('g.id', 'g.group_key', 'g.label', 'g.sort_order')
                ->orderBy('g.sort_order')
                ->get([
                    'g.group_key',
                    'g.label',
                    DB::raw('COUNT(DISTINCT m.keyword_id) as keyword_count'),
                ])
                ->map(static fn ($row): array => [
                    'key' => (string) $row->group_key,
                    'label' => (string) $row->label,
                    'keyword_count' => (int) $row->keyword_count,
                ])
                ->all();
        }

        $primary = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->orderByRaw('CHAR_LENGTH(COALESCE(normalized_text, \'\')) ASC')
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

        return [
            'cluster_key' => $clusterKey,
            'label' => $this->clusters->displayLabel($clusterKey, $sample),
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
            'groups' => $groups,
            'coverage' => $this->coverageBuilder->score($keywordCount, $articleCount, count($groups), $intentDiversity),
            'last_analyzed' => $lastAnalyzed,
        ];
    }

    public function paginateKeywords(?int $siteId, string $clusterKey, int $perPage = 25): LengthAwarePaginator
    {
        $ids = $this->keywordIdsForCluster($siteId, $clusterKey);
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
     * @return list<int>
     */
    private function keywordIdsForCluster(?int $siteId, string $clusterKey): array
    {
        $query = SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey);
        if ($siteId !== null && $siteId > 0) {
            $ids = Keyword::query()->forSite($siteId)->select('id');
            $query->whereIn('keyword_id', $ids);
        }

        return $query->limit(5000)->pluck('keyword_id')->map(static fn ($id): int => (int) $id)->all();
    }
}
