<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ArchiveContentProjectCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly ?string $note = null,
        public readonly bool $confirmWaitingPublish = false,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
        public readonly bool $confirmHiddenStaleRuns = false,
    ) {}

    public function name(): string
    {
        return 'content_project.archive';
    }
}
