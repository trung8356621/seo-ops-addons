<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

/**
 * Chỉ dùng migration/audit — không runtime fallback.
 */
final class WorkflowRoleMigrationSuggester
{
    public function __construct(
        private readonly WorkflowExecutionRoleRegistry $registry,
        private readonly WorkflowExecutionRoleResolver $resolver,
    ) {}

    /**
     * @return list<array{
     *     workflow_id: int,
     *     workflow_name: string,
     *     node_id: string,
     *     node_label: string,
     *     prompt_id: ?int,
     *     prompt_name: ?string,
     *     hook_key: ?string,
     *     current_role: ?string,
     *     suggested_role: ?string,
     *     confidence: string,
     *     conflict: ?string,
     *     auto_assignable: bool
     * }>
     */
    public function auditTask(SeoTask $task): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $rows = [];
        $suggestedCounts = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || (string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }

            $nodeId = trim((string) ($node['id'] ?? ''));
            $label = trim((string) ($node['title'] ?? $nodeId));
            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
            $prompt = $promptId > 0 ? SeoPrompt::query()->find($promptId) : null;
            $hook = $prompt instanceof SeoPrompt ? trim((string) ($prompt->hook_key ?? '')) : '';
            $current = $this->resolver->readRole($node);
            $suggested = $hook !== '' ? $this->registry->suggestRoleFromHook($hook) : null;

            $confidence = 'none';
            $conflict = null;
            $auto = false;

            if ($current !== null) {
                $confidence = 'already_set';
            } elseif ($suggested instanceof WorkflowExecutionRole) {
                $confidence = 'high_hook';
                $auto = true;
                $key = $suggested->value;
                $suggestedCounts[$key] = ($suggestedCounts[$key] ?? 0) + 1;
            } elseif ($hook !== '') {
                $confidence = 'ambiguous_hook';
                $conflict = 'Hook không map 1-1 sang role registry.';
            } else {
                $confidence = 'missing_hook';
                $conflict = 'Thiếu hook_key — không auto-assign theo title.';
            }

            $rows[] = [
                'workflow_id' => (int) $task->getKey(),
                'workflow_name' => (string) ($task->name ?? ''),
                'node_id' => $nodeId,
                'node_label' => $label,
                'prompt_id' => $promptId > 0 ? $promptId : null,
                'prompt_name' => $prompt instanceof SeoPrompt ? (string) $prompt->name : null,
                'hook_key' => $hook !== '' ? $hook : null,
                'current_role' => $current?->value,
                'suggested_role' => $suggested?->value,
                'confidence' => $confidence,
                'conflict' => $conflict,
                'auto_assignable' => $auto,
            ];
        }

        // Duplicate suggestions → không auto.
        foreach ($rows as &$row) {
            $suggested = $row['suggested_role'];
            if ($suggested !== null
                && ($suggestedCounts[$suggested] ?? 0) > 1
                && $row['current_role'] === null
            ) {
                $row['auto_assignable'] = false;
                $row['confidence'] = 'duplicate_suggested';
                $row['conflict'] = 'Nhiều node cùng suggested role — operator chọn thủ công.';
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{assigned: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function applyTask(SeoTask $task): array
    {
        $rows = $this->auditTask($task);
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $assigned = 0;
        $skipped = 0;
        $changed = false;

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            $match = null;
            foreach ($rows as $row) {
                if ($row['node_id'] === $nodeId) {
                    $match = $row;
                    break;
                }
            }
            if ($match === null || ! $match['auto_assignable'] || $match['suggested_role'] === null) {
                $skipped++;
                continue;
            }
            if ($match['current_role'] !== null) {
                $skipped++;
                continue;
            }

            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $data[WorkflowExecutionRoleRegistry::NODE_DATA_KEY] = $match['suggested_role'];
            $nodes[$index]['data'] = $data;
            $assigned++;
            $changed = true;
        }

        if ($changed) {
            $flow['nodes'] = $nodes;
            $task->flow_data = $flow;
            $task->save();
        }

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'rows' => $this->auditTask($task->fresh() ?? $task),
        ];
    }
}
