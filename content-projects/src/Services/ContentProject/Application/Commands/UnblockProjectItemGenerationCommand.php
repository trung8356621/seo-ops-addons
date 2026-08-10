<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Clear generation block so item may be selected by Generate / Retry again.
 *
 * @param  list<int|string>  $itemRefs
 */
final class UnblockProjectItemGenerationCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
    ) {}

    public function name(): string
    {
        return 'content_project.unblock_generation';
    }
}
