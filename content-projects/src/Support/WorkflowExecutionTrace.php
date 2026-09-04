<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Slim observability trace persisted alongside prompt-only steps.
 *
 * @phpstan-type TraceRow array{
 *     node_id: ?string,
 *     type: string,
 *     title: string,
 *     status: string,
 *     skip_reason: ?string,
 *     message: ?string,
 *     hook_key: ?string,
 *     execution_role: ?string,
 *     result_id: ?int,
 *     prompt_result_ids: list<int>,
 *     prompt_id: ?int,
 *     ai_model: ?string,
 *     duration_ms: ?int,
 *     outline_subtask: ?string,
 *     action: ?string,
 *     filter_type: ?string
 * }
 */
final class WorkflowExecutionTrace
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<TraceRow>
     */
    public static function fromSteps(array $steps): array
    {
        $trace = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $nodeId = trim((string) ($step['node_id'] ?? ''));
            $title = trim((string) ($step['title'] ?? $step['prompt_name'] ?? ''));
            $type = trim((string) ($step['type'] ?? ''));
            if ($nodeId === '' && $title === '' && $type === '') {
                continue;
            }

            $resultIds = [];
            foreach (['outline_result_id', 'vocabulary_result_id'] as $key) {
                $id = (int) ($step[$key] ?? 0);
                if ($id > 0 && ! in_array($id, $resultIds, true)) {
                    $resultIds[] = $id;
                }
            }
            foreach (is_array($step['prompt_result_ids'] ?? null) ? $step['prompt_result_ids'] : [] as $rid) {
                $id = (int) $rid;
                if ($id > 0 && ! in_array($id, $resultIds, true)) {
                    $resultIds[] = $id;
                }
            }
            $single = (int) ($step['result_id'] ?? 0);
            if ($single > 0 && ! in_array($single, $resultIds, true)) {
                $resultIds[] = $single;
            }

            $trace[] = [
                'node_id' => $nodeId !== '' ? $nodeId : null,
                'type' => $type,
                'title' => $title,
                'status' => trim((string) ($step['status'] ?? '')),
                'skip_reason' => self::nullableString($step['skip_reason'] ?? null),
                'message' => self::nullableString($step['message'] ?? null),
                'hook_key' => self::nullableString($step['hook_key'] ?? null),
                'execution_role' => self::nullableString($step['execution_role'] ?? null),
                'result_id' => $single > 0 ? $single : null,
                'prompt_result_ids' => $resultIds,
                'outline_result_id' => (($oid = (int) ($step['outline_result_id'] ?? 0)) > 0) ? $oid : null,
                'vocabulary_result_id' => (($vid = (int) ($step['vocabulary_result_id'] ?? 0)) > 0) ? $vid : null,
                'outline_status' => self::nullableString($step['outline_status'] ?? null),
                'vocabulary_status' => self::nullableString($step['vocabulary_status'] ?? null),
                'outline_message' => self::nullableString($step['outline_message'] ?? null),
                'vocabulary_message' => self::nullableString($step['vocabulary_message'] ?? null),
                'prompt_id' => isset($step['prompt_id']) && (int) $step['prompt_id'] > 0
                    ? (int) $step['prompt_id']
                    : null,
                'ai_model' => self::nullableString($step['ai_model'] ?? $step['render_model'] ?? null),
                'duration_ms' => isset($step['duration_ms']) && is_numeric($step['duration_ms'])
                    ? (int) $step['duration_ms']
                    : null,
                'outline_subtask' => self::nullableString($step['outline_subtask'] ?? null),
                'execution_sequence' => isset($step['execution_sequence']) && is_numeric($step['execution_sequence'])
                    ? (int) $step['execution_sequence']
                    : null,
                'action' => self::nullableString($step['action'] ?? null),
                'filter_type' => self::nullableString($step['filter_type'] ?? null),
            ];
        }

        return $trace;
    }

    private static function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
