<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\SearchIntelligence\Console\ReclusterSiteTopicsCommand;
use Omnichannel\Addons\SearchIntelligence\Contracts\TopicMembershipCapability;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipCapabilityService;

/**
 * Peer addon: Search Intelligence + site-scoped Topic Core.
 */
final class SearchIntelligenceServiceProvider extends ServiceProvider
{
    public const SLUG = 'search-intelligence';

    public function register(): void
    {
        $this->registerCapabilities();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReclusterSiteTopicsCommand::class,
            ]);
        }
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
            if ($id === TopicMembershipCapability::ID) {
                $caps->register(
                    $id,
                    $this->app->make(TopicMembershipCapabilityService::class),
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
