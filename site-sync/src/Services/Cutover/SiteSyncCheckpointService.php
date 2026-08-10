<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Cutover;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncCheckpoint;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Handshake\SiteSyncHandshakeService;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class SiteSyncCheckpointService
{
    public function __construct(
        private readonly SiteSyncHandshakeService $handshake,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(Site $site, string $currentMode): array
    {
        $siteId = (int) $site->id;
        $site->loadMissing('metas');
        $pluginInfo = $site->getMeta('seo_wp_plugin_info');
        $decoded = is_string($pluginInfo) ? json_decode($pluginInfo, true) : (is_array($pluginInfo) ? $pluginInfo : []);
        $cap = SeoSiteCapability::query()->where('site_id', $siteId)->first();
        $manifest = is_array($cap?->manifest) ? $cap->manifest : [];
        $provider = (string) ($manifest['provider']['id'] ?? $manifest['capabilities']['seo_metadata']['provider'] ?? 'none');
        $lastV2 = SeoSiteSyncRun::query()->where('site_id', $siteId)->orderByDesc('id')->first();
        $dead = SeoSiteSyncInboundEvent::query()
            ->where('site_id', $siteId)
            ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
            ->count();

        $keywordCount = 0;
        if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source')) {
            $keywordCount = Keyword::query()
                ->when(
                    Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id') && $site->user_id,
                    static fn ($q) => $q->where('user_id', (int) $site->user_id),
                )
                ->count();
        }

        return [
            'site_id' => $siteId,
            'connection_hash' => (string) ($site->getMeta('seo_connection_hash') ?? ''),
            'current_mode' => $currentMode,
            'plugin_version' => (string) ($decoded['bridge_version'] ?? $cap?->bridge_version ?? ''),
            'contract_version' => SiteSyncSchema::VERSION,
            'feature_flags' => [
                'enabled' => (bool) config('seo-content-ai.seo_architecture.site_sync_v2.enabled', true),
                'auto_push' => (bool) config('seo-content-ai.seo_architecture.site_sync_v2.auto_push', true),
                'reconciliation' => (bool) config('seo-content-ai.seo_architecture.site_sync_v2.reconciliation', true),
                'legacy_actions' => (bool) config('seo-content-ai.seo_architecture.site_sync_v2.legacy_actions', false),
                'emergency_rollback' => (bool) config('seo-content-ai.seo_architecture.site_sync_v2.emergency_rollback', false),
            ],
            'last_legacy_sync_at' => (string) ($site->getMeta('seo_last_legacy_sync_at') ?? ''),
            'last_v2_run' => $lastV2 ? [
                'id' => (int) $lastV2->id,
                'status' => (string) $lastV2->status,
                'mode' => (string) $lastV2->mode,
                'finished_at' => optional($lastV2->finished_at)?->toIso8601String(),
            ] : null,
            'capability_manifest' => [
                'provider' => $provider,
                'bridge_version' => (string) ($cap?->bridge_version ?? ''),
                'keys' => array_keys($manifest['capabilities'] ?? []),
            ],
            'provider' => $provider,
            'counts' => [
                'articles' => SeoArticle::query()->where('site_id', $siteId)->count(),
                'wp_links' => SeoSiteLinkCatalog::query()->forSite($siteId)->where('source', SiteSyncSchema::SOURCE_WORDPRESS)->count(),
                'manual_links' => SeoSiteManualLink::query()->where('site_id', $siteId)->count(),
                'keywords' => $keywordCount,
                'scores' => SeoArticleScoreSource::query()->where('site_id', $siteId)->count(),
            ],
            'ownership_summary' => [
                'manual_links' => SeoSiteManualLink::query()->where('site_id', $siteId)->count(),
                'manual_keywords_locked' => Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source_locked')
                    ? Keyword::query()->where('source_locked', true)->count()
                    : 0,
            ],
            'dead_letter_count' => $dead,
            'handshake' => $this->handshake->current($site),
            'bootstrapped_at' => (string) ($site->getMeta(SiteSyncSchema::META_BOOTSTRAPPED_AT) ?? ''),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function create(
        Site $site,
        string $purpose,
        string $fromMode,
        ?string $toMode,
        ?string $actorType,
        ?int $actorId,
        ?string $reason,
    ): SeoSiteSyncCheckpoint {
        return SeoSiteSyncCheckpoint::query()->create([
            'site_id' => (int) $site->id,
            'purpose' => $purpose,
            'from_mode' => $fromMode,
            'to_mode' => $toMode,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'snapshot' => $this->buildSnapshot($site, $fromMode),
        ]);
    }
}
