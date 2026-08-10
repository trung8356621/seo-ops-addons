<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AddContentProjectItemsCommand implements ContentProjectCommand
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $items,
    ) {}

    public function name(): string
    {
        return 'content_project.add_items';
    }
}
