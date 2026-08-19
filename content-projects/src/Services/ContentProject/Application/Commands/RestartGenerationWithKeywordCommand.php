<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RestartGenerationWithKeywordCommand implements ContentProjectCommand
{
    /**
     * @param  list<int|string>  $itemRefs
     * @param  array<string, mixed>  $settings  Optional launch settings (e.g. generate_post_images).
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly string $keyword,
        public readonly string $mode = 'full',
        public readonly array $settings = [],
    ) {}

    public function name(): string
    {
        return 'content_project.restart_with_keyword';
    }
}
