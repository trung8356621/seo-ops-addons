<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Global comment target → per-user requirement (CEIL, never round()).
 */
final class SeedingTargetCalculator
{
    public const DEFAULT_MAX_COMMENTS_PER_DAY = 20;

    /**
     * @return array{
     *     max_comments_target: int,
     *     member_count_at_share: int,
     *     required_comments_per_user: int
     * }
     */
    public function snapshot(int $maxCommentsPerDay, int $activeMemberCount): array
    {
        $max = max(1, $maxCommentsPerDay);
        $members = max(1, $activeMemberCount);
        $required = (int) ceil($max / $members);

        return [
            'max_comments_target' => $max,
            'member_count_at_share' => $members,
            'required_comments_per_user' => max(1, $required),
        ];
    }

    public function requiredPerUser(int $maxCommentsPerDay, int $activeMemberCount): int
    {
        return $this->snapshot($maxCommentsPerDay, $activeMemberCount)['required_comments_per_user'];
    }
}
