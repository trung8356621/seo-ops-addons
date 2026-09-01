<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;

/**
 * Read-model for single-domain Dashboard keyword overview (topics + top keywords).
 */
final class DashboardKeywordOverviewService
{
    public const ROW_LIMIT = 10;

    public function __construct(
        private readonly KeywordUiInventoryQuery $inventory,
        private readonly KeywordClusterQuery $clusters,
    ) {}

    /**
     * @return array{
     *     total_keywords: int,
     *     topic_count: int,
     *     topics: list<array{
     *         cluster_key: string,
     *         label: string,
     *         internal_link_count: int,
     *         keyword_count: int,
     *         url: string
     *     }>,
     *     keywords: list<array{
     *         id: int,
     *         phrase: string,
     *         internal_link_count: int,
     *         is_focus: bool
     *     }>
     * }
     */
    public function forSite(int $siteId): array
    {
        $languageVariants = null;

        $totalKeywords = $this->inventory->count($siteId, $languageVariants);
        $summary = $this->clusters->summary($siteId, $languageVariants);
        $topicCount = (int) ($summary['topic_clusters'] ?? 0);

        return [
            'total_keywords' => $totalKeywords,
            'topic_count' => $topicCount,
            'topics' => $this->topTopics($siteId, $languageVariants),
            'keywords' => $this->topKeywordsByInternalLinks($siteId, $languageVariants),
        ];
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{cluster_key: string, label: string, internal_link_count: int, keyword_count: int, url: string}>
     */
    private function topTopics(int $siteId, ?array $languageVariants): array
    {
        if (! $this->clusters->classificationsReady()) {
            return [];
        }

        $items = $this->clusters->paginateClusters(
            $siteId,
            [
                'sort' => 'links_desc',
                'projection' => 'seo',
                'language_variants' => $languageVariants,
            ],
            self::ROW_LIMIT,
        )->items();

        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $clusterKey = trim((string) ($item['cluster_key'] ?? ''));
            if ($clusterKey === '') {
                continue;
            }

            $rows[] = [
                'cluster_key' => $clusterKey,
                'label' => (string) ($item['label'] ?? $clusterKey),
                'internal_link_count' => (int) ($item['internal_link_count'] ?? 0),
                'keyword_count' => (int) ($item['keyword_count'] ?? 0),
                'url' => KeywordResource::getUrl('cluster', ['clusterKey' => $clusterKey]),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{id: int, phrase: string, internal_link_count: int, is_focus: bool}>
     */
    private function topKeywordsByInternalLinks(int $siteId, ?array $languageVariants): array
    {
        $inventorySub = $this->inventory->keywordIdSubquery($siteId, $languageVariants);

        $query = DB::connection('omi_seo_ai')
            ->table('seo_link_maps as lm')
            ->join('articles as a', static function ($join) use ($siteId): void {
                $join->on('a.id', '=', 'lm.source_article_id')
                    ->where('a.site_id', '=', $siteId)
                    ->whereNull('a.deleted_at');
            })
            ->join('keywords as k', 'k.id', '=', 'lm.keyword_id')
            ->whereIn('k.id', $inventorySub)
            ->where('lm.status', '!=', SeoLinkMapStatus::Ignored->value)
            ->where('lm.link_type', SeoLinkMapType::Internal->value)
            ->groupBy('k.id', 'k.phrase')
            ->selectRaw('k.id as id, k.phrase as phrase, COUNT(lm.id) as internal_link_count')
            ->orderByDesc('internal_link_count')
            ->orderBy('k.phrase')
            ->limit(self::ROW_LIMIT);

        $rawRows = $query->get();
        if ($rawRows->isEmpty()) {
            return [];
        }

        /** @var list<int> $keywordIds */
        $keywordIds = $rawRows
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $focusIds = $this->focusKeywordIds($keywordIds);

        $rows = [];
        foreach ($rawRows as $row) {
            $keywordId = (int) ($row->id ?? 0);
            if ($keywordId <= 0) {
                continue;
            }

            $rows[] = [
                'id' => $keywordId,
                'phrase' => (string) ($row->phrase ?? ''),
                'internal_link_count' => (int) ($row->internal_link_count ?? 0),
                'is_focus' => isset($focusIds[$keywordId]),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true>
     */
    private function focusKeywordIds(array $keywordIds): array
    {
        if ($keywordIds === []) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')
            ->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->pluck('keyword_id');

        $map = [];
        foreach ($ids as $id) {
            $keywordId = (int) $id;
            if ($keywordId > 0) {
                $map[$keywordId] = true;
            }
        }

        return $map;
    }
}
