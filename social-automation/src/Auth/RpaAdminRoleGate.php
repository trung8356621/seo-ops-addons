<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Auth;

use App\Models\User;

/**
 * RPA Admin may only use SEO-OPS account roles admin|owner. Staff is rejected.
 * Server-side gate — never trust a role claim from Electron.
 */
final class RpaAdminRoleGate
{
    /** @var list<string> */
    public const ALLOWED_ROLES = [User::ROLE_ADMIN, User::ROLE_OWNER];

    public function allows(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        if ($user->isSystemUser()) {
            return false;
        }

        return in_array((string) $user->role, self::ALLOWED_ROLES, true);
    }

    public function denyReason(?User $user): string
    {
        if (! $user instanceof User) {
            return 'unauthenticated';
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return 'user_blocked';
        }

        if ($user->isSystemUser()) {
            return 'system_user_forbidden';
        }

        if ((string) $user->role === User::ROLE_STAFF) {
            return 'role_staff_forbidden';
        }

        if (! in_array((string) $user->role, self::ALLOWED_ROLES, true)) {
            return 'role_forbidden';
        }

        return 'forbidden';
    }
}
