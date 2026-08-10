<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

final class SerpResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'serp.');
    }

    public function render(AgentExecutionResult $result): array
    {
        $base = (new GenericAgentResultRenderer)->render($result);
        $base['title'] = $result->ok ? 'SERP Intelligence' : 'SERP lỗi';
        $workspace = $result->data['serp_workspace_ref'] ?? $result->data['workspace_ref'] ?? null;
        if (is_string($workspace) && $workspace !== '') {
            $base['links'][] = [
                'label' => 'SERP Workspace',
                'ref' => $workspace,
                'type' => 'serp_workspace',
            ];
        }

        return $base;
    }
}
