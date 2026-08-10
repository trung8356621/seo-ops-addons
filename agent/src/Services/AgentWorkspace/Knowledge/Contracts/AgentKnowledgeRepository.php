<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentKnowledgeItem;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeItemData;

interface AgentKnowledgeRepository
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public function create(array $attrs): SeoAgentKnowledgeItem;

    public function findByHash(string $hashId, AgentWorkspaceContext $context): ?SeoAgentKnowledgeItem;

    public function findDuplicate(int $siteId, string $contentHash, string $scopeType, ?string $scopeRef): ?SeoAgentKnowledgeItem;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<SeoAgentKnowledgeItem>
     */
    public function listForContext(AgentWorkspaceContext $context, array $filters = []): array;

    public function toData(SeoAgentKnowledgeItem $item): AgentKnowledgeItemData;

    public function save(SeoAgentKnowledgeItem $item): void;
}
