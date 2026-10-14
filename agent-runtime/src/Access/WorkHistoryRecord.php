<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Persisted task classification for work history. No inferred events.
 */
final class WorkHistoryRecord
{
    public const PER_PAGE = 20;

    public static function pageNumber(string $message): int
    {
        if (preg_match('/trang\s+(\d+)/u', mb_strtolower($message), $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    public static function enteredWorkflow(?string $status, ?string $type, mixed $articleId): bool
    {
        $status = (string) $status;
        if ($status === SeoProjectTask::STATUS_CANCELLED) {
            return false;
        }
        if ($status !== SeoProjectTask::STATUS_PENDING) {
            return true;
        }

        return $type === SeoProjectTask::TYPE_CREATE && (int) $articleId > 0;
    }

    public static function classification(
        ?string $status,
        ?string $type,
        mixed $articleId,
        bool $taskArchived,
        bool $projectArchived,
        bool $outstanding,
    ): string {
        $status = (string) $status;
        if ($taskArchived || $projectArchived || $status === SeoProjectTask::STATUS_ARCHIVED) {
            return 'archived';
        }
        if ($status === SeoProjectTask::STATUS_CANCELLED) {
            return 'cancelled';
        }
        if ($outstanding) {
            return 'outstanding';
        }
        if ($status === SeoProjectTask::STATUS_COMPLETED) {
            return 'completed';
        }
        if (self::enteredWorkflow($status, $type, $articleId)) {
            return 'in_workflow';
        }

        return 'planned';
    }
}
