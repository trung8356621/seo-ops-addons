<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo;

use App\Core\Capability\CapabilityRegistry;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Peer addon skeleton: registers capabilities into Client Core.
 * Implementation still migrating out of SeoContentAi legacy monolith.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public const SLUG = 'seo';

    public function register(): void
    {
        $this->app->singleton(DomainContextResolver::class);
        $this->registerCapabilities();
        $this->app->singleton(
            \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry::class,
            static function ($app): \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry {
                return new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry([
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\SiteMonthlyMcpSource::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\GscMonthlyMcpSource::class),
                ]);
            },
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'seo');
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
