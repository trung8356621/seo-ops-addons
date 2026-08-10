<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ArchiveSerpQueriesCommand implements ContentProjectCommand
{
    /** @param list<string> $queryRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $queryRefs,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.archive_queries';
    }
}
