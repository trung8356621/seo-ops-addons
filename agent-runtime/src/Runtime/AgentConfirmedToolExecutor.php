<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalExecutor;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;

final class AgentConfirmedToolExecutor
{
    private const CONNECTED_TOOLS = [
        'gsc.performance',
        'content_projects.read',
        'seo_audit.worst_articles',
    ];

    public function __construct(
        private readonly RetrievalExecutor $retrieval,
        private readonly SeoAuditAgentReadService $seoAudit,
    ) {}

    public function execute(AgentToolConfirmationProposal $proposal, int $userId): RetrievalBundle
    {
        $scope = AgentProjectScope::fromArray($proposal->scope);
        if (! $scope->isSite() || $scope->siteId === null) {
            throw new InvalidArgumentException('Confirmed Tool execution requires a site scope.');
        }

        $this->assertRegistryContract($proposal);
        foreach ($proposal->toolCapabilities as $capability) {
            if (! in_array($capability, self::CONNECTED_TOOLS, true)) {
                throw new InvalidArgumentException("Confirmed Tool capability [{$capability}] is not connected.");
            }
        }

        $retrievalCapabilities = array_values(array_filter(
            $proposal->capabilities,
            static fn (string $capability): bool => $capability !== 'seo_audit.worst_articles',
        ));
        $modules = $retrievalCapabilities === [] ? [] : AgentCapabilityCatalog::modulesFor($retrievalCapabilities);
        $bundle = $modules === []
            ? new RetrievalBundle($scope, [])
            : $this->retrieval->execute(new RetrievalDecision(
                true,
                $proposal->intent,
                $proposal->primaryCapability,
                $retrievalCapabilities,
                $modules[0] ?? null,
                $modules,
                $proposal->parameters,
                false,
                false,
                $proposal->responseTemplate,
            ), $scope);

        if (! in_array('seo_audit.worst_articles', $proposal->toolCapabilities, true)) {
            return $bundle;
        }

        $limit = max(1, min(100, (int) ($proposal->parameters['limit_max'] ?? 50)));
        $context = new AgentExecutionContext(
            actorRef: 'user:'.$userId,
            actorType: 'agent',
            tenantRef: 'user:'.$userId,
            siteRef: (string) $scope->siteRef,
            requestRef: 'agent-confirmed-tool',
            resolvedSiteId: $scope->siteId,
            resolvedActorUserId: $userId,
            scopes: ['content-project:read'],
        );
        $data = $this->seoAudit->listArticles($context, ['low_score' => true, 'limit' => $limit]);

        return new RetrievalBundle(
            $scope,
            [...$bundle->sources, new RetrievalSource(
                'articles',
                'ok',
                'SeoAuditAgentReadService::listArticles?low_score=true&limit='.$limit,
                $data,
            )],
            $bundle->warnings,
        );
    }

    private function assertRegistryContract(AgentToolConfirmationProposal $proposal): void
    {
        if ($proposal->capabilities === [] || $proposal->toolCapabilities === []) {
            throw new InvalidArgumentException('Frozen confirmation proposal has no Tool capabilities.');
        }
        if ($proposal->primaryCapability === '' || ! in_array($proposal->primaryCapability, $proposal->capabilities, true)) {
            throw new InvalidArgumentException('Frozen primary capability contradicts the capability list.');
        }
        foreach ($proposal->capabilities as $capability) {
            $metadata = AgentCapabilityCatalog::get($capability);
            if (($metadata['jev_selectable'] ?? false) !== true
                || in_array((string) ($metadata['execution_mode'] ?? ''), ['hidden', 'internal'], true)) {
                throw new InvalidArgumentException("Frozen capability [{$capability}] is no longer available.");
            }
        }

        $expectedTools = AgentCapabilityCatalog::toolCapabilities($proposal->capabilities);
        $frozenTools = array_values(array_unique($proposal->toolCapabilities));
        sort($expectedTools);
        sort($frozenTools);
        if ($expectedTools !== $frozenTools) {
            throw new InvalidArgumentException('Frozen Tool proposal contradicts the capability registry.');
        }
    }
}
