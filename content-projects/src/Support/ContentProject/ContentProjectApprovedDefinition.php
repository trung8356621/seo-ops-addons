<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Canonical Approved (workflow) — Planner confirmation marker.
 * Not a Schedule gate. Mutually exclusive vs Scheduled/Published for summary.
 */
final class ContentProjectApprovedDefinition
{
    public const FILTER = 'approved';

    /**
     * @param  array{
     *     lifecycle?: string|null,
     *     review_status?: string|null,
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
        if (ContentProjectScheduledDefinition::matches($row)) {
            return false;
        }

        $lifecycle = strtolower(trim((string) ($row['lifecycle'] ?? '')));
        if ($lifecycle === 'approved') {
            return true;
        }

        return strtolower(trim((string) ($row['review_status'] ?? ''))) === 'approved';
    }
}
