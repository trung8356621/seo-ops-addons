<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ValidateWorkspaceClustersWithSerpCommand implements ContentProjectCommand
{
    /** @param list<string>|null $clusterRefs */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?array $clusterRefs = null,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.validate_workspace_clusters';
    }
}
