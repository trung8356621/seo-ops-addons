<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Safe bulk repair: update existing WP posts for Published queue items only.
 */
final class BulkResyncPublishedArticlesToWordPressCommand implements ContentProjectCommand
{
    /** @param list<int|string> $itemRefs */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly string $initiatedFrom = 'publishing_queue.bulk_resync',
    ) {}

    public function name(): string
    {
        return 'publishing.bulk_resync_published_articles_to_wordpress';
    }
}
