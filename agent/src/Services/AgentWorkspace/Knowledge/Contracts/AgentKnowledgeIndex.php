<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeChunkData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeSearchResult;

interface AgentKnowledgeIndex
{
    /**
     * @param  list<AgentKnowledgeChunkData>  $chunks
     */
    public function indexItem(SeoAgentKnowledgeItem $item, array $chunks): void;

    public function removeItem(SeoAgentKnowledgeItem $item): void;

    public function search(AgentKnowledgeQuery $query): AgentKnowledgeSearchResult;

    public function adapterName(): string;
}
