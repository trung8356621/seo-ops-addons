<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Awaiting delivery — dispatch claimed/emitted, publisher_started_at null.
 * Must not show as Publishing. Must appear in counters/filters.
 */
final class PublishingQueueAwaitingWorkerDefinition
{
    public const FILTER = 'awaiting_delivery';

    /** @deprecated use FILTER */
    public const FILTER_LEGACY = 'awaiting_worker';

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
        if ($queue === 'queued_for_delivery') {
            return true;
        }

        // Legacy / false-active: processing without publisher start evidence.
        if ($queue === 'processing') {
            if (! array_key_exists('publisher_started_at', $row)) {
                // Missing key on row projection — treat as awaiting when no lease-active publisher stamp.
                $legacyStarted = $row['publishing_started_at'] ?? null;

                return $legacyStarted === null || $legacyStarted === '';
            }

            $started = $row['publisher_started_at'];

            return $started === null || $started === '';
        }

        return false;
    }
}
