<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;

/**
 * Global Social Automation access — Service activation only (website-agnostic).
 */
final class SocialAutomationAccess
{
    public function __construct(
        private readonly SocialAutomationServiceResolver $service,
        private readonly RpaAdminRoleGate $roles,
    ) {}

    public function canAccess(?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        if (! $this->roles->allows($user)) {
            return false;
        }

        return $this->service->isActive();
    }

    public function canMutate(?User $user = null): bool
    {
        return $this->canAccess($user);
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
