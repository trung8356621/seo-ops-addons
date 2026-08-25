<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RestoreNewContentSuggestionsCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $fingerprints
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $fingerprints,
    ) {}

    public function name(): string
    {
        return 'content_project.restore_new_content_suggestions';
    }
}
