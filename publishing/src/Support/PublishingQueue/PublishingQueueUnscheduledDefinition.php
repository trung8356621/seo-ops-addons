<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Unscheduled — in Publishing Queue, no schedule time yet.
 * Not a catch-all; unknown statuses go to needs_attention.
 */
final class PublishingQueueUnscheduledDefinition
{
    public const FILTER = 'unscheduled';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row)
            || PublishingQueueFailedDefinition::matches($row)
            || PublishingQueuePublishingDefinition::matches($row)
            || PublishingQueueAwaitingWorkerDefinition::matches($row)
            || PublishingQueueRetryWaitDefinition::matches($row)
            || PublishingQueueScheduledDefinition::matches($row)
            || PublishingQueueNeedsAttentionDefinition::matches($row)
        ) {
            return false;
        }

        $queued = $row['publishing_queued_at'] ?? null;
        $hasQueue = $queued !== null && $queued !== '';
        $at = PublishingQueueScheduledDefinition::scheduledAt($row);
        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        return $hasQueue && $at === null && in_array($queue, ['none', ''], true);
    }
}
