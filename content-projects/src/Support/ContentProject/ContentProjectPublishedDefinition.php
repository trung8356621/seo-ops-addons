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
     *     publish_published_at?: string|null,
     *     has_published_revision?: bool,
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

        $queue = strtolower(trim((string) ($row['queue_status'] ?? '')));
        if ($queue === 'published') {
            return true;
        }

        return trim((string) ($row['publish_published_at'] ?? '')) !== '';
    }
}
