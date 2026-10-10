<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Testing;

use Omnichannel\Addons\AgentRuntime\Integration\AgentIntegrationRegistry;
use Omnichannel\Addons\AgentRuntime\Routing\LocalAgentToolRouter;

final class RoutingCasesRunner
{
    public function __construct(private readonly AgentIntegrationRegistry $integrations, private readonly LocalAgentToolRouter $router) {}

    /** @return list<array<string, mixed>> */
    public function run(?string $service = null): array
    {
        $results = [];
        foreach ($this->integrations->cases($service) as $case) {
            $route = $this->router->route((string) ($case['question'] ?? ''));
            $actualOperation = $route->diagnostics['operation'] ?? null;
            $passed = $route->outcome === ($case['expected_outcome'] ?? null)
                && $route->module === ($case['expected_module'] ?? null)
                && $actualOperation === ($case['expected_operation'] ?? null);
            $results[] = $case + [
                'passed' => $passed,
                'actual_outcome' => $route->outcome,
                'actual_module' => $route->module,
                'actual_operation' => $actualOperation,
                'semantic' => $route->diagnostics,
            ];
        }
        return $results;
    }
}
