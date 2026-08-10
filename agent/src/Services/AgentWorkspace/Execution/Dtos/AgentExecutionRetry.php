<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

final readonly class AgentExecutionRetry
{
    public function __construct(
        public AgentWorkspaceContext $context,
        public string $executionRef,
    ) {}
}
