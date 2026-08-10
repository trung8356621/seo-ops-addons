<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeItemData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentMemoryProposal;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;

interface AgentKnowledgeOrchestrator
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function ingest(AgentWorkspaceContext $context, array $input): array;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(AgentWorkspaceContext $context, array $filters = []): array;

    public function get(AgentWorkspaceContext $context, string $hashId): ?AgentKnowledgeItemData;

    /**
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function correct(AgentWorkspaceContext $context, string $hashId, array $edits): array;

    /**
     * @return array<string, mixed>
     */
    public function verify(AgentWorkspaceContext $context, string $hashId): array;

    /**
     * @return array<string, mixed>
     */
    public function disable(AgentWorkspaceContext $context, string $hashId, ?string $reason = null): array;

    /**
     * @return array<string, mixed>
     */
    public function forget(AgentWorkspaceContext $context, string $hashId): array;

    /**
     * @return array<string, mixed>
     */
    public function search(AgentWorkspaceContext $context, AgentKnowledgeQuery $query): array;

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function createProposal(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $candidate,
    ): AgentMemoryProposal;

    /**
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    public function resolveProposal(
        AgentWorkspaceContext $context,
        string $proposalId,
        string $action,
        array $edits = [],
    ): array;
}
