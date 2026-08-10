<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentGroundingContextProvider;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRetriever;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Throwable;

/**
 * Minimal Phase 3 grounding bridge — failures never crash planning.
 */
final class DefaultAgentGroundingContextProvider implements AgentGroundingContextProvider
{
    public function __construct(
        private readonly AgentKnowledgeRetriever $retriever,
        private readonly int $maxItems = 6,
        private readonly int $tokenBudget = 900,
    ) {}

    public function build(
        AgentPlanningRequest $request,
        ?AgentWorkspaceContext $context = null,
    ): AgentGroundedContextPackage {
        $ctx = $context ?? $request->context;

        try {
            if ($ctx->siteId <= 0 || $ctx->siteRef === '') {
                return new AgentGroundedContextPackage(
                    warnings: ['grounding_skipped_missing_site'],
                    diagnostics: ['skipped' => true],
                );
            }

            $conversationRef = (string) ($request->conversation->public_ref ?? '');

            return $this->retriever->retrieve(new AgentKnowledgeQuery(
                tenantId: $ctx->tenantId,
                siteId: $ctx->siteId,
                connectionHash: null,
                message: $request->userMessage,
                siteRef: $ctx->siteRef,
                projectRef: $ctx->projectRef,
                workspaceRef: $ctx->workspaceRef,
                conversationRef: $conversationRef !== '' ? $conversationRef : null,
                ownerUserId: $ctx->actorUserId,
                taskType: $request->taskType,
                maxResults: $this->maxItems,
                tokenBudget: $this->tokenBudget,
                allowStaleWithWarning: true,
            ));
        } catch (Throwable $e) {
            return new AgentGroundedContextPackage(
                warnings: ['grounding_failed'],
                diagnostics: [
                    'error' => 'grounding_failed',
                    'message' => mb_substr($e->getMessage(), 0, 120),
                ],
            );
        }
    }
}
