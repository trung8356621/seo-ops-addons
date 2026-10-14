<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteStatistics;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\Seo\Services\FocusKeywordCoverageService;
use Omnichannel\Addons\SiteSync\Services\LinkAnalysis\DomainLinkInventoryReadModel;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;

/**
 * Builds a statistics snapshot by reusing the existing aggregate services.
 * Not called from Domain General GET.
 */
class SiteStatisticsRebuilder
{
    public function __construct(
        private readonly DomainOverviewService $overview,
        private readonly FocusKeywordCoverageService $focusKeywords,
        private readonly DomainLinkInventoryReadModel $linkInventory,
        private readonly SiteSyncPreflightService $preflight,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $siteId, int $generation): array
    {
        $site = Site::query()->find($siteId);
        $coverage = $this->focusKeywords->forSite($siteId, withMissingIds: false);
        unset($coverage['missing_article_ids']);

        $topKeywords = $this->overview->getTopKeywords($siteId)
            ->map(static fn (object $row): array => [
                'id' => (int) ($row->id ?? 0),
                'phrase' => (string) ($row->phrase ?? ''),
                'articles_count' => (int) ($row->articles_count ?? 0),
            ])
            ->values()
            ->all();

        $topLinks = $this->overview->getTopLinks($siteId)
            ->map(static fn (object $row): array => [
                'url' => (string) ($row->url ?? ''),
                'type' => (string) ($row->type ?? ''),
                'articles_count' => (int) ($row->articles_count ?? 0),
            ])
            ->values()
            ->all();

        $preflight = $site instanceof Site ? $this->preflight->evaluateLocalOnly($site) : null;

        return [
            'schema_version' => SiteStatisticsSnapshotStore::SCHEMA_VERSION,
            'site_id' => $siteId,
            'generation' => $generation,
            'generated_at' => now()->toIso8601String(),
            'statistics' => [
                'scoring' => $this->overview->getScoringStatistics($siteId),
                'score_distribution' => $this->overview->getScoreDistribution($siteId),
                'sync' => $this->overview->getSyncStatistics($siteId),
                'top_keywords' => $topKeywords,
                'top_links' => $topLinks,
                'focus_keyword_coverage' => $coverage,
                'required_data_health' => is_array($preflight['data_health'] ?? null) ? $preflight['data_health'] : null,
                'preflight_local' => $preflight,
                'link_inventory' => $site instanceof Site
                    ? $this->linkInventory->forSite($site)
                    : null,
            ],
        ];
    }
}
