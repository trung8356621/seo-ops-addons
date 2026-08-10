<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class StopProjectExecutionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string|int|null $executionRef = null,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'content_project.stop_execution';
    }
}
