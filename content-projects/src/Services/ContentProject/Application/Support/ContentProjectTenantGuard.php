<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use RuntimeException;

/**
 * Tenant / site isolation — bắt buộc ở Application layer.
 */
final class ContentProjectTenantGuard
{
    public function assertCanAccessProject(SeoProject $project, ActorContext $actor): void
    {
        $siteId = (int) ($project->site_id ?? 0);

        // Domain-neutral project (null site_id): Shared Draft OR execution from Publish/Split.
        // Domain lives on items — do not require project.site_id.
        if ($siteId <= 0) {
            if (in_array($actor->actorType, ['user', 'api', 'agent'], true)
                && ! SeoAccessControl::canManageContentProjectWorkflow()
                && ! (
                    SeoAccessControl::isContentManager()
                    && (int) ($project->user_id ?? 0) === (int) (auth()->id() ?? 0)
                )
            ) {
                throw new RuntimeException('Không có quyền truy cập project.');
            }

            return;
        }

        if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
            throw new RuntimeException('Project không thuộc site hiện tại.');
        }

        if (in_array($actor->actorType, ['user', 'api', 'agent'], true)) {
            if (! SeoAccessControl::canAccessSite($siteId)) {
                throw new RuntimeException('Không có quyền truy cập project.');
            }
        }
    }

    /**
     * Distinct positive site_ids owned by live project items (domain-neutral ownership surface).
     * Never treats 0/null as a site id.
     *
     * @return list<int>
     */
    public function resolveProjectItemSiteIds(SeoProject $project): array
    {
        $ids = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNotNull('site_id')
            ->where('site_id', '>', 0)
            ->distinct()
            ->pluck('site_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return array_values($ids);
    }

    /**
     * Domain-neutral fail-closed ownership (same family as ContentProjectArchiveAccessScope):
     * - no site-bound items yet → allow (Shared Draft / empty packing)
     * - empty accessible list while unrestricted → allow
     * - otherwise require intersection with accessible sites
     *
     * @param  list<int>  $accessibleSiteIds
     */
    public function domainNeutralProjectHasAccessibleItemOwnership(
        SeoProject $project,
        array $accessibleSiteIds,
    ): bool {
        $itemSiteIds = $this->resolveProjectItemSiteIds($project);
        if ($itemSiteIds === []) {
            return true;
        }

        $normalized = [];
        foreach ($accessibleSiteIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }
        $normalized = array_values($normalized);

        if ($normalized === []) {
            return ! SeoAccessControl::shouldScopeToAccountOwner();
        }

        return array_intersect($itemSiteIds, $normalized) !== [];
    }

    /**
     * Site ids for queue-health scoping of a single project.
     * Prefer project.site_id when set; otherwise positive item site_ids. Never returns [0].
     *
     * @return list<int>
     */
    public function resolveProjectQueueHealthSiteIds(SeoProject $project): array
    {
        $projectSiteId = (int) ($project->site_id ?? 0);
        if ($projectSiteId > 0) {
            return [$projectSiteId];
        }

        return $this->resolveProjectItemSiteIds($project);
    }

    /**
     * Validate actor may operate against an explicit working Site (separate from Shared Draft access).
     */
    public function assertCanAccessSite(int $siteId, ActorContext $actor): void
    {
        if ($siteId <= 0) {
            throw new RuntimeException('Site is required.');
        }

        if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
            throw new RuntimeException('Site không thuộc ngữ cảnh hiện tại.');
        }

        if (in_array($actor->actorType, ['user', 'api', 'agent'], true)) {
            if (! SeoAccessControl::canAccessSite($siteId)) {
                throw new RuntimeException('Không có quyền truy cập site.');
            }
        }
    }

    public function assertTasksBelongToProject(SeoProject $project, array $taskIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if ($ids === []) {
            return;
        }

        $count = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('id', $ids)
            ->count();

        if ($count !== count($ids)) {
            throw new RuntimeException('Một hoặc nhiều item không thuộc project.');
        }
    }

    public function failForbidden(?int $projectId = null): ContentProjectActionResult
    {
        return ContentProjectActionResult::fail(
            ContentProjectActionCodes::FORBIDDEN,
            'Forbidden.',
            $projectId,
        );
    }
}
