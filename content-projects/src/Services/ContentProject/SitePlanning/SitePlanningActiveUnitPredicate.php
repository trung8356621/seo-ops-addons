<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Database\Query\Builder;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Shared predicate: which project tasks still consume Site Planning ACTIVE units.
 *
 * Matrix cells, Month Detail totals, and plannedCountsBySite MUST use this together
 * with {@see SitePlanningActiveUnitAggregator} loaders — never fork lifecycle rules.
 *
 * Terminal / historical (excluded from ACTIVE):
 * - task archived / deleted / cancelled (also applied by loaders)
 * - project archived
 * - task status completed|archived (generation finished or vaulted)
 * - WordPress publish success ({@see publish_published_at})
 * - project status completed
 *
 * Draft planning rows remain active while still on Shared Draft (non-terminal).
 */
final class SitePlanningActiveUnitPredicate
{
    /**
     * Apply terminal-exclusion constraints on a tasks+projects query (aliases t / p).
     */
    public static function constrainQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('t.archived_at')
            ->whereNull('t.deleted_at')
            ->whereNull('p.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->where('t.status', '!=', SeoProjectTask::STATUS_COMPLETED)
            ->where('t.status', '!=', SeoProjectTask::STATUS_ARCHIVED)
            ->where('p.status', '!=', SeoProject::STATUS_COMPLETED)
            ->whereNull('t.publish_published_at');
    }

    /**
     * Row-level check for collections already loaded (legacy backfill filter path).
     *
     * @param  object{
     *     status?: mixed,
     *     archived_at?: mixed,
     *     deleted_at?: mixed,
     *     publish_published_at?: mixed,
     *     project_status?: mixed,
     *     project_archived_at?: mixed
     * }  $row
     */
    public static function acceptsRow(object $row): bool
    {
        if (($row->archived_at ?? null) !== null) {
            return false;
        }
        if (($row->deleted_at ?? null) !== null) {
            return false;
        }
        if (($row->project_archived_at ?? null) !== null) {
            return false;
        }
        if (($row->publish_published_at ?? null) !== null) {
            return false;
        }

        $taskStatus = strtolower(trim((string) ($row->status ?? '')));
        if (in_array($taskStatus, [
            SeoProjectTask::STATUS_CANCELLED,
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_ARCHIVED,
        ], true)) {
            return false;
        }

        $projectStatus = strtolower(trim((string) ($row->project_status ?? '')));
        if ($projectStatus === SeoProject::STATUS_COMPLETED) {
            return false;
        }

        return true;
    }
}
