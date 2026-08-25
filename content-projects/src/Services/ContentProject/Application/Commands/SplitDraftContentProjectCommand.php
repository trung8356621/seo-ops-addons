<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Materialize an execution Content Project by moving Draft planning items.
 * Does not generate articles or publish.
 */
final class SplitDraftContentProjectCommand implements ContentProjectCommand
{
    public const MODE_FIRST_N = 'first_n';

    public const MODE_SELECTED = 'selected';

    public const MODE_ALL = 'all';

    /**
     * @param  list<int|string>  $itemRefs
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string $selectionMode = self::MODE_FIRST_N,
        public readonly ?int $quantity = null,
        public readonly array $itemRefs = [],
        public readonly string|null $targetMonth = null,
        public readonly string|null $projectName = null,
        public readonly bool $dryRun = false,
    ) {}

    public function name(): string
    {
        return 'content_project.split_draft';
    }
}
