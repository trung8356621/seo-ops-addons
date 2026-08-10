<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events;

final class ContentProjectRestored
{
    public function __construct(
        public readonly int $projectId,
        public readonly ?int $actorId,
    ) {}
}
