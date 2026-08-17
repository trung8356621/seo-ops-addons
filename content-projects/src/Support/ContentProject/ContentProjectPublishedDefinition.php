<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Canonical Published (workflow) — WordPress publisher success only.
 *
 * Used by: Summary count, list filter, badge hierarchy, counters, recovery.
 * NOT article.status. NOT editor Save/Sync.
 */
final class ContentProjectPublishedDefinition
{
    public const FILTER = 'published';

    /**
     * @param  array{
     *     lifecycle?: string|null,
     *     queue_status?: string|null,
     *     publish_queue_status?: string|null,
     *     publish_published_at?: string|null,
     *     has_published_revision?: bool,
     *     observed_post_status?: string|null,
     *     publishing_queued_at?: string|null,
     *     in_publishing_queue?: bool,
     * }  $row
     */
    public static function matches(array $row): bool
    {
        if (! empty($row['has_published_revision'])) {
            return true;
        }

        $lifecycle = strtolower(trim((string) ($row['lifecycle'] ?? '')));
        if ($lifecycle === 'published') {
            return true;
        }

        return ContentProjectPublishedEvidence::fromRow($row);
    }
}
