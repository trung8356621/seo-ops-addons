<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Service-level health (activation + DB plane). No business table checks.
 */
final class SeedingServiceHealth
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
        private readonly SeedingDatabaseHealth $database,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $config = $this->resolver->resolve();
        $db = $this->database->check();

        return [
            'ok' => $config->active && ($db['reachable'] === true || app()->environment('testing')),
            'service' => SeedingServiceResolver::SLUG,
            'active' => $config->active,
            'version' => $config->version,
            'persistence' => $config->persistence,
            'database' => [
                'connection' => $db['connection'],
                'database' => $db['database'],
                'configured' => $db['configured'],
                'reachable' => $db['reachable'],
            ],
        ];
    }

    /**
     * Bootstrap payload for the React workspace (no topic business state, no secrets).
     *
     * @return array<string, mixed>
     */
    public function bootstrap(?User $user = null): array
    {
        $user ??= Auth::user();
        $config = $this->resolver->resolve();
        $access = app(SeedingAccess::class);

        $sites = [];
        if ($user instanceof User && $access->canAccess($user)) {
            foreach ($access->accessibleSitesQuery($user)->orderBy('domain')->get() as $site) {
                $sites[] = [
                    'id' => (int) $site->id,
                    'domain' => (string) $site->domain,
                    'name' => (string) ($site->domain ?? ''),
                ];
            }
        }

        return [
            'service' => [
                'slug' => SeedingServiceResolver::SLUG,
                'active' => $config->active,
                'version' => $config->version,
            ],
            'client' => [
                'installation_id' => $this->resolver->installationNamespace(),
            ],
            'user' => $user instanceof User ? [
                'id' => (int) $user->id,
                'display_name' => (string) ($user->name ?? ''),
            ] : null,
            'sites' => $sites,
            'storage' => [
                'mode' => $config->persistence,
                'schema_version' => $config->storageSchemaVersion,
            ],
            'capabilities' => [
                'seeding.workspace',
                'seeding.topic',
                'link.intelligence',
            ],
        ];
    }
}
