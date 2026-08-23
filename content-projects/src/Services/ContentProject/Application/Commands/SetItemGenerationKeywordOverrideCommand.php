<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class SetItemGenerationKeywordOverrideCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $itemRef,
        /** null = revert override */
        public readonly ?string $generationKeywordOverride,
    ) {}

    public function name(): string
    {
        return 'content_project.set_generation_keyword_override';
    }
}
