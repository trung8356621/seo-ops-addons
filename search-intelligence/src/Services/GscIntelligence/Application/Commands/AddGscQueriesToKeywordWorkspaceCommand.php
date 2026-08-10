<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AddGscQueriesToKeywordWorkspaceCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $workspaceRef,
        public readonly array $queryRefs = [],
        public readonly bool $keepDuplicates = false
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.add_queries_to_workspace';
    }
}
