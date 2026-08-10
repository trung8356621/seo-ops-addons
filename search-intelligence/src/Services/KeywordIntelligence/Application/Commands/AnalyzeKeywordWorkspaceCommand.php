<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class AnalyzeKeywordWorkspaceCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>|null  $keywordRefs  null = toàn workspace
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?string $clusteringStrategy = null,
        public readonly ?array $keywordRefs = null,
        public readonly array $options = [],
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.analyze_workspace';
    }
}
