<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

final class ContentProjectArchived
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $archiveId,
        public readonly ?int $actorId,
    ) {}
}
