<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPendingOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedDefinition;

/**
 * Send to Publishing Queue — content complete, not in queue, not blocked by active generation.
 * Published is not terminal: Published + unpublished_changes may be queued again.
 * Needs Review / In Review reporting are equivalent (not required).
 * Approved not required.
 */
final class PublishingQueueHandoffEligibility
{
    /**
     * @param  array<string, mixed>  $row
     */
    public static function canSend(array $row): bool
    {
        if (! empty($row['publishing_queued_at']) || ! empty($row['in_publishing_queue'])) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['queue_status'] ?? $row['publish_queue_status'] ?? 'none')));
        if (in_array($queue, ['processing', 'queued_for_delivery'], true)) {
            return false;
        }

        if (ContentProjectPublishedDefinition::matches($row) && empty($row['has_unpublished_changes'])) {
            return false;
        }
        if (ContentProjectPendingOpsDefinition::matches($row) || ! empty($row['is_genuinely_running'])) {
            return false;
        }
        if (ContentProjectFailedOpsDefinition::matches($row)) {
            return false;
        }

        $articleId = (int) ($row['article_id'] ?? 0);
        if ($articleId <= 0) {
            return false;
        }

        $gs = strtolower(trim((string) ($row['generation_status'] ?? '')));
        $exec = strtolower(trim((string) ($row['execution_status'] ?? '')));
        $completedAt = trim((string) ($row['generation_completed_at'] ?? ''));

        $contentReady = $completedAt !== ''
            || in_array($gs, ['completed', 'reviewing'], true)
            || ($exec === 'success' || $exec === 'completed');

        return $contentReady;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function needsContentManagerWarning(array $row): bool
    {
        if (! self::canSend($row)) {
            return false;
        }

        return empty($row['is_content_manager_reviewed'])
            && empty($row['content_manager_reviewed_at']);
    }
}
