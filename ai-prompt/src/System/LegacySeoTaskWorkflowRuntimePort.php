<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\System;

use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

/**
 * Legacy workflow adapter over existing seo_tasks.flow_data (omi_seo_ai).
 * Does NOT invoke domain TaskWorkflow runners or article side-effects in this phase.
 * Validates + loads definitions only; full native runtime comes later.
 */
final class LegacySeoTaskWorkflowRuntimePort implements WorkflowRuntimePort
{
    public function validate(array $definition): array
    {
        $errors = [];
        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? ($definition['connections'] ?? []);
        if (! is_array($nodes) || $nodes === []) {
            $errors[] = 'definition.nodes must be a non-empty array';
        }
        if (! is_array($edges)) {
            $errors[] = 'definition.edges must be an array';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'meta' => [
                'adapter' => 'legacy_seo_task',
                'storage' => 'seo_tasks.flow_data',
            ],
        ];
    }

    public function loadDefinition(int $definitionId): ?array
    {
        /** @var SeoTask|null $task */
        $task = SeoTask::query()->find($definitionId);
        if ($task === null) {
            return null;
        }

        $flow = $task->flow_data;
        if (! is_array($flow)) {
            return null;
        }

        return array_merge($flow, [
            '_meta' => [
                'definition_id' => $task->id,
                'name' => $task->name,
                'is_active' => (bool) $task->is_active,
            ],
        ]);
    }

    public function run(WorkflowRunRequest $request): WorkflowRunResult
    {
        $definition = $request->definition;
        if ($definition === null && $request->definitionId !== null) {
            $definition = $this->loadDefinition($request->definitionId);
        }
        if (! is_array($definition)) {
            return new WorkflowRunResult(
                id: 'wf_'.Str::lower(Str::random(12)),
                status: 'failed',
                errorCode: 'definition_not_found',
                errorMessage: 'Workflow definition not found in seo_tasks.',
                meta: ['adapter' => 'legacy_seo_task'],
            );
        }

        $validation = $this->validate($definition);
        if (! $validation['valid']) {
            return new WorkflowRunResult(
                id: 'wf_'.Str::lower(Str::random(12)),
                status: 'failed',
                errorCode: 'invalid_definition',
                errorMessage: implode('; ', $validation['errors']),
                meta: ['adapter' => 'legacy_seo_task'],
            );
        }

        // Phase boundary: do not run legacy domain workflow runners (article writes).
        // Callers needing article side-effects stay on legacy CP path.
        return new WorkflowRunResult(
            id: 'wf_'.Str::lower(Str::random(16)),
            status: 'accepted',
            steps: [],
            artifacts: [],
            meta: [
                'adapter' => 'legacy_seo_task',
                'execution' => 'deferred',
                'message' => 'Definition validated against seo_tasks; domain node execution remains on legacy CP path until cutover.',
                'definition_id' => $request->definitionId ?? ($definition['_meta']['definition_id'] ?? null),
            ],
        );
    }

    public function cancel(string $runId): WorkflowRunResult
    {
        return new WorkflowRunResult(
            id: $runId,
            status: 'failed',
            errorCode: 'not_supported',
            errorMessage: 'cancel not yet implemented on legacy workflow adapter',
        );
    }

    public function retry(string $runId): WorkflowRunResult
    {
        return new WorkflowRunResult(
            id: $runId,
            status: 'failed',
            errorCode: 'not_supported',
            errorMessage: 'retry not yet implemented on legacy workflow adapter',
        );
    }

    public function resume(string $runId): WorkflowRunResult
    {
        return new WorkflowRunResult(
            id: $runId,
            status: 'failed',
            errorCode: 'not_supported',
            errorMessage: 'resume not yet implemented on legacy workflow adapter',
        );
    }
}
