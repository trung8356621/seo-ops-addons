<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Deterministic planning_month for legacy tasks missing the stamp.
 * Must not drift when the page is reopened (never use "today" as backfill).
 */
final class PlanningMonthBackfill
{
    /**
     * @param  array{
     *   planning_month?: mixed,
     *   created_at?: mixed,
     *   target_date?: mixed,
     *   project_month?: mixed,
     *   project_is_draft?: bool,
     * }  $row
     */
    public static function resolve(array $row): string
    {
        $existing = ContentProjectMonthContext::parseOrNull($row['planning_month'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        $isDraft = (bool) ($row['project_is_draft'] ?? false);
        if (! $isDraft) {
            $fromProject = ContentProjectMonthContext::parseOrNull($row['project_month'] ?? null);
            if ($fromProject !== null) {
                return $fromProject;
            }
        }

        // Draft: created_at before target_date (target_date is KPI day, not planning month SSOT).
        $fromCreated = ContentProjectMonthContext::parseOrNull($row['created_at'] ?? null);
        if ($fromCreated !== null) {
            return $fromCreated;
        }

        $fromTarget = ContentProjectMonthContext::parseOrNull($row['target_date'] ?? null);
        if ($fromTarget !== null) {
            return $fromTarget;
        }

        // Last resort: still deterministic relative to epoch, not wall-clock "now".
        return '1970-01';
    }
}
