<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Agent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceReadService as CoreSerpIntelligenceReadService;

final class SerpIntelligenceReadService
{
    public function __construct(
        private readonly CoreSerpIntelligenceReadService $reads,
    ) {}

    /** @param array<string, mixed> $input */
    public function listQueries(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listQueries($this->siteId($context), $this->workspaceRef($input), $input);
    }

    /** @param array<string, mixed> $input */
    public function getQuery(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getQuery(
            $this->siteId($context),
            $this->workspaceRef($input),
            trim((string) ($input['query_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listSnapshots(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listSnapshots($this->siteId($context), $this->workspaceRef($input), $input);
    }

    /** @param array<string, mixed> $input */
    public function getSnapshot(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getSnapshot(
            $this->siteId($context),
            $this->workspaceRef($input),
            trim((string) ($input['snapshot_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listResults(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listResults(
            $this->siteId($context),
            trim((string) ($input['snapshot_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function listFeatures(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listFeatures(
            $this->siteId($context),
            trim((string) ($input['snapshot_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getClusterEvidence(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getClusterEvidence(
            $this->siteId($context),
            $this->workspaceRef($input),
            trim((string) ($input['evidence_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listContentGaps(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listContentGaps($this->siteId($context), $this->workspaceRef($input), $input);
    }

    /** @param array<string, mixed> $input */
    public function listCompetitors(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listCompetitors(
            $this->siteId($context),
            trim((string) ($input['snapshot_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getOperation(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getOperation(
            $this->siteId($context),
            trim((string) ($input['operation_ref'] ?? '')),
        );
    }

    private function siteId(AgentExecutionContext $context): int
    {
        return (int) ($context->resolvedSiteId ?? 0);
    }

    /** @param array<string, mixed> $input */
    private function workspaceRef(array $input): string
    {
        return trim((string) ($input['workspace_ref'] ?? ''));
    }
}
