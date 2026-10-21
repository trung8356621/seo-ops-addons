<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Global Social Automation access — Service activation only (website-agnostic).
 */
final class SocialAutomationAccess
{
    public const ROLE_MANAGER = 'social-automation.manager';

    public function __construct(
        private readonly SocialAutomationServiceResolver $service,
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

    public function assertCanAccess(?User $user = null): void
    {
        abort_unless($this->canAccess($user), 403);
    }

    public function assertCanMutate(?User $user = null): void
    {
        abort_unless($this->canMutate($user), 403);
    }
}
