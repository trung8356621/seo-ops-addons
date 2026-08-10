<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * retry_wait presentation — publish_queue_status=retrying + next retry scheduled.
 * Must not show as Publishing.
 */
final class PublishingQueueRetryWaitDefinition
{
    public const FILTER = 'retry_wait';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row)
            || PublishingQueueFailedDefinition::matches($row)
            || PublishingQueuePublishingDefinition::matches($row)
        ) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        return $queue === 'retrying';
    }
}
