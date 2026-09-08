<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Models\User;

/**
 * Author/manage rules for Seeding topic & comment mutations.
 * Fine-grained RBAC deferred — canMutate ≈ manage permission.
 */
final class SeedingTopicAuthorization
{
    public function __construct(
        private readonly SeedingAccess $access,
    ) {}

    public function canEditTopic(?User $user, ?int $createdByUserId): bool
    {
        if (! $this->access->canMutate($user) || $user === null) {
            return false;
        }
        if ($createdByUserId === null) {
            return false;
        }

        return (int) $user->id === $createdByUserId;
    }

    public function canDeleteTopic(?User $user, ?int $createdByUserId): bool
    {
        if (! $this->access->canMutate($user) || $user === null) {
            return false;
        }
        if ($createdByUserId !== null && (int) $user->id === $createdByUserId) {
            return true;
        }

        // Manage = mutate until fine-grained RBAC lands.
        return true;
    }

    public function canEditComment(?User $user, ?int $authorUserId): bool
    {
        if (! $this->access->canMutate($user) || $user === null) {
            return false;
        }
        if ($authorUserId === null) {
            return false;
        }

        return (int) $user->id === $authorUserId;
    }

    public function canDeleteComment(?User $user, ?int $authorUserId): bool
    {
        if (! $this->access->canMutate($user) || $user === null) {
            return false;
        }
        if ($authorUserId !== null && (int) $user->id === $authorUserId) {
            return true;
        }

        return true;
    }
}
