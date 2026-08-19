<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\Seo\Models\SeoFinding;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;

/**
 * Prepared site intelligence for monthly MCP. Reads current stored state; never syncs.
 */
final class SiteMcpContextBuilder
{
    public function __construct(
        private readonly SiteMcpContentDistributionAggregator $contentDistribution,
        private readonly SiteMcpInternalLinkingAggregator $internalLinking,
        private readonly McpDataQualityGuard $dataQuality,
    ) {}

    public function build(Site $site, string $periodKey): MonthlyMcpSourcePayload
    {
        $siteId = (int) $site->id;
        $heartbeat = $this->jsonMeta($site, WordPressHeartbeatPollService::META_KEY);
        $link = $this->jsonMeta($site, 'seo_link_analysis_snapshot');
        $findings = $this->findings($siteId);
        $indexability = $this->indexability($siteId);
        $articles = $this->articleCounts($siteId);
        $distribution = $this->contentDistribution->aggregate($siteId);
        $linking = $this->internalLinking->aggregate($site);
        $publishing = $this->publishingStatus($siteId);
        $lastSync = $this->lastSyncAt($siteId);
        $sourceUpdatedAt = MonthlyMcpFreshness::maxIso([
            is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null,
            is_string($link['last_analyzed_at'] ?? null) ? (string) $link['last_analyzed_at'] : null,
            $lastSync,
            $findings['updated_at'],
        ]);

        $health = $this->healthLabel($heartbeat);
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

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Site,
            $metrics,
            $summary,
            $context,
            $sourceUpdatedAt,
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        $heartbeat = $this->jsonMeta($site, WordPressHeartbeatPollService::META_KEY);
        $link = $this->jsonMeta($site, 'seo_link_analysis_snapshot');

        return MonthlyMcpFreshness::maxIso([
            is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null,
            is_string($link['last_analyzed_at'] ?? null) ? (string) $link['last_analyzed_at'] : null,
            $this->lastSyncAt((int) $site->id),
            $this->findings((int) $site->id)['updated_at'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonMeta(Site $site, string $key): array
    {
        $decoded = SiteSyncSiteMeta::getJson($site, $key);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{critical: int, high: int, top: list<array<string, mixed>>, updated_at: ?string}
     */
    private function findings(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_findings')) {
            return ['critical' => 0, 'high' => 0, 'top' => [], 'updated_at' => null];
        }
        $open = SeoFinding::query()
            ->where('site_id', $siteId)
            ->where('status', SeoFinding::STATUS_OPEN)
            ->orderByDesc('id')
            ->get();
        $top = [];
        foreach ($open->take(10) as $finding) {
            $top[] = [
                'id' => (int) $finding->id,
                'type' => (string) $finding->type,
                'severity' => (string) $finding->severity,
                'title' => (string) $finding->title,
            ];
        }
        $updated = $open->max('updated_at');

        return [
            'critical' => $open->where('severity', 'critical')->count(),
            'high' => $open->where('severity', 'high')->count(),
            'top' => $top,
            'updated_at' => $updated !== null ? (string) $updated : null,
        ];
    }

    /**
     * @return array{indexable: int, noindex: int}
     */
    private function indexability(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            return ['indexable' => 0, 'noindex' => 0];
        }

        return [
            'indexable' => (int) SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where('is_indexable', true)
                ->count(),
            'noindex' => (int) SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where('is_indexable', false)
                ->count(),
        ];
    }

    /**
     * @return array{total: int, published: int, draft: int, scheduled: int, private: int, other: int}
     */
    private function articleCounts(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return ['total' => 0, 'published' => 0, 'draft' => 0, 'scheduled' => 0, 'private' => 0, 'other' => 0];
        }
        $base = SeoArticle::query()->where('site_id', $siteId)->where('status', '!=', 'trash');
        $publishing = $this->publishingStatus($siteId);

        return [
            'total' => (int) (clone $base)->count(),
            'published' => (int) ($publishing['published'] ?? 0),
            'draft' => (int) ($publishing['draft'] ?? 0),
            'scheduled' => (int) ($publishing['scheduled'] ?? 0),
            'private' => (int) ($publishing['private'] ?? 0),
            'other' => (int) ($publishing['other'] ?? 0),
        ];
    }

    /**
     * @return array{published: int, draft: int, scheduled: int, private: int, other: int}
     */
    private function publishingStatus(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return ['published' => 0, 'draft' => 0, 'scheduled' => 0, 'private' => 0, 'other' => 0];
        }
        $base = SeoArticle::query()->where('site_id', $siteId)->where('status', '!=', 'trash');
        $published = (int) (clone $base)->where('status', 'published')->count();
        $draft = (int) (clone $base)->where('status', 'draft')->count();
        $scheduled = (int) (clone $base)->where('status', 'scheduled')->count();
        $private = (int) (clone $base)->where('status', 'private')->count();
        $total = (int) (clone $base)->count();

        return [
            'published' => $published,
            'draft' => $draft,
            'scheduled' => $scheduled,
            'private' => $private,
            'other' => max(0, $total - $published - $draft - $scheduled - $private),
        ];
    }

    private function lastSyncAt(int $siteId): ?string
    {
        $articleSync = null;
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('articles')) {
            $col = $schema->hasColumn('articles', 'last_synced_at')
                ? 'last_synced_at'
                : ($schema->hasColumn('articles', 'wp_synced_at') ? 'wp_synced_at' : null);
            if ($col !== null) {
                $articleSync = SeoArticle::query()->where('site_id', $siteId)->max($col);
            }
        }
        $runFinished = null;
        if (SiteSyncInfrastructure::tablesReady() && SiteSyncInfrastructure::hasTable('seo_site_sync_runs')) {
            $run = SeoSiteSyncRun::query()->where('site_id', $siteId)->orderByDesc('id')->first();
            $runFinished = $run?->finished_at?->toIso8601String();
        }

        return MonthlyMcpFreshness::maxIso([
            is_string($articleSync) ? $articleSync : null,
            $runFinished,
        ]);
    }

    /**
     * @param  array<string, mixed>  $heartbeat
     */
    private function healthLabel(array $heartbeat): string
    {
        $status = (string) ($heartbeat['status'] ?? '');

        return match ($status) {
            'ok' => 'healthy',
            'degraded' => 'degraded',
            'error', 'failed' => 'unhealthy',
            default => $status !== '' ? $status : 'unknown',
        };
    }
}
