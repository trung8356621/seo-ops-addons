<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Immutable snapshot workflow tại lúc start run / execution.
 *
 * @phpstan-type NodeRow array{
 *     node_id: string,
 *     execution_role: string|null,
 *     prompt_id: int|null,
 *     type: string
 * }
 */
final class WorkflowExecutionSnapshot
{
    /**
     * @param  list<NodeRow>  $nodes
     */
    public function __construct(
        public readonly int $workflowId,
        public readonly string $flowDataHash,
        public readonly array $nodes,
        public readonly ?string $workflowName = null,
        public readonly string $capturedAt = '',
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function tryFromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $workflowId = (int) ($raw['workflow_id'] ?? 0);
        $hash = trim((string) ($raw['flow_data_hash'] ?? ''));
        if ($workflowId <= 0 || $hash === '') {
            return null;
        }

        $nodes = [];
        foreach (is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $nodeId = trim((string) ($row['node_id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $nodes[] = [
                'node_id' => $nodeId,
                'execution_role' => isset($row['execution_role'])
                    ? (trim((string) $row['execution_role']) ?: null)
                    : null,
                'prompt_id' => isset($row['prompt_id']) && (int) $row['prompt_id'] > 0
                    ? (int) $row['prompt_id']
                    : null,
                'type' => (string) ($row['type'] ?? ''),
            ];
        }

        return new self(
            workflowId: $workflowId,
            flowDataHash: $hash,
            nodes: $nodes,
            workflowName: isset($raw['workflow_name']) ? (string) $raw['workflow_name'] : null,
            capturedAt: (string) ($raw['captured_at'] ?? ''),
        );
    }

    /**
     * @return array{
     *     workflow_id: int,
     *     workflow_name: string|null,
     *     flow_data_hash: string,
     *     nodes: list<NodeRow>,
     *     captured_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflowId,
            'workflow_name' => $this->workflowName,
            'flow_data_hash' => $this->flowDataHash,
            'nodes' => $this->nodes,
            'captured_at' => $this->capturedAt !== '' ? $this->capturedAt : now()->toIso8601String(),
        ];
    }

    public function nodeIdForRole(string $roleValue): ?string
    {
        foreach ($this->nodes as $node) {
            if (($node['execution_role'] ?? null) === $roleValue) {
                return $node['node_id'];
            }
        }

        return null;
    }

    public function promptIdForNode(string $nodeId): ?int
    {
        foreach ($this->nodes as $node) {
            if ($node['node_id'] === $nodeId) {
                return $node['prompt_id'];
            }
        }

        return null;
    }
}
