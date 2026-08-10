<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AutoScheduleProjectItemsCommand implements ContentProjectCommand
{
    /**
     * @param  list<int|string>  $itemRefs
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly array $options,
        public readonly bool $dryRun = false,
    ) {}

    public function name(): string
    {
        return 'content_project.auto_schedule';
    }
}
