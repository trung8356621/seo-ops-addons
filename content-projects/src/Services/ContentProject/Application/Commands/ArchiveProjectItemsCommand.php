<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ArchiveProjectItemsCommand implements ContentProjectCommand
{
    public const REASON_USER_REJECT = 'user_reject';

    public const REASON_GLOBAL_SKIP = 'global_skip';

    /** @param list<int|string> $itemRefs */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly ?string $note = null,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
        /** user_reject records project dismissal; global_skip archives without dismissal. */
        public readonly string $removeReason = self::REASON_USER_REJECT,
    ) {}

    public function name(): string
    {
        return 'content_project.archive_items';
    }

    public function shouldRecordSuggestionDismissal(): bool
    {
        return $this->removeReason !== self::REASON_GLOBAL_SKIP;
    }
}
