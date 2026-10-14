<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Outstanding work has left the ungenerated plan state.
 * Pending create without a result article, and pending rewrite/improve whose article_id is only the source, stay out.
 * Writing, failed, processing, draft and reviewing count for both create and rewrite.
 * Completed, archived and cancelled stay out.
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

        if ($status === SeoProjectTask::STATUS_PENDING && ($type !== SeoProjectTask::TYPE_CREATE || (int) $articleId <= 0)) {
            return false;
        }

        return true;
    }
}
