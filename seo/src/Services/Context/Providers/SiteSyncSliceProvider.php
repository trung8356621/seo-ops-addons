<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use App\Models\Site;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSyncContextReader;

final class SiteSyncSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteSyncContextReader $sync,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::SITE_SYNC,
            description: 'Site Sync freshness and last sync timestamps.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $site = Site::query()->find($request->siteId);
        if (! $site instanceof Site) {
            throw new InvalidArgumentException('Site not found.');
        }
        $lastSync = $this->sync->lastSyncAt($request->siteId);
        $heartbeat = $this->sync->heartbeat($site);
        $observed = is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null;

        return ContextSlice::make(
            ContextSliceKey::SITE_SYNC,
            $request->siteId,
            [
                'last_sync_at' => $lastSync,
                'heartbeat_observed_at' => $observed,
                'wordpress_status' => (string) ($heartbeat['status'] ?? 'unknown'),
            ],
            $lastSync ?? $observed,
        );
    }
}
