<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use App\Core\Sites\SiteAccess;
use App\Models\User;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

final class AgentActorAccessResolver
{
    public function forUserId(int $userId): AgentActorAccessContext
    {
        $user = $userId > 0 ? User::query()->find($userId) : null;
        if (! $user instanceof User) {
            return new AgentActorAccessContext($userId, SeoAccessControl::ROLE_CONTENT_MANAGER, [], [], false, false);
        }

        $role = SeoAccessControl::roleForUser($user);
        if ($role === SeoAccessControl::ROLE_CONTENT_MANAGER) {
            $ownerId = (int) ($user->accountOwnerId() ?? 0);

            return new AgentActorAccessContext(
                $userId,
                $role,
                $this->assignedProjectIds($userId),
                $ownerId > 0 ? $this->siteIdsFor($user) : [],
                $ownerId > 0,
                false,
            );
        }

        try {
            $siteIds = SeoAccessControl::accessibleSiteIds();
        } catch (Throwable) {
            $siteIds = [];
        }

        return new AgentActorAccessContext(
            $userId,
            $role,
            $this->projectsForSites($siteIds, $role === SeoAccessControl::ROLE_MANAGER),
            $siteIds,
            $role === SeoAccessControl::ROLE_PLANNER || $role === SeoAccessControl::ROLE_MANAGER,
            $role === SeoAccessControl::ROLE_MANAGER,
        );
    }

    /**
     * @return list<int>
     */
    private function assignedProjectIds(int $userId): array
    {
        return SeoProject::query()
            ->whereNull('archived_at')
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function projectsForSites(array $siteIds, bool $includeArchived): array
    {
        if ($siteIds === []) {
            return [];
        }

        $query = SeoProject::query()->where(function ($inner) use ($siteIds): void {
            $inner->whereIn('site_id', $siteIds)->orWhereNull('site_id');
        });
        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function siteIdsFor(User $user): array
    {
        try {
            return app(SiteAccess::class)->accessibleSiteIds($user);
        } catch (Throwable) {
            return [];
        }
    }
}
