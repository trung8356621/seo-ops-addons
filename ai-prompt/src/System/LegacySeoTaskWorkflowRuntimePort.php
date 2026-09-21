<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\System;

use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowGraphScope;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionScope;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Throwable;

/**
 * Domain WorkflowRuntimePort over seo_tasks.flow_data.
 *
 * Orchestration boundary: SystemWorkflowClient → this port → TaskWorkflowTestRunner.
 * Does not implement a second graph engine.
 */
final class LegacySeoTaskWorkflowRuntimePort implements WorkflowRuntimePort
{
    public function __construct(
        private readonly TaskWorkflowTestRunner $runner,
    ) {}

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
        $runId = 'wf_'.Str::lower(Str::random(16));

        $mode = WorkflowExecutionMode::tryParse($request->executionMode);
        if ($mode === null) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'invalid_execution_mode',
                errorMessage: 'Unknown execution_mode "'.$request->executionMode.'". Expected full_run|from_node|single_step.',
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $request->executionMode,
                ],
            );
        }

        $definitionId = $request->definitionId;
        $definition = $request->definition;
        if ($definition === null && $definitionId !== null) {
            $definition = $this->loadDefinition($definitionId);
        }
        if (! is_array($definition)) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'definition_not_found',
                errorMessage: 'Workflow definition not found in seo_tasks.',
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                ],
            );
        }

        $validation = $this->validate($definition);
        if (! $validation['valid']) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'invalid_definition',
                errorMessage: implode('; ', $validation['errors']),
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                ],
            );
        }

        $taskId = $definitionId
            ?? (isset($definition['_meta']['definition_id']) ? (int) $definition['_meta']['definition_id'] : 0);
        if ($taskId <= 0) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'definition_id_required',
                errorMessage: 'definition_id is required to execute the canonical TaskWorkflowTestRunner.',
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                ],
            );
        }

        /** @var SeoTask|null $task */
        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'definition_not_found',
                errorMessage: "SeoTask #{$taskId} not found.",
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                ],
            );
        }

        $ownerGate = $this->assertExecutionOwnership($request, $task);
        if ($ownerGate !== null) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: $ownerGate['code'],
                errorMessage: $ownerGate['message'],
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                    'definition_id' => $taskId,
                    'owner_user_id' => $ownerGate['owner_user_id'] ?? null,
                    'task_owner_user_id' => (int) ($task->user_id ?? 0),
                ],
            );
        }

        $ownerUserId = (int) ($request->correlation['owner_user_id'] ?? $request->context['owner_user_id'] ?? 0);

        $contextPayload = is_array($request->context['task_test_context'] ?? null)
            ? $request->context['task_test_context']
            : [];
        if ($contextPayload === [] && $request->input !== []) {
            $contextPayload = [
                'article_id' => null,
                'is_new_article' => true,
                'matched_by' => 'system_workflow',
                'summary' => 'System Workflow disposable/raw input',
                'variables' => array_map(
                    static fn (mixed $v): string => is_string($v) ? $v : (string) $v,
                    $request->input,
                ),
            ];
        }
        if ($contextPayload === []) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: 'context_required',
                errorMessage: 'task_test_context (or input) is required for workflow execution.',
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                ],
            );
        }

        $articleScopeError = $this->assertArticleSiteOwnership($contextPayload, $ownerUserId);
        if ($articleScopeError !== null) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                errorCode: $articleScopeError['code'],
                errorMessage: $articleScopeError['message'],
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'execution_mode' => $mode->value,
                    'definition_id' => $taskId,
                    'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                ],
            );
        }

        try {
            $context = TaskTestContext::fromArray($contextPayload);
            $steps = $this->executeByMode($mode, $task, $context, $request);
        } catch (Throwable $e) {
            return new WorkflowRunResult(
                id: $runId,
                status: 'failed',
                steps: [],
                artifacts: [],
                meta: [
                    'adapter' => 'legacy_seo_task',
                    'definition_id' => $taskId,
                    'runner' => TaskWorkflowTestRunner::class,
                    'execution_mode' => $mode->value,
                    'source' => (string) ($request->context['source'] ?? ''),
                    'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                ],
                errorCode: 'runner_exception',
                errorMessage: $e->getMessage(),
            );
        }

        return $this->mapRunnerSteps(
            runId: $runId,
            steps: $steps,
            taskId: $taskId,
            mode: $mode,
            source: (string) ($request->context['source'] ?? ''),
            executionScope: $request->executionScope,
            ownerUserId: $ownerUserId > 0 ? $ownerUserId : null,
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

    /**
     * @return list<array<string, mixed>>
     */
    private function executeByMode(
        WorkflowExecutionMode $mode,
        SeoTask $task,
        TaskTestContext $context,
        WorkflowRunRequest $request,
    ): array {
        return match ($mode) {
            WorkflowExecutionMode::FullRun => $this->runner->run($task, $context),
            WorkflowExecutionMode::FromNode => $this->executeFromNode($task, $context, $request),
            WorkflowExecutionMode::SingleStep => $this->executeSingleStep($task, $context, $request),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executeFromNode(
        SeoTask $task,
        TaskTestContext $context,
        WorkflowRunRequest $request,
    ): array {
        $startNodeId = trim((string) ($request->startNodeId ?? ''));
        if ($startNodeId === '') {
            throw new \InvalidArgumentException('start_node_id is required for execution_mode=from_node.');
        }

        $seedFromArtifact = (bool) ($request->context['seed_from_artifact'] ?? false);
        $executionScope = $this->resolveDomainExecutionScope($request->executionScope);

        return $this->runner->runFromNodeId(
            $task,
            $context,
            $startNodeId,
            seedOutlineFromArticle: $seedFromArtifact,
            executionScope: $executionScope,
        );
    }

    /**
     * Map generic System WorkflowGraphScope wire values → domain WorkflowExecutionScope.
     * Unknown values fail closed (never silently downgrade to Full).
     */
    private function resolveDomainExecutionScope(?string $raw): WorkflowExecutionScope
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return WorkflowExecutionScope::Full;
        }

        $systemScope = WorkflowGraphScope::tryParse($trimmed);
        if ($systemScope === null) {
            throw new \InvalidArgumentException(
                "Unsupported workflow execution_scope «{$trimmed}».",
            );
        }

        return match ($systemScope) {
            WorkflowGraphScope::Full => WorkflowExecutionScope::Full,
            WorkflowGraphScope::OutlineVocabulary => WorkflowExecutionScope::OutlineVocabulary,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executeSingleStep(
        SeoTask $task,
        TaskTestContext $context,
        WorkflowRunRequest $request,
    ): array {
        $targetNodeId = trim((string) ($request->targetNodeId ?? ''));
        if ($targetNodeId === '') {
            throw new \InvalidArgumentException('target_node_id is required for execution_mode=single_step.');
        }

        $priorSteps = is_array($request->context['prior_steps'] ?? null)
            ? $request->context['prior_steps']
            : [];

        $step = $this->runner->runSingleStep(
            $task,
            $context,
            $targetNodeId,
            $priorSteps,
        );

        return [$step];
    }

    /**
     * Fail-closed ownership for remote/API and any request that asserts owner_user_id.
     *
     * @return null|array{code: string, message: string, owner_user_id?: int}
     */
    private function assertExecutionOwnership(WorkflowRunRequest $request, SeoTask $task): ?array
    {
        $viaHttp = (bool) ($request->context['via_http_api'] ?? false);
        $ownerUserId = (int) ($request->correlation['owner_user_id'] ?? $request->context['owner_user_id'] ?? 0);
        $taskOwnerId = (int) ($task->user_id ?? 0);

        if ($viaHttp && $ownerUserId <= 0) {
            return [
                'code' => 'owner_required',
                'message' => 'owner_user_id is required for Workflow API execution.',
                'owner_user_id' => 0,
            ];
        }

        if ($ownerUserId <= 0) {
            // Local in-process without asserted owner (e.g. console) — preserve BC.
            return null;
        }

        if ($taskOwnerId <= 0 || $taskOwnerId !== $ownerUserId) {
            return [
                'code' => 'owner_mismatch',
                'message' => 'SeoTask does not belong to the asserted execution owner.',
                'owner_user_id' => $ownerUserId,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $contextPayload
     * @return null|array{code: string, message: string}
     */
    private function assertArticleSiteOwnership(array $contextPayload, int $ownerUserId): ?array
    {
        if ($ownerUserId <= 0) {
            return null;
        }

        $articleId = (int) ($contextPayload['article_id'] ?? 0);
        $siteId = (int) ($contextPayload['site_id'] ?? 0);
        if ($articleId <= 0 && $siteId <= 0) {
            return null;
        }

        if ($articleId > 0) {
            $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($articleId);
            if ($article === null) {
                return [
                    'code' => 'article_not_found',
                    'message' => "Article #{$articleId} not found for asserted owner scope.",
                ];
            }
            $siteId = (int) ($article->site_id ?? $siteId);
        }

        if ($siteId <= 0) {
            return null;
        }

        $site = \App\Models\Site::query()->find($siteId);
        if ($site === null) {
            return [
                'code' => 'site_not_found',
                'message' => "Site #{$siteId} not found for asserted owner scope.",
            ];
        }

        if ((int) ($site->user_id ?? 0) !== $ownerUserId) {
            return [
                'code' => 'site_owner_mismatch',
                'message' => 'Site does not belong to the asserted execution owner.',
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function mapRunnerSteps(
        string $runId,
        array $steps,
        int $taskId,
        WorkflowExecutionMode $mode,
        string $source,
        ?string $executionScope = null,
        ?int $ownerUserId = null,
    ): WorkflowRunResult {
        $failed = 0;
        $artifacts = [];
        $mappedSteps = [];
        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            $nodeId = (string) ($step['node_id'] ?? ('step_'.$index));
            $status = (string) ($step['status'] ?? '');
            if ($status === 'failed') {
                $failed++;
            }
            $mappedSteps[$nodeId] = $step;
            if (
                array_key_exists('output', $step)
                || array_key_exists('outputs', $step)
                || isset($step['result_id'])
                || isset($step['prompt_result_id'])
            ) {
                $artifacts[$nodeId] = [
                    'output' => $step['output'] ?? null,
                    'outputs' => $step['outputs'] ?? null,
                    'prompt_result_id' => $step['prompt_result_id'] ?? ($step['result_id'] ?? null),
                ];
            }
        }

        return new WorkflowRunResult(
            id: $runId,
            status: $failed > 0 ? 'failed' : 'completed',
            steps: $mappedSteps,
            artifacts: $artifacts,
            meta: [
                'adapter' => 'legacy_seo_task',
                'definition_id' => $taskId,
                'runner' => TaskWorkflowTestRunner::class,
                'execution_mode' => $mode->value,
                'execution_scope' => $executionScope,
                'source' => $source,
                'step_count' => count($mappedSteps),
                'failed_count' => $failed,
                'owner_user_id' => $ownerUserId,
                'ordered_steps' => array_values(array_filter($steps, static fn (mixed $s): bool => is_array($s))),
            ],
            errorCode: $failed > 0 ? 'step_failures' : null,
            errorMessage: $failed > 0 ? "{$failed} workflow step(s) failed." : null,
        );
    }
}
