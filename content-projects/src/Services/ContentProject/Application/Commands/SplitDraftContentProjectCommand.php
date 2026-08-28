<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Materialize execution Content Project(s) by moving Draft planning items
 * onto selected real writers (current month, 30 items/user cap).
 * Does not generate or publish.
 */
final class SplitDraftContentProjectCommand implements ContentProjectCommand
{
    public const MODE_FIRST_N = 'first_n';

    public const MODE_SELECTED = 'selected';

    public const MODE_ALL = 'all';

    /**
     * @param  list<int|string>  $itemRefs
     * @param  list<int|string>  $assigneeIds  Real writer user ids in UI order
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string $selectionMode = self::MODE_FIRST_N,
        public readonly ?int $quantity = null,
        public readonly array $itemRefs = [],
        public readonly bool $dryRun = false,
        public readonly array $assigneeIds = [],
    ) {}

    public function name(): string
    {
        return 'content_project.split_draft';
    }
}
