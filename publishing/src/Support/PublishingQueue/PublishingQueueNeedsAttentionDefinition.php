<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

/**
 * Needs attention — queue member that cannot be projected to a known state.
 * Never silently omit these from counters.
 */
final class PublishingQueueNeedsAttentionDefinition
{
    public const FILTER = 'needs_attention';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        // Only used as final classifier fallback — callers should check known states first.
        $queue = strtolower(trim((string) ($row['publish_queue_status'] ?? $row['queue_status'] ?? '')));

        return in_array($queue, [
            'cancelled',
            'skipped',
            'unknown',
        ], true)
            || ($queue !== '' && ! in_array($queue, [
                'none',
                'waiting',
                'retrying',
                'processing',
                'queued_for_delivery',
                'published',
                'failed',
                '',
            ], true));
    }
}
