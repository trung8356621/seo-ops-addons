<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ResumeProjectExecutionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string|int|null $executionRef = null,
    ) {}

    public function name(): string
    {
        return 'content_project.resume_execution';
    }
}
