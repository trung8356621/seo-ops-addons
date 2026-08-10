<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationApproval;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationRun;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomationState;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationDefinitionData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use DateTimeInterface;

interface AgentAutomationRepository
{
    public function create(AgentWorkspaceContext $context, AgentAutomationDefinitionData $definition): SeoAgentAutomation;

    public function update(SeoAgentAutomation $automation, AgentAutomationDefinitionData $definition): SeoAgentAutomation;

    public function findByHash(string $hashId): ?SeoAgentAutomation;

    /**
     * @return list<SeoAgentAutomation>
     */
    public function listForContext(AgentWorkspaceContext $context): array;

    /**
     * @return list<SeoAgentAutomation>
     */
    public function findDue(DateTimeInterface $nowUtc, int $limit = 100): array;

    public function claimOccurrence(
        SeoAgentAutomation $automation,
        string $occurrenceKey,
        DateTimeInterface $scheduledAt,
        string $triggerSource,
        string $status = 'queued',
    ): SeoAgentAutomationRun;

    public function findRunById(int $runId): ?SeoAgentAutomationRun;

    public function findRunByHash(string $hashId): ?SeoAgentAutomationRun;

    /**
     * @return list<SeoAgentAutomationRun>
     */
    public function listRuns(int $automationId, int $limit = 50): array;

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function updateRun(SeoAgentAutomationRun $run, array $attrs): SeoAgentAutomationRun;

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function updateAutomation(SeoAgentAutomation $automation, array $attrs): SeoAgentAutomation;

    /**
     * @param  array<string, mixed>  $previewPayload
     */
    public function createApproval(
        SeoAgentAutomation $automation,
        SeoAgentAutomationRun $run,
        string $tokenHash,
        array $previewPayload,
        DateTimeInterface $expiresAt,
        ?string $executionRef = null,
    ): SeoAgentAutomationApproval;

    public function findApprovalByHash(string $hashId): ?SeoAgentAutomationApproval;

    public function getState(int $automationId, string $stateKey): ?SeoAgentAutomationState;

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function putState(
        int $automationId,
        string $stateKey,
        ?string $fingerprint,
        ?array $payload,
    ): SeoAgentAutomationState;

    public function countActiveForSite(int $siteId): int;

    public function countForOwner(int $ownerUserId): int;

    public function countRunsSince(int $automationId, DateTimeInterface $since): int;

    public function countConcurrentRunning(int $siteId): int;

    public function softDelete(SeoAgentAutomation $automation): void;
}
