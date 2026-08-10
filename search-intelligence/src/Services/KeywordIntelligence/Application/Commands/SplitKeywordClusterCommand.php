<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class SplitKeywordClusterCommand implements ContentProjectCommand
{
    /**
     * @param  list<array{name?: string, keyword_refs: list<string>, primary_keyword_ref?: string|null}>  $groups
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $sourceClusterRef,
        public readonly array $groups,
        public readonly bool $leaveUnassigned = false,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.split_cluster';
    }
}
