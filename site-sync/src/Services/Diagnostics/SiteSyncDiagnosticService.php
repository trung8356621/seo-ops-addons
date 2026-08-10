<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Diagnostics;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Bootstrap\SiteSyncBootstrapService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Handshake\SiteSyncHandshakeService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncCutoverReadinessService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

/**
 * Readonly Site Sync diagnostic — never mutates data.
 */
final class SiteSyncDiagnosticService
{
    public function __construct(
        private readonly SiteSyncHandshakeService $handshake,
        private readonly SiteSyncBootstrapService $bootstrap,
        private readonly SiteSyncCutoverReadinessService $cutover,
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(Site $site): array
    {
        $siteId = (int) $site->id;
        $latestBootstrap = SeoSiteSyncRun::query()
            ->where('site_id', $siteId)
            ->where('meta->bootstrap', true)
            ->orderByDesc('id')
            ->first();
        $latestIncremental = SeoSiteSyncRun::query()
            ->where('site_id', $siteId)
            ->where(function ($q): void {
                $q->whereNull('meta->bootstrap')->orWhere('meta->bootstrap', false);
            })
            ->orderByDesc('id')
            ->first();
        $lastEvent = SeoSiteSyncInboundEvent::query()
            ->where('site_id', $siteId)
            ->orderByDesc('id')
            ->first();
        $cap = SeoSiteCapability::query()->where('site_id', $siteId)->first();
        $handshake = $this->handshake->current($site);
        $backfill = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_BACKFILL_REPORT);

        $keywordSources = [];
        if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source')) {
            $keywordSources = Keyword::query()
                ->when(
                    Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id') && $site->user_id,
                    static fn ($q) => $q->where('user_id', (int) $site->user_id),
                )
                ->selectRaw('source, count(*) as c')
                ->groupBy('source')
                ->pluck('c', 'source')
                ->all();
        }

        $scoreSources = SeoArticleScoreSource::query()
            ->where('site_id', $siteId)
            ->selectRaw('source, count(*) as c')
            ->groupBy('source')
            ->pluck('c', 'source')
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'site_id' => $siteId,
            'connection' => [
                'domain' => (string) $site->domain,
                'has_read_token' => trim((string) ($site->getMeta('seo_read_token') ?? '')) !== '',
            ],
            'plugin_version' => (string) ($handshake['bridge_version'] ?? ''),
            'contract_version' => SiteSyncSchema::VERSION,
            'callback_handshake' => $handshake,
            'flags' => [
                'enabled' => $this->flags->enabled(),
                'auto_push' => $this->flags->autoPushEnabled(),
                'legacy_visible' => $this->flags->legacyActionsVisible(),
                'emergency_rollback' => $this->flags->emergencyRollback(),
                'require_signed' => $this->flags->requireSignedCallbacks(),
            ],
            'needs_bootstrap' => $this->bootstrap->needsBootstrap($site),
            'bootstrapped_at' => (string) ($site->getMeta(SiteSyncSchema::META_BOOTSTRAPPED_AT) ?? ''),
            'latest_bootstrap_run' => $latestBootstrap ? [
                'id' => (int) $latestBootstrap->id,
                'status' => (string) $latestBootstrap->status,
                'public_ref' => (string) $latestBootstrap->public_ref,
            ] : null,
            'latest_incremental_run' => $latestIncremental ? [
                'id' => (int) $latestIncremental->id,
                'status' => (string) $latestIncremental->status,
                'mode' => (string) $latestIncremental->mode,
                'public_ref' => (string) $latestIncremental->public_ref,
            ] : null,
            'last_inbound_event' => $lastEvent ? [
                'id' => (int) $lastEvent->id,
                'type' => (string) $lastEvent->event_type,
                'status' => (string) $lastEvent->status,
            ] : null,
            'dead_letters' => SeoSiteSyncInboundEvent::query()
                ->where('site_id', $siteId)
                ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
                ->count(),
            'capabilities' => is_array($cap?->manifest) ? ($cap->manifest['capabilities'] ?? $cap->manifest) : null,
            'provider' => is_array($cap?->manifest)
                ? (string) ($cap->manifest['capabilities']['seo_metadata']['provider'] ?? 'none')
                : 'none',
            'counts' => [
                'articles' => SeoArticle::query()->where('site_id', $siteId)->count(),
                'wp_links' => SeoSiteLinkCatalog::query()->forSite($siteId)->where('source', SiteSyncSchema::SOURCE_WORDPRESS)->count(),
                'manual_links' => SeoSiteManualLink::query()->where('site_id', $siteId)->count(),
            ],
            'keyword_sources' => $keywordSources,
            'score_sources' => $scoreSources,
            'backfill_report' => $backfill,
            'cutover' => $this->cutover->evaluate($site),
            'mutates' => false,
        ];
    }
}
