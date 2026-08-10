<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ArchiveKeywordWorkspaceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.archive_workspace';
    }
}
