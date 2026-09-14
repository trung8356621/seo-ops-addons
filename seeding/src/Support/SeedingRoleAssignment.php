<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Exclusive assignment of exactly one seeding.* Spatie role per user.
 * Does not touch Core users.role or SEO roles.
 */
final class SeedingRoleAssignment
{
    /** Precedence: manager > topic_creator > seeder */
    public const PRECEDENCE = [
        SeedingAccess::ROLE_MANAGER,
        SeedingAccess::ROLE_TOPIC_CREATOR,
        SeedingAccess::ROLE_SEEDER,
    ];

    public function __construct(
        private readonly AddonPermissionRegistry $registry,
    ) {}

    /**
     * @return list<string>
     */
    public function allRoles(): array
    {
        return self::PRECEDENCE;
    }

    public function normalize(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if (in_array($role, self::PRECEDENCE, true)) {
            return $role;
        }

        return SeedingAccess::ROLE_SEEDER;
    }

    /**
     * Resolve current seeding role for UI. Missing → seeder default.
     * Multiple roles → strongest by precedence.
     */
    public function resolveForUser(User $user): string
    {
        if (! $this->registry->permissionTablesReady()) {
            return SeedingAccess::ROLE_SEEDER;
        }

        try {
            $this->registry->ensureSynced();
            foreach (self::PRECEDENCE as $role) {
                if ($user->hasRole($role)) {
                    return $role;
                }
            }
        } catch (Throwable) {
            // Fall through to default.
        }

        return SeedingAccess::ROLE_SEEDER;
    }

    /**
     * Replace all seeding.* roles with exactly one normalized role.
     */
    public function assignExclusive(User $user, ?string $role): void
    {
        $target = $this->normalize($role);

        if (! $this->registry->permissionTablesReady()) {
            return;
        }

        $this->registry->ensureSynced();

        $current = $user->roles()
            ->whereIn('name', self::PRECEDENCE)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $toRemove = array_values(array_filter(
            $current,
            static fn (string $name): bool => $name !== $target,
        ));

        if ($toRemove !== []) {
            $user->removeRole(...$toRemove);
        }

        if (! $user->hasRole($target)) {
            $user->assignRole($target);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
