<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Carbon\Carbon;

final class MoveProjectItemScheduleCommand implements ContentProjectCommand
{
    /** @param list<int|string> $itemRefs */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly Carbon $scheduledAt,
    ) {}

    public function name(): string
    {
        return 'content_project.move_schedule';
    }
}
