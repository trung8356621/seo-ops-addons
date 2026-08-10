<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedDefinition;

/**
 * Publishing Queue "Published" bucket — wraps the canonical Content Project
 * definition so both modules agree on a single source of truth.
 */
final class PublishingQueuePublishedDefinition
{
    public const FILTER = 'published';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        return ContentProjectPublishedDefinition::matches($row);
    }
}
