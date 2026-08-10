<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class DetachClusterFromTopicCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $topicRef,
        public readonly string $clusterRef,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.detach_cluster';
    }
}
