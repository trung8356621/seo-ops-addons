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
        if ($siteId <= 0) {
            throw new RuntimeException('Project thiếu site_id.');
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
