<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

final readonly class AgentPlanningRequest
{
    /**
     * @param  array<string, mixed>  $clarificationAnswers
     * @param  array<string, mixed>  $hints
     */
    public function __construct(
        public AgentWorkspaceContext $context,
        public SeoAgentConversation $conversation,
        public string $userMessage,
        public string $taskType = 'plan_generation',
        public array $clarificationAnswers = [],
        public array $hints = [],
        public ?string $preferredModel = null,
        public ?string $planningRequestId = null,
    ) {}
}
