<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionSnapshot;

final class WorkflowExecutionSnapshotBuilder
{
    public function __construct(
        private readonly WorkflowExecutionRoleResolver $roleResolver,
    ) {}

    public function fromTask(SeoTask $task): WorkflowExecutionSnapshot
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $hash = self::hashFlowData($flow);
        $nodes = [];

        foreach (is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $role = $this->roleResolver->readRole($node);
            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;

            $nodes[] = [
                'node_id' => $nodeId,
                'execution_role' => $role?->value,
                'prompt_id' => $promptId > 0 ? $promptId : null,
                'type' => (string) ($node['type'] ?? ''),
            ];
        }

        return new WorkflowExecutionSnapshot(
            workflowId: (int) $task->getKey(),
            flowDataHash: $hash,
            nodes: $nodes,
            workflowName: trim((string) ($task->name ?? '')) ?: null,
            capturedAt: now()->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $flowData
     */
    public static function hashFlowData(array $flowData): string
    {
        $normalized = [
            'nodes' => is_array($flowData['nodes'] ?? null) ? $flowData['nodes'] : [],
            'edges' => is_array($flowData['edges'] ?? null) ? $flowData['edges'] : [],
        ];

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
