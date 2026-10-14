<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Outstanding work is generated work already in the Content Manager workflow.
 * Ungenerated plans stay out of the count. Completed, archived and cancelled tasks stay out.
 */
final class WorkStatsTaskLedger
{
    public static function isOutstanding(?string $status, ?string $type, mixed $articleId): bool
    {
        $status = (string) $status;
        if (in_array($status, [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_ARCHIVED,
            SeoProjectTask::STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        if ($status === SeoProjectTask::STATUS_REVIEWING) {
            return true;
        }

        return $type === SeoProjectTask::TYPE_CREATE && (int) $articleId > 0;
    }
}
