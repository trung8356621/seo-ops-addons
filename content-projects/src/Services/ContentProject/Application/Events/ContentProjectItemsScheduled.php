<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

final class ContentProjectItemsScheduled
{
    public function __construct(
        public readonly int $projectId,
        /** @var list<int> */
        public readonly array $itemIds,
        public readonly string $scheduledAt,
    ) {}
}
