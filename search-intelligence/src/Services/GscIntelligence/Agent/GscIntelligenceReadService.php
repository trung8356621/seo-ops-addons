<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Agent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceReadService as CoreGscIntelligenceReadService;

final class GscIntelligenceReadService
{
    public function __construct(
        private readonly CoreGscIntelligenceReadService $reads,
    ) {}

    /** @param array<string, mixed> $input */
    public function listProperties(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listProperties($this->siteId($context), $input);
    }

    /** @param array<string, mixed> $input */
    public function getProperty(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getProperty(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listSyncRuns(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listSyncRuns(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getSyncRun(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getSyncRun(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            trim((string) ($input['sync_run_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listQueryMappings(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listQueryMappings(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getQueryMapping(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getQueryMapping(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            trim((string) ($input['mapping_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listPageMappings(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listPageMappings(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getPageMapping(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getPageMapping(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            trim((string) ($input['mapping_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listAggregates(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listAggregates(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getAggregate(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getAggregate(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            trim((string) ($input['aggregate_ref'] ?? '')),
        );
    }

    /** @param array<string, mixed> $input */
    public function listOpportunities(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->listOpportunities(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            $input,
        );
    }

    /** @param array<string, mixed> $input */
    public function getOpportunity(AgentExecutionContext $context, array $input): array
    {
        return $this->reads->getOpportunity(
            $this->siteId($context),
            trim((string) ($input['property_ref'] ?? '')),
            trim((string) ($input['opportunity_ref'] ?? '')),
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
}
