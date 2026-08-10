<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateContentProjectFromKeywordClustersCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $clusterRefs
     * @param  array<string, mixed>  $projectAttributes
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $clusterRefs,
        public readonly array $projectAttributes = [],
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.convert_to_content_project';
    }
}
