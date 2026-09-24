<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Core\Permissions\AddonAuthorization;
use App\Core\Sites\SiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Addon-neutral Seeding access — Core User + SiteAccess + Service activation + Spatie roles.
 *
 * Seeding Manager = Core owner/admin bypass OR Spatie seeding.manager only.
 * SEO addon roles NEVER grant Seeding Manager.
 */
final class SeedingAccess
{
    public const CAPABILITY_TOPIC = 'seeding.topic';

    public const ROLE_MANAGER = 'seeding.manager';

    public const ROLE_TOPIC_CREATOR = 'seeding.topic_creator';

    public const ROLE_SEEDER = 'seeding.seeder';

    public function __construct(
        private readonly SiteAccess $sites,
        private readonly SeedingServiceResolver $service,
    ) {}

    public function canAccess(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        return $this->service->isActive();
    }

    public function canMutate(?User $user = null): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Seeding Manager — create/edit/pause topics, management table, stats.
     */
    public function isManager(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User || ! $this->canAccess($user)) {
            return false;
        }

        if (in_array((string) ($user->role ?? ''), [User::ROLE_OWNER, User::ROLE_ADMIN], true)) {
            return true;
        }

        try {
            return app(AddonAuthorization::class)
                ->hasAddonRole($user, self::ROLE_MANAGER, ownerBypass: false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Manager-only: pause/resume/cancel, management table, stats.
     */
    public function canManageTopics(?User $user = null): bool
    {
        return $this->isManager($user);
    }

    public function assertCanManage(?User $user = null): void
    {
        abort_unless($this->canManageTopics($user), 403);
    }

    /**
     * Create/share Topics + manage DB link assignments — Manager or Topic Creator.
     * Exact Seeder must never pass this gate.
     */
    public function canCreateTopics(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User || ! $this->canAccess($user)) {
            return false;
        }

        if ($this->isManager($user)) {
            return true;
        }

        try {
            $role = app(SeedingRoleAssignment::class)->resolveForUser($user);

            return $role === self::ROLE_TOPIC_CREATOR;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertCanCreateTopics(?User $user = null): void
    {
        abort_unless($this->canCreateTopics($user), 403);
    }

    /**
     * DB-backed link assignment CRUD — same gate as create topics.
     */
    public function canManageLinkAssignments(?User $user = null): bool
    {
        return $this->canCreateTopics($user);
    }

    public function assertCanManageLinkAssignments(?User $user = null): void
    {
        abort_unless($this->canManageLinkAssignments($user), 403);
    }

    public function canAccessSite(int $siteId, ?User $user = null): bool
    {
        if (! $this->canAccess($user)) {
            return false;
        }

        return $this->sites->canAccessSite($siteId, $user);
    }

    /**
     * @return Builder<Site>
     */
    public function accessibleSitesQuery(?User $user = null): Builder
    {
        return $this->sites->accessibleSitesQuery($user);
    }

    public function assertCanAccessSite(int $siteId, ?User $user = null): void
    {
        abort_unless($this->canAccessSite($siteId, $user), 403);
    }

    public function assertCanAccess(?User $user = null): void
    {
        abort_unless($this->canAccess($user), 403);
    }

    public function assertCanMutate(?User $user = null): void
    {
        abort_unless($this->canMutate($user), 403);
    }
}
