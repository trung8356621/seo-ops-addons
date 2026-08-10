<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Step-scoped rerun (outline|article) via CommandBus → RunEngine.
 *
 * @param  list<int|string>  $itemRefs
 */
final class RerunProjectItemStepCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly ContentProjectRerunFromStep $fromStep,
        public readonly bool $includeDownstream = false,
        public readonly ?int $sourceArticleId = null,
        public readonly string $mode = 'full',
        public readonly bool $syncExecution = false,
    ) {}

    public function name(): string
    {
        return 'content_project.rerun_step';
    }
}
