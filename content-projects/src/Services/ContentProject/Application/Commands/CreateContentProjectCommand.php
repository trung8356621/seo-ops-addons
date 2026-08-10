<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateContentProjectCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $tasksData
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $tasksData = [],
    ) {}

    public function name(): string
    {
        return 'content_project.create';
    }
}
