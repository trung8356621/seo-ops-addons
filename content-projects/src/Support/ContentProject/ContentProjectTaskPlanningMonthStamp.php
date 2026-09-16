<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;

/**
 * Stamp seo_project_tasks.planning_month using PlanningMonthBackfill SSOT.
 * No-op when the column is missing (pre-migration environments).
 */
final class ContentProjectTaskPlanningMonthStamp
{
    /**
     * @param  array<string, mixed>  $taskAttrs  Mutable create/update attributes
     * @return array<string, mixed>
     */
    public static function applyToAttrs(array $taskAttrs, ?SeoProject $project = null, ?string $preferredMonth = null): array
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            return $taskAttrs;
        }

        if (ContentProjectMonthContext::parseOrNull($taskAttrs['planning_month'] ?? null) !== null) {
            return $taskAttrs;
        }

        $isDraft = $project instanceof SeoProject && $project->isDraftPlanning();
        $month = PlanningMonthBackfill::resolve([
            'planning_month' => $preferredMonth,
            'created_at' => $taskAttrs['created_at'] ?? now(),
            'target_date' => $taskAttrs['target_date'] ?? null,
            'project_month' => $project?->month,
            'project_is_draft' => $isDraft,
        ]);

        $taskAttrs['planning_month'] = ContentProjectMonthContext::toDateString($month);

        return $taskAttrs;
    }

    public static function stampTask(SeoProjectTask $task, ?SeoProject $project = null, ?string $preferredMonth = null): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            return;
        }
        if (ContentProjectMonthContext::parseOrNull($task->planning_month ?? null) !== null) {
            return;
        }

        $attrs = self::applyToAttrs(
            [
                'created_at' => $task->created_at,
                'target_date' => $task->target_date,
                'planning_month' => null,
            ],
            $project,
            $preferredMonth,
        );

        if (isset($attrs['planning_month'])) {
            $task->forceFill(['planning_month' => $attrs['planning_month']])->save();
        }
    }
}
