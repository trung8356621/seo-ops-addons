<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Core\Sites\SiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Addon-neutral Seeding access — Core User + SiteAccess + Service activation.
 *
 * Fine-grained Seeding RBAC is deferred.
 */
final class SeedingAccess
{
    public const CAPABILITY_TOPIC = 'seeding.topic';

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
