<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Mark item(s) skipped for automatic Generate / Retry / resume selection.
 * Does not delete article content or archive the item.
 *
 * @param  list<int|string>  $itemRefs
 */
final class BlockProjectItemGenerationCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'content_project.block_generation';
    }
}
