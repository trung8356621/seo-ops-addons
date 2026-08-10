<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Canonical Scheduled (workflow) — waiting publish / due queue, not Published.
 *
 * Used by: Summary count, list filter, badge hierarchy, counters.
 */
final class ContentProjectScheduledDefinition
{
    public const FILTER = 'waiting_publish';

    /**
     * @param  array{
     *     lifecycle?: string|null,
     *     queue_status?: string|null,
     *     is_scheduled?: bool,
     *     scheduled_raw?: string|null,
     *     publish_published_at?: string|null,
     *     has_published_revision?: bool,
     * }  $row
     */
    public static function matches(array $row): bool
    {
        if (ContentProjectPublishedDefinition::matches($row)) {
            return false;
        }

        $lifecycle = strtolower(trim((string) ($row['lifecycle'] ?? '')));
        if ($lifecycle === 'waiting_publish') {
            return true;
        }

        $queue = strtolower(trim((string) ($row['queue_status'] ?? '')));
        if (in_array($queue, ['waiting', 'processing', 'retrying'], true)) {
            return true;
        }

        if (! empty($row['is_scheduled'])) {
            return true;
        }

        return trim((string) ($row['scheduled_raw'] ?? '')) !== '';
    }
}
