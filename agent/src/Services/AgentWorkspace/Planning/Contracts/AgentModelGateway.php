<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelSelection;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;

interface AgentModelGateway
{
    /**
     * @param  array<string, mixed>  $assembledContext
     * @return array{response: AgentPlanningResponse, meta: array<string, mixed>}
     */
    public function plan(
        AgentPlanningRequest $request,
        AgentModelSelection $model,
        array $assembledContext,
    ): array;

    /**
     * @return array{summary: AgentConversationSummary, meta: array<string, mixed>}
     */
    public function summarize(
        AgentSummarizationRequest $request,
        AgentModelSelection $model,
    ): array;
}
