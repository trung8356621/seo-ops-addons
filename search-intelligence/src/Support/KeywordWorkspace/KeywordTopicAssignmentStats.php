<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;

/**
 * Shared Keyword ↔ Topic assignment stats for Topics UI + Dictionary deep-links.
 *
 * Canonical "Unassigned / No Topic":
 *   KeywordUiInventoryQuery scope (Dictionary/UI)
 *   AND no seo_topic_keywords row for this site.
 *
 * Does NOT change clustering eligibility (seo_site_keywords.is_seo_keyword).
 */
final class KeywordTopicAssignmentStats
{
    public function __construct(
        private readonly KeywordUiInventoryQuery $inventory,
        private readonly KeywordDictionaryQuery $dictionary,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants  Same workspace language gate as Dictionary tabs.
     * @return array{
     *     site_id: int,
     *     inventory_total: int,
     *     assigned: int,
     *     unassigned: int,
     *     topic_count: int,
     *     seo_eligible_clustering: int
     * }
     */
    public function forSite(int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId <= 0) {
            return $this->empty(0);
        }

        $inventoryTotal = $this->inventory->count($siteId, $languageVariants);
        $unassigned = (int) $this->dictionary
            ->filtered($siteId, $languageVariants, ['topic_assignment' => 'unassigned'])
            ->count();
        $assigned = (int) $this->dictionary
            ->filtered($siteId, $languageVariants, ['topic_assignment' => 'assigned'])
            ->count();

        $topicCount = 0;
        $seoEligibleClustering = 0;
        if (TopicReclusterService::tablesReady()) {
            $topicCount = (int) SeoTopic::query()->where('site_id', $siteId)->count();
            $seoEligibleClustering = (int) \Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->where('is_seo_keyword', true)
                ->count();
        }

        return [
            'site_id' => $siteId,
            'inventory_total' => $inventoryTotal,
            'assigned' => $assigned,
            'unassigned' => $unassigned,
            'topic_count' => $topicCount,
            // Diagnostic / clustering denominator only — not the Unassigned UX metric.
            'seo_eligible_clustering' => $seoEligibleClustering,
        ];
    }

    /**
     * @return array{
     *     site_id: int,
     *     inventory_total: int,
     *     assigned: int,
     *     unassigned: int,
     *     topic_count: int,
     *     seo_eligible_clustering: int
     * }
     */
    private function empty(int $siteId): array
    {
        return [
            'site_id' => $siteId,
            'inventory_total' => 0,
            'assigned' => 0,
            'unassigned' => 0,
            'topic_count' => 0,
            'seo_eligible_clustering' => 0,
        ];
    }
}
