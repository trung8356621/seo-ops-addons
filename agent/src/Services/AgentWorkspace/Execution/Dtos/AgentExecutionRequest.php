<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

/**
 * @param  array<string, mixed>  $formInput
 */
final readonly class AgentExecutionRequest
{
    /**
     * @param  array<string, mixed>  $formInput
     */
    public function __construct(
        public AgentWorkspaceContext $context,
        public SeoAgentConversation $conversation,
        public string $skillKey,
        public array $formInput = [],
        public string $mode = 'execute',
        public ?string $parentExecutionRef = null,
        public ?string $planRef = null,
        public ?int $stepIndex = null,
        public ?int $attempt = null,
    ) {}
}
