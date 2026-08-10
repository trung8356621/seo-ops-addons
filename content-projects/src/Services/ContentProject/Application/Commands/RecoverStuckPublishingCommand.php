<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Carbon\Carbon;

final class RecoverStuckPublishingCommand implements ContentProjectCommand
{
    /**
     * @param  list<int|string>  $itemRefs
     * @param  'scheduled'|'unscheduled'|'failed'  $target
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly string $target,
        public readonly ?Carbon $rescheduleAt = null,
        public readonly bool $dryRun = false,
        public readonly bool $force = false,
    ) {}

    public function name(): string
    {
        return 'content_project.recover_stuck_publishing';
    }
}
