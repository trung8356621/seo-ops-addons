<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * LEGACY / REFERENCE-ONLY peer provider.
 *
 * Agent Workspace product runtime is isolated: this provider is skipped from
 * discovery by default (`addons.skip_slugs` includes `agent`). Source under
 * this addon remains for behavioral reference for a future Agent rewrite.
 *
 * Do not re-enable production registration without an explicit rewrite plan.
 */
final class AgentServiceProvider extends ServiceProvider
{
    public const SLUG = 'agent';

    public function register(): void
    {
        // Intentionally minimal — Agent Workspace must not own active business execution.
        // Capability markers only when this provider is explicitly loaded (non-default).
        $this->registerCapabilities();
    }

    public function boot(): void
    {
        // No routes, schedules, Filament, or listeners — reference-only.
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
