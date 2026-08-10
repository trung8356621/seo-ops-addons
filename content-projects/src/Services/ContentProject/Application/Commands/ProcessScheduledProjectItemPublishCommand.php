<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/** Internal — queue runner dispatch scheduled publish. */
final class ProcessScheduledProjectItemPublishCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int|string $itemRef,
        public readonly int|string|null $projectRef = null,
        public readonly ?string $attemptRef = null,
    ) {}

    public function name(): string
    {
        return 'content_project.process_scheduled_publish';
    }
}
