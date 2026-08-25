<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class GenerateNewContentSuggestionsCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly int $quantity = 20,
        public readonly array $options = [],
        public readonly bool $dryRun = false,
    ) {}

    public function name(): string
    {
        return 'content_project.generate_new_content_suggestions';
    }
}
