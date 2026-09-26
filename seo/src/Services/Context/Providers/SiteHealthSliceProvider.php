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

final class SiteHealthSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly SiteSyncContextReader $sync,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::SITE_HEALTH,
            description: 'WordPress heartbeat health status for the site.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $site = $this->requireSite($request->siteId);
        $heartbeat = $this->sync->heartbeat($site);
        $observed = is_string($heartbeat['observed_at'] ?? null) ? (string) $heartbeat['observed_at'] : null;
        $data = [
            'health' => $this->sync->healthLabel($heartbeat),
            'status' => (string) ($heartbeat['status'] ?? 'unknown'),
            'plugin_version' => (string) ($heartbeat['plugin_version'] ?? ''),
            'observed_at' => $observed,
        ];
        if ($request->view === ContextView::Detail) {
            $data['heartbeat'] = $heartbeat;
        }

        return ContextSlice::make(ContextSliceKey::SITE_HEALTH, $request->siteId, $data, $observed);
    }

    private function requireSite(int $siteId): Site
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            throw new InvalidArgumentException('Site not found.');
        }

        return $site;
    }
}
