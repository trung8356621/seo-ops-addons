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

        $maxComments = (int) ($config->rawConfig['max_comments_per_day'] ?? SeedingTargetCalculator::DEFAULT_MAX_COMMENTS_PER_DAY);
        if ($maxComments <= 0) {
            $maxComments = SeedingTargetCalculator::DEFAULT_MAX_COMMENTS_PER_DAY;
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
                'seo_role' => (string) ($user->seo_role ?? ''),
                'is_manager' => $access->isManager($user),
                'role' => $access->isManager($user) ? 'manager' : 'seeder',
            ] : null,
            'sites' => $sites,
            'settings' => [
                'max_comments_per_day' => $maxComments,
                'website_share_delay_minutes' => 10,
            ],
            'storage' => [
                'mode' => 'hybrid',
                'schema_version' => $config->storageSchemaVersion,
            ],
            'capabilities' => [
                'seeding.workspace',
                'seeding.topic',
                'link.intelligence',
                'seeding.website_share',
            ],
            'permissions' => [
                'is_manager' => $user instanceof User && $access->isManager($user),
                'can_create_topic' => $user instanceof User && $access->canManageTopics($user),
                'can_manage' => $user instanceof User && $access->canManageTopics($user),
            ],
        ];
    }
}
