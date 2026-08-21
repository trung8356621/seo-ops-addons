<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\SiteSync\Contracts\SiteLinkCatalogCapability;
use Omnichannel\Addons\SiteSync\Services\Capabilities\SiteLinkCatalogCapabilityService;

/**
 * Peer addon skeleton: registers capabilities into Client Core.
 * Implementation still migrating out of SeoContentAi legacy monolith.
 */
final class SiteSyncServiceProvider extends ServiceProvider
{
    public const SLUG = 'site-sync';

    public function register(): void
    {
        $this->registerCapabilities();
    }

    public function boot(): void
    {
        // Routes/migrations attach as extraction progresses.
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);
        foreach ($this->providedCapabilityIds() as $id) {
            if ($caps->has($id)) {
                continue;
            }
            if ($id === SiteLinkCatalogCapability::ID) {
                $caps->register(
                    $id,
                    $this->app->make(SiteLinkCatalogCapabilityService::class),
                    self::SLUG,
                );
                continue;
            }
            $caps->register($id, new CapabilityMarker($id, self::SLUG), self::SLUG);
        }
    }

    /** @return list<string> */
    private function providedCapabilityIds(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'addon.json';
        if (! is_file($path)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($path), true);
        if (! is_array($meta) || ! is_array($meta['provides'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $meta['provides'])));
    }
}

final class CapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
