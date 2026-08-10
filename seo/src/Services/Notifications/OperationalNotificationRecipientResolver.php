<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Canonical recipient resolver — tenant isolation, active users, role gates.
 */
final class OperationalNotificationRecipientResolver
{
    /**
     * Prompt/config/system error → manager + planner under tenant.
     *
     * @return Collection<int, User>
     */
    public function forPromptOrSystemError(int $tenantOwnerId): Collection
    {
        return $this->activeTenantUsers($tenantOwnerId, [
            User::SEO_ROLE_MANAGER,
            User::SEO_ROLE_PLANNER,
        ]);
    }

    /**
     * Generation batch → initiator + managers/planners of project tenant.
     *
     * @return Collection<int, User>
     */
    public function forGenerationBatch(SeoProject $project, ?int $initiatorUserId = null): Collection
    {
        $tenantOwnerId = $this->tenantOwnerIdForProject($project);
        $recipients = $this->forPromptOrSystemError($tenantOwnerId);

        if ($initiatorUserId !== null && $initiatorUserId > 0) {
            $initiator = User::query()->find($initiatorUserId);
            if ($initiator instanceof User && $this->isEligible($initiator, $tenantOwnerId)) {
                $recipients->push($initiator);
            }
        }

        return $this->uniqueUsers($recipients);
    }

    /**
     * Review assignment → assigned reviewer only.
     *
     * @return Collection<int, User>
     */
    public function forReviewAssignment(int $reviewerId, int $tenantOwnerId): Collection
    {
        $reviewer = User::query()->find($reviewerId);
        if (! $reviewer instanceof User || ! $this->isEligible($reviewer, $tenantOwnerId)) {
            return collect();
        }

        return collect([$reviewer]);
    }

    /**
     * Runner health → manager/admin/operations (manager + planner).
     *
     * @return Collection<int, User>
     */
    public function forRunnerHealth(int $tenantOwnerId): Collection
    {
        return $this->activeTenantUsers($tenantOwnerId, [
            User::SEO_ROLE_MANAGER,
            User::SEO_ROLE_PLANNER,
        ]);
    }

    /**
     * WordPress connection → manager/planner (+ optional domain managers = same tenant set).
     *
     * @return Collection<int, User>
     */
    public function forWordPressConnection(int $tenantOwnerId): Collection
    {
        return $this->forPromptOrSystemError($tenantOwnerId);
    }

    /**
     * Site Sync → initiator + managers/domain owners (tenant managers/planners).
     *
     * @return Collection<int, User>
     */
    public function forSiteSync(int $tenantOwnerId, ?int $initiatorUserId = null): Collection
    {
        $recipients = $this->forPromptOrSystemError($tenantOwnerId);

        if ($initiatorUserId !== null && $initiatorUserId > 0) {
            $initiator = User::query()->find($initiatorUserId);
            if ($initiator instanceof User && $this->isEligible($initiator, $tenantOwnerId)) {
                $recipients->push($initiator);
            }
        }

        return $this->uniqueUsers($recipients);
    }

    /**
     * Publishing — same tenant manager/planner + optional initiator (Publishing patch reuses).
     *
     * @return Collection<int, User>
     */
    public function forPublishing(int $tenantOwnerId, ?int $initiatorUserId = null): Collection
    {
        return $this->forSiteSync($tenantOwnerId, $initiatorUserId);
    }

    /**
     * Content Manager(s) for a project (Needs Review assignees).
     *
     * @return Collection<int, User>
     */
    public function forProjectContentManagers(SeoProject $project): Collection
    {
        $tenantOwnerId = $this->tenantOwnerIdForProject($project);
        $ownerId = (int) ($project->user_id ?? 0);
        if ($ownerId <= 0) {
            return $this->activeTenantUsers($tenantOwnerId, [User::SEO_ROLE_CONTENT_MANAGER]);
        }

        $owner = User::query()->find($ownerId);
        if ($owner instanceof User && $this->isEligible($owner, $tenantOwnerId)) {
            return collect([$owner]);
        }

        return collect();
    }

    public function tenantOwnerIdForProject(SeoProject $project): int
    {
        $ownerId = (int) ($project->user_id ?? 0);
        if ($ownerId <= 0) {
            return 0;
        }

        $owner = User::query()->find($ownerId);
        if (! $owner instanceof User) {
            return $ownerId;
        }

        return (int) ($owner->accountOwnerId() ?? $owner->id);
    }

    public function tenantOwnerIdForUser(User $user): int
    {
        return (int) ($user->accountOwnerId() ?? $user->id);
    }

    /**
     * @param  list<string>  $seoRoles
     * @return Collection<int, User>
     */
    private function activeTenantUsers(int $tenantOwnerId, array $seoRoles): Collection
    {
        if ($tenantOwnerId <= 0 || $seoRoles === []) {
            return collect();
        }

        return User::query()
            ->where('status', User::STATUS_NORMAL)
            ->whereIn('seo_role', $seoRoles)
            ->where(function ($query) use ($tenantOwnerId): void {
                $query->whereKey($tenantOwnerId)
                    ->orWhere('parent_id', $tenantOwnerId);
            })
            ->get()
            ->filter(fn (User $user): bool => $this->isEligible($user, $tenantOwnerId))
            ->values();
    }

    private function isEligible(User $user, int $tenantOwnerId): bool
    {
        if ((string) $user->status !== User::STATUS_NORMAL) {
            return false;
        }

        if (! SeoAccessControl::canAccessSeoPanel($user)) {
            return false;
        }

        $userTenant = $this->tenantOwnerIdForUser($user);

        return $tenantOwnerId <= 0 || $userTenant === $tenantOwnerId;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function uniqueUsers(Collection $users): Collection
    {
        return $users
            ->filter(static fn (mixed $user): bool => $user instanceof User)
            ->unique(static fn (User $user): int => (int) $user->id)
            ->values();
    }
}
