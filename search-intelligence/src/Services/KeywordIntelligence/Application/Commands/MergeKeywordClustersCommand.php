<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class MergeKeywordClustersCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $sourceClusterRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $sourceClusterRefs,
        public readonly string $targetClusterRef,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.merge_clusters';
    }
}
