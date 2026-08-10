<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Cutover;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncProviderTimeline;
use Omnichannel\Addons\SiteSync\Models\SeoSiteCapability;
use App\Models\Site;

final class SiteSyncProviderTimelineService
{
    /**
     * Close previous segment and open new when provider changes.
     */
    public function recordIfChanged(Site $site, string $provider, ?string $version, ?string $edition, array $snippet = []): void
    {
        if (! \Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure::hasTable('seo_site_sync_provider_timelines')) {
            return;
        }

        $siteId = (int) $site->id;
        $current = SeoSiteSyncProviderTimeline::query()
            ->where('site_id', $siteId)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        if ($current !== null && (string) $current->provider === $provider) {
            if ($version !== null && (string) $current->provider_version !== $version) {
                $current->forceFill(['provider_version' => $version, 'edition' => $edition])->save();
            }

            return;
        }

        if ($current !== null) {
            $current->forceFill([
                'ended_at' => now(),
                'reason' => 'provider_changed',
            ])->save();
        }

        SeoSiteSyncProviderTimeline::query()->create([
            'site_id' => $siteId,
            'provider' => $provider !== '' ? $provider : 'none',
            'provider_version' => $version,
            'edition' => $edition,
            'started_at' => now(),
            'reason' => $current === null ? 'initial' : 'provider_changed',
            'manifest_snippet' => $snippet,
        ]);
    }

    public function syncFromCapability(Site $site): void
    {
        $cap = SeoSiteCapability::query()->where('site_id', (int) $site->id)->first();
        $manifest = is_array($cap?->manifest) ? $cap->manifest : [];
        $provider = (string) ($manifest['provider']['id'] ?? $manifest['capabilities']['seo_metadata']['provider'] ?? 'none');
        $version = (string) ($manifest['provider']['version'] ?? $manifest['capabilities']['seo_metadata']['provider_version'] ?? '');
        $edition = (string) ($manifest['provider']['edition'] ?? '');
        $this->recordIfChanged(
            $site,
            $provider,
            $version !== '' ? $version : null,
            $edition !== '' ? $edition : null,
            [
                'capability_keys' => array_keys($manifest['capabilities'] ?? []),
            ],
        );
    }
}
