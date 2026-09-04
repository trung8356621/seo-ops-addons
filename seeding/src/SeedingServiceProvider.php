<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkExtractor;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkResourceService;
use Omnichannel\Addons\Seeding\LinkIntelligence\UrlNormalizer;
use Omnichannel\Addons\Seeding\Services\SeedingSocialPlatformDetector;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;

final class SeedingServiceProvider extends ServiceProvider
{
    public const SLUG = 'seeding';

    public function register(): void
    {
        $this->app->singleton(UrlNormalizer::class);
        $this->app->singleton(LinkExtractor::class);
        $this->app->singleton(LinkResourceService::class);
        $this->app->singleton(SeedingSocialPlatformDetector::class);
        $this->app->singleton(SeedingTopicService::class);

        $this->registerCapabilities();
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);
        $this->loadMigrationsFrom($root.'/database/migrations');
        $this->loadViewsFrom($root.'/resources/views', 'seeding');
        $this->loadTranslationsFrom($root.'/resources/lang', 'seeding');
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
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'addon.json';
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
