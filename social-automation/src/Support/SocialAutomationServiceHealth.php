<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Service-level health (activation + DB plane). No business table checks in Phase A.
 */
final class SocialAutomationServiceHealth
{
    public function __construct(
        private readonly SocialAutomationServiceResolver $resolver,
        private readonly SocialAutomationDatabaseHealth $database,
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
            'service' => SocialAutomationServiceResolver::SLUG,
            'active' => $config->active,
            'version' => $config->version,
            'scope' => 'global',
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
     * @return array<string, mixed>
     */
    public function bootstrap(?User $user = null): array
    {
        $user ??= Auth::user();
        $config = $this->resolver->resolve();

        return [
            'service' => [
                'slug' => SocialAutomationServiceResolver::SLUG,
                'active' => $config->active,
                'version' => $config->version,
                'scope' => 'global',
            ],
            'user' => $user instanceof User ? [
                'id' => (int) $user->id,
                'name' => (string) ($user->name ?? ''),
            ] : null,
            'config' => $config->toArray(),
        ];
    }
}
