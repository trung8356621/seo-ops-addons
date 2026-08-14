<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RerunProjectItemsCommand implements ContentProjectCommand
{
    /**
     * @param  list<int|string>  $itemRefs
     * @param  array<string, mixed>  $settings  Optional launch settings (e.g. generate_post_images).
     *                                          Orchestration keys are forced in the handler.
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs = [],
        public readonly string $mode = 'full',
        public readonly array $settings = [],
    ) {}

    public function name(): string
    {
        return 'content_project.rerun';
    }
}
