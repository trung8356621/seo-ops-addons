<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class UpdateContentProjectCommand implements ContentProjectCommand
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $attributes,
    ) {}

    public function name(): string
    {
        return 'content_project.update';
    }
}
