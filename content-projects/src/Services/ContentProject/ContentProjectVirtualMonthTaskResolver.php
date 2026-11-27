<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Resolves tasks for Domain × planning_month virtual ops (matrix SSOT scope).
 */
final class ContentProjectVirtualMonthTaskResolver
{
    public function __construct(
        private readonly SitePlanningActiveUnitAggregator $units = new SitePlanningActiveUnitAggregator,
    ) {}

    /**
     * @return array{task: SeoProjectTask, project: SeoProject}
     */
    public function resolveOwnedTask(int $siteId, string $planningMonth, int $taskId): array
    {
        abort_unless(SeoAccessControl::canAccessSite($siteId), 403);

        $month = ContentProjectMonthContext::normalize($planningMonth);
        $allowed = $this->allowedTaskIdMap($siteId, $month);
        abort_unless(isset($allowed[$taskId]), 404);

        $task = SeoProjectTask::query()
            ->whereKey($taskId)
            ->where('site_id', $siteId)
            ->with(['project.user', 'project.site'])
            ->first();
        abort_unless($task instanceof SeoProjectTask, 404);

        $project = $task->project;
        abort_unless($project instanceof SeoProject, 404);

        return ['task' => $task, 'project' => $project];
    }

    /**
     * @return array{planned: int, task_ids: list<int>}
     */
    public function scopeForSiteMonth(int $siteId, string $planningMonth): array
    {
        return $this->units->forSiteMonth($siteId, ContentProjectMonthContext::normalize($planningMonth), true);
    }

    /**
     * @return array<int, true>
     */
    public function allowedTaskIdMap(int $siteId, string $planningMonth): array
    {
        $map = [];
        foreach ($this->scopeForSiteMonth($siteId, $planningMonth)['task_ids'] as $id) {
            $tid = (int) $id;
            if ($tid > 0) {
                $map[$tid] = true;
            }
        }

        return $map;
    }
}