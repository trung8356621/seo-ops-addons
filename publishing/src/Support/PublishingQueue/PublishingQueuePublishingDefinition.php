<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Publishing Queue "Publishing" bucket — publisher worker đã start thật.
 *
 * Chỉ `processing` + publisher_started_at. queued_for_delivery / dispatch-only ≠ Publishing.
 */
final class PublishingQueuePublishingDefinition
{
    public const FILTER = 'publishing';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (PublishingQueuePublishedDefinition::matches($row) || PublishingQueueFailedDefinition::matches($row)) {
            return false;
        }

        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));
        if ($queue !== 'processing') {
            return false;
        }

        // Require publisher_started_at evidence. Missing key = not actively publishing.
        if (! array_key_exists('publisher_started_at', $row)) {
            return false;
        }

        $started = $row['publisher_started_at'];

        return $started !== null && $started !== '';
    }
}
