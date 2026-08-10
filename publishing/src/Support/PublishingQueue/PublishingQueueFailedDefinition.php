<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Publishing Queue "Failed" bucket — publish_queue_status=failed, not Published.
 */
final class PublishingQueueFailedDefinition
{
    public const FILTER = 'failed';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row)) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        return $queue === 'failed';
    }
}
