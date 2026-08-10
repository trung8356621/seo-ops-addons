<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Capability\Contracts\PublisherCapability;
use Illuminate\Support\ServiceProvider;
use Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisher;
use Omnichannel\Addons\Publishing\Application\Publishing\PublisherResolver;

/**
 * Publishing peer — registers PublisherCapability over ContentPublisher port.
 */
final class PublishingServiceProvider extends ServiceProvider
{
    public const SLUG = 'publishing';

    public function register(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);

        if (! $caps->has('publishing.queue')) {
            $caps->register('publishing.queue', new CapabilityMarker('publishing.queue', self::SLUG), self::SLUG);
        }

        if (! $caps->has('publisher') && interface_exists(ContentPublisher::class)) {
            $caps->register('publisher', new class($this->app) implements PublisherCapability
            {
                public function __construct(private readonly \Illuminate\Contracts\Foundation\Application $app) {}

                public function publish(array $payload): array
                {
                    /** @var PublisherResolver $resolver */
                    $resolver = $this->app->make(PublisherResolver::class);
                    $publisher = $resolver->resolve($payload['site_id'] ?? null, $payload['driver'] ?? null);
                    if (! $publisher instanceof ContentPublisher) {
                        return ['ok' => false, 'error' => 'publisher_unavailable'];
                    }

                    // Adapters vary; expose minimal capability surface.
                    return ['ok' => true, 'publisher' => $publisher::class];
                }

                public function supports(string $target): bool
                {
                    return in_array($target, ['wordpress', 'default'], true);
                }
            }, self::SLUG);
        }
    }
}

final class CapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
