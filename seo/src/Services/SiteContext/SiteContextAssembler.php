<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpDataQualityGuard;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;
use Omnichannel\Addons\Seo\Services\SiteContext\Dto\SiteContext;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteContentContextReader;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteLinkContextReader;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SitePublishingContextReader;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSeoHealthReader;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSyncContextReader;

/**
 * Assembles Site Intelligence Context from domain-owned readers.
 *
 * Reads current stored state; never syncs or HTTP-loopbacks.
 */
final class SiteContextAssembler
{
    public function __construct(
        private readonly SiteSyncContextReader $sync,
        private readonly SiteSeoHealthReader $seoHealth,
        private readonly SiteContentContextReader $content,
        private readonly SiteLinkContextReader $links,
        private readonly SitePublishingContextReader $publishing,
        private readonly McpDataQualityGuard $dataQuality,
    ) {}

    public function assemble(Site $site, string $periodKey): SiteContext
    {
        $siteId = (int) $site->id;
        $heartbeat = $this->sync->heartbeat($site);
        $link = $this->links->analysisSnapshot($site);
        $findings = $this->seoHealth->findings($siteId);
        $indexability = $this->seoHealth->indexability($siteId);
        $articles = $this->content->articleCounts($siteId);
        $distribution = $this->content->distribution($siteId);
        $linking = $this->links->internalLinking($site);
        $publishing = $this->publishing->status($siteId);
        $lastSync = $this->sync->lastSyncAt($siteId);
        $sourceUpdatedAt = MonthlyMcpFreshness::maxIso([
            is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null,
            is_string($link['last_analyzed_at'] ?? null) ? (string) $link['last_analyzed_at'] : null,
            $lastSync,
            $findings['updated_at'],
        ]);

        $health = $this->sync->healthLabel($heartbeat);
        $critical = $findings['critical'];
        $high = $findings['high'];
        $risks = [];
        foreach (array_slice($findings['top'], 0, 10) as $row) {
            $risks[] = [
                'id' => $row['id'],
                'type' => $row['type'],
                'severity' => $row['severity'],
                'title' => $row['title'],
            ];
        }
        $opportunities = [];
        if ((int) ($link['opportunities'] ?? 0) > 0) {
            $opportunities[] = [
                'key' => 'internal_link_opportunity',
                'count' => (int) $link['opportunities'],
            ];
        }
        if ((int) ($link['orphan_pages'] ?? 0) > 0) {
            $opportunities[] = [
                'key' => 'orphan_pages',
                'count' => (int) $link['orphan_pages'],
            ];
        }

        $metrics = [
            'health' => $health,
            'indexable' => $indexability['indexable'],
            'noindex' => $indexability['noindex'],
            'critical_findings' => $critical,
            'high_findings' => $high,
            'article_total' => $articles['total'],
            'article_published' => $articles['published'],
            'broken_links' => array_key_exists('broken_links', $link) ? (int) $link['broken_links'] : null,
            'internal_links' => $linking['total_internal_links'],
            'internally_linked_articles' => $linking['linked_articles'],
            'articles_without_internal_links' => $linking['articles_without_internal_links'],
            'categories' => $distribution['categories'],
            'orphan_pages' => array_key_exists('orphan_pages', $link) ? (int) $link['orphan_pages'] : null,
            'link_opportunities' => array_key_exists('opportunities', $link) ? (int) $link['opportunities'] : null,
        ];
        $qualityWarnings = $this->dataQuality->siteWarnings((int) $articles['total'], $distribution, $linking);
        $summary = [
            'identity' => [
                'site_id' => $siteId,
                'domain' => (string) ($site->domain ?? ''),
            ],
            'wordpress' => [
                'status' => (string) ($heartbeat['status'] ?? 'unknown'),
                'plugin_version' => (string) ($heartbeat['plugin_version'] ?? ''),
                'observed_at' => $heartbeat['observed_at'] ?? null,
            ],
            'indexability' => $indexability,
            'seo_freshness' => [
                'last_sync_at' => $lastSync,
                'source_stale' => MonthlyMcpFreshness::isSourceStale($sourceUpdatedAt),
            ],
            'link_health' => [
                'internal_links' => $linking['total_internal_links'],
                'internally_linked_articles' => $linking['linked_articles'],
                'articles_without_internal_links' => $linking['articles_without_internal_links'],
                'articles_single_internal_link' => $linking['articles_single_internal_link'],
                'average_links_per_linked_article' => $linking['average_links_per_linked_article'],
                'top_linked_articles' => $linking['top_linked_articles'],
                'broken_links' => array_key_exists('broken_links', $link) ? (int) $link['broken_links'] : null,
                'orphan_pages' => array_key_exists('orphan_pages', $link) ? (int) $link['orphan_pages'] : null,
                'opportunities' => array_key_exists('opportunities', $link) ? (int) $link['opportunities'] : null,
                'last_analyzed_at' => $link['last_analyzed_at'] ?? null,
                'available' => (bool) ($linking['available'] ?? false),
                'source' => (string) ($linking['source'] ?? 'unavailable'),
            ],
            'internal_linking' => $linking,
            'content_distribution' => $distribution,
            'publishing_status' => $publishing,
            'data_quality' => [
                'warnings' => $qualityWarnings,
            ],
            'findings' => [
                'critical' => $critical,
                'high' => $high,
                'top' => array_slice($findings['top'], 0, 10),
            ],
            'articles' => $articles,
        ];
        $context = [
            'schema' => McpSourceKey::Site->schema(),
            'period' => $periodKey,
            'site_id' => $siteId,
            'risks' => $risks,
            'opportunities' => $opportunities,
        ];

        return new SiteContext(
            siteId: $siteId,
            periodKey: $periodKey,
            metrics: $metrics,
            summary: $summary,
            context: $context,
            sourceUpdatedAt: $sourceUpdatedAt,
            generatedAt: now()->toIso8601String(),
            available: true,
            stale: SiteContext::computeStale($sourceUpdatedAt),
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        $heartbeat = $this->sync->heartbeat($site);
        $link = $this->links->analysisSnapshot($site);

        return MonthlyMcpFreshness::maxIso([
            is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null,
            is_string($link['last_analyzed_at'] ?? null) ? (string) $link['last_analyzed_at'] : null,
            $this->sync->lastSyncAt((int) $site->id),
            $this->seoHealth->findings((int) $site->id)['updated_at'],
        ]);
    }
}
