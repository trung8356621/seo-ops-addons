<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Models\ApiConnection;
use App\Models\User;

final class SeoToolsAccessControl
{
    public static function canUseTranslateTool(?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ((string) ($user->status ?? '') === User::STATUS_BLOCK) {
            return false;
        }

        $ownerId = self::apiConnectionOwnerId($user);
        if ($ownerId === null || $ownerId <= 0) {
            return false;
        }

        return ApiConnection::query()
            ->where('user_id', $ownerId)
            ->exists();
    }

    public static function apiConnectionOwnerId(User $user): ?int
    {
        if ($user->isStaff() && (int) $user->parent_id > 0) {
            return (int) $user->parent_id;
        }

        return (int) $user->id;
    }
}
