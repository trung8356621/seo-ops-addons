<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentCapabilityResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanGateway;

/**
 * MCP server adapter — delegates exclusively to Agent Gateway.
 */
final class ContentProjectMcpServer
{
    public function __construct(
        private readonly ContentProjectMcpToolCatalog $catalog,
        private readonly ContentProjectAgentGateway $gateway,
        private readonly ContentProjectAgentPlanGateway $planGateway,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        return $this->catalog->listTools();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callTool(AgentExecutionContext $context, string $name, array $arguments = []): AgentCapabilityResult
    {
        if ($this->catalog->isPlanTool($name)) {
            return $this->planGateway->execute($context, $name, $arguments);
        }

        return $this->gateway->execute($context, $name, $arguments);
    }
}
