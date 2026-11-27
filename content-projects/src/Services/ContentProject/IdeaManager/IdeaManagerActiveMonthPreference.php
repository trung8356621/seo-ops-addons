<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use App\Models\User;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * User preference for Idea Manager Active Month (YYYY-MM).
 * Does not mutate Content Project Item planning_month.
 */
final class IdeaManagerActiveMonthPreference
{
    public const META_KEY = 'idea_manager_active_month';

    public static function get(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return ContentProjectMonthContext::current();
        }

        return ContentProjectMonthContext::normalize($user->getMeta(self::META_KEY));
    }

    public static function set(string $month, ?User $user = null): string
    {
        $normalized = ContentProjectMonthContext::normalize($month);
        $user ??= auth()->user();
        if ($user instanceof User) {
            $user->setMeta(self::META_KEY, $normalized);
        }

        return $normalized;
    }

    /**
     * Past / present / future options for the Active Month select.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(int $past = 12, int $future = 6): array
    {
        return ContentProjectMonthContext::selectOptions($past, $future);
    }
}
