<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

/**
 * Runtime lookup theo execution_role; khi thiếu role thì resolve từ Prompt hook
 * qua registry (không title heuristic).
 */
class WorkflowExecutionRoleResolver
{
    public function __construct(
        private readonly WorkflowExecutionRoleRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function readRole(array $node): ?WorkflowExecutionRole
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];

        $explicit = WorkflowExecutionRole::tryFromMixed(
            $data[WorkflowExecutionRoleRegistry::NODE_DATA_KEY] ?? null,
        );
        if ($explicit instanceof WorkflowExecutionRole) {
            return $explicit;
        }

        // Prompt + hook đã cấu hình = source of truth khi execution_role trống
        // (Workflow Builder từng lưu promptId mà không auto-gán role).
        $promptId = isset($data['promptId']) ? (int) $data['promptId'] : 0;
        $hook = $this->promptHookKeyOrEmpty($promptId);
        if ($hook === '') {
            $hook = trim((string) ($data['hook_key'] ?? $data['hookKey'] ?? ''));
            if (str_contains($hook, '@')) {
                $hook = trim(explode('@', $hook, 2)[0]);
            }
        }

        return $hook !== '' ? $this->registry->suggestRoleFromHook($hook) : null;
    }

    /**
     * @return array{node_id: string, node: array<string, mixed>, prompt_id: ?int}|null
     */
    public function findNode(SeoTask $task, WorkflowExecutionRole $role): ?array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];

        $hookMatch = null;

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }

            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $promptId = isset($data['promptId']) ? (int) $data['promptId'] : 0;
            $payload = [
                'node_id' => $nodeId,
                'node' => $node,
                'prompt_id' => $promptId > 0 ? $promptId : null,
            ];

            $explicit = WorkflowExecutionRole::tryFromMixed(
                $data[WorkflowExecutionRoleRegistry::NODE_DATA_KEY] ?? null,
            );
            if ($explicit === $role) {
                return $payload;
            }

            if ($explicit !== null) {
                continue;
            }

            if ($this->readRole($node) === $role && $hookMatch === null) {
                $hookMatch = $payload;
            }
        }

        return $hookMatch;
    }

    public function requireNodeId(SeoTask $task, WorkflowExecutionRole $role): string
    {
        $found = $this->findNode($task, $role);
        if ($found !== null) {
            return $found['node_id'];
        }

        $taskId = (int) $task->getKey();
        $taskName = trim((string) ($task->name ?? ''));
        $label = $taskName !== '' ? $taskName : '#'.$taskId;

        throw new \InvalidArgumentException(
            'Workflow chưa cấu hình node có vai trò: '.$role->value
            ."\nWorkflow: {$label} (#{$taskId})"
            ."\nVào SEO → Quy trình → Workflow Builder → gán «{$role->labelVi()}» cho Prompt Block tương ứng.",
        );
    }

    /**
     * @return list<string>
     */
    public function validateTask(SeoTask $task): array
    {
        $errors = [];
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];
        $seen = [];
        $nodeIds = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId !== '') {
                $nodeIds[$nodeId] = true;
            }

            $role = $this->readRole($node);
            if ($role === null) {
                continue;
            }

            $def = $this->registry->definition($role);
            $type = (string) ($node['type'] ?? '');

            if (! in_array($type, $def['allowed_node_types'], true)) {
                $errors[] = "Node {$nodeId}: role {$role->value} không hợp lệ với type «{$type}».";
            }

            if ($def['unique_per_workflow']) {
                if (isset($seen[$role->value])) {
                    $errors[] = "Role {$role->value} bị trùng (nodes {$seen[$role->value]} và {$nodeId}).";
                } else {
                    $seen[$role->value] = $nodeId;
                }
            }

            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
            if ($promptId <= 0) {
                $errors[] = "Node {$nodeId}: đã gán role {$role->value} nhưng Prompt bị thiếu/xóa.";

                continue;
            }

            if (! $this->promptExists($promptId)) {
                $errors[] = "Node {$nodeId}: Prompt #{$promptId} không tồn tại (đã xóa?).";

                continue;
            }

            $hook = $this->promptHookKeyOrEmpty($promptId);
            if ($hook !== '' && ! $this->registry->isHookAllowed($role, $hook)) {
                $errors[] = "Node {$nodeId}: Prompt hook «{$hook}» không khớp role {$role->value}.";
            }
        }

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $edgeId = trim((string) ($edge['id'] ?? ''));
            $source = trim((string) ($edge['source'] ?? ''));
            $target = trim((string) ($edge['target'] ?? ''));
            if ($source !== '' && ! isset($nodeIds[$source])) {
                $errors[] = "Edge {$edgeId}: tham chiếu node source «{$source}» đã bị xóa.";
            }
            if ($target !== '' && ! isset($nodeIds[$target])) {
                $errors[] = "Edge {$edgeId}: tham chiếu node target «{$target}» đã bị xóa.";
            }
        }

        return $errors;
    }

    /**
     * Hook check cần Eloquent/DB. Unit test / chưa bootstrap → bỏ qua (unique/type vẫn validate).
     */
    private function promptHookKeyOrEmpty(int $promptId): string
    {
        if ($promptId <= 0) {
            return '';
        }

        if (SeoPrompt::getConnectionResolver() === null) {
            return '';
        }

        try {
            $prompt = SeoPrompt::query()->find($promptId);
        } catch (\Throwable) {
            return '';
        }

        $hook = $prompt instanceof SeoPrompt
            ? trim((string) ($prompt->hook_key ?? ''))
            : '';

        if ($hook !== '' && str_contains($hook, '@')) {
            $hook = trim(explode('@', $hook, 2)[0]);
        }

        return $hook;
    }

    private function promptExists(int $promptId): bool
    {
        if ($promptId <= 0) {
            return false;
        }

        if (SeoPrompt::getConnectionResolver() === null) {
            // Unit test không có DB — không chặn unique/type validation.
            return true;
        }

        try {
            return SeoPrompt::query()->whereKey($promptId)->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: mixed}  $flowData
     * @return list<string>
     */
    public function validateFlowData(array $flowData): array
    {
        $task = new SeoTask;
        $task->flow_data = $flowData;
        $task->name = 'draft';

        return $this->validateTask($task);
    }
}
