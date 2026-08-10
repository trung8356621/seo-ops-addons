<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

final class ContentProjectGenerationRequested
{
    public function __construct(
        public readonly int $projectId,
        public readonly string $executionRef,
        /** @var list<int> */
        public readonly array $itemIds = [],
    ) {}
}
