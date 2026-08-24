<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleExecutionHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionSnapshot;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionTrace;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryArtifactRef;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Read-only assembly: run → workflow definition → node executions → AI call mapping.
 */
final class ArticleExecutionHistoryService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptExecutionProfileResolver $profileResolver,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return list<array<string, mixed>>
     */
    public function build(SeoArticle $article, array $accessibleProjectIds): array
    {
        $articleId = (int) $article->getKey();
        if ($accessibleProjectIds === []) {
            return [];
        }

        $article->loadMissing(['site:id,domain']);

        $accessibleRunIds = SeoProjectRun::query()
            ->whereIn('project_id', $accessibleProjectIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($accessibleRunIds === []) {
            return [];
        }

        $runItems = SeoProjectRunItem::query()
            ->whereIn('run_id', $accessibleRunIds)
            ->where('article_id', $articleId)
            ->with(['run.project:id,name', 'run'])
            ->orderByDesc('id')
            ->get();

        if ($runItems->isEmpty()) {
            return [];
        }

        $runIds = $runItems->pluck('run_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $linksByRun = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->whereIn('project_run_id', $runIds)
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (SeoPromptResultLink $link): int => (int) ($link->project_run_id ?? 0));

        $resultIds = $linksByRun
            ->flatten(1)
            ->pluck('prompt_result_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($runItems as $item) {
            $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];
            foreach (is_array($output['execution_trace'] ?? null) ? $output['execution_trace'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (is_array($row['prompt_result_ids'] ?? null) ? $row['prompt_result_ids'] : [] as $rid) {
                    $id = (int) $rid;
                    if ($id > 0) {
                        $resultIds[] = $id;
                    }
                }
                $single = (int) ($row['result_id'] ?? 0);
                if ($single > 0) {
                    $resultIds[] = $single;
                }
            }
            foreach (is_array($output['steps'] ?? null) ? $output['steps'] : [] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $single = (int) ($step['result_id'] ?? 0);
                if ($single > 0) {
                    $resultIds[] = $single;
                }
            }
        }

        $results = PromptResult::query()
            ->with('prompt')
            ->whereIn('id', array_values(array_unique($resultIds)))
            ->get()
            ->keyBy(static fn (PromptResult $row): int => (int) $row->id);

        $runs = [];
        foreach ($runItems->groupBy(static fn (SeoProjectRunItem $item): int => (int) $item->run_id) as $runId => $items) {
            /** @var SeoProjectRunItem $primary */
            $primary = $items->sortByDesc(static fn (SeoProjectRunItem $item): int => (int) $item->id)->first();
            $run = $primary->run;
            if (! $run instanceof SeoProjectRun) {
                continue;
            }

            $workflow = $this->resolveWorkflowDefinition($run);
            $trace = $this->collectExecutionTrace($items);
            $hasFullTrace = $this->hasFullExecutionTrace($items);
            $links = $linksByRun->get((int) $runId, collect());
            $executionByNodeId = $this->buildExecutionByNodeId($workflow, $trace, $links, $results, $hasFullTrace);
            $workflowNodes = is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [];

            $runs[] = [
                'id' => 'run-'.$runId,
                'run_id' => (int) $runId,
                'run_item_id' => (int) $primary->id,
                'project_name' => trim((string) ($run->project?->name ?? '')),
                'status' => (string) $run->status,
                'attempt' => (int) ($primary->attempt ?? 1),
                'execution_type' => trim((string) (is_array($primary->input_snapshot) ? ($primary->input_snapshot['execution_type'] ?? 'first') : 'first')),
                'ran_at' => $primary->finished_at ?? $primary->started_at ?? $primary->created_at,
                'workflow' => $workflow,
                'execution_by_node_id' => $executionByNodeId,
                'node_visibility' => ExecutionHistoryNodeVisibility::classifyWorkflowNodes($workflowNodes, $executionByNodeId),
                'context_summary' => $this->buildContextSummary($article, $primary, $workflowNodes),
                'has_execution_trace' => $hasFullTrace,
                'legacy_unmapped' => $this->legacyUnmapped($trace, $workflow, $executionByNodeId),
            ];
        }

        return collect($runs)
            ->sortByDesc(static fn (array $row): int => $row['ran_at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $workflowNodes
     * @return array<string, mixed>
     */
    private function buildContextSummary(SeoArticle $article, SeoProjectRunItem $runItem, array $workflowNodes): array
    {
        $snapshot = is_array($runItem->input_snapshot) ? $runItem->input_snapshot : [];
        $site = $article->site;
        $domain = '';
        if ($site !== null) {
            $domain = trim((string) ($site->domain ?? ''));
        }

        $generationMode = trim((string) ($snapshot['type'] ?? ''));
        if ($generationMode === '') {
            $generationMode = trim((string) ($snapshot['execution_type'] ?? ''));
        }

        $postType = trim((string) ($snapshot['post_type'] ?? ''));
        $keyword = trim((string) ($snapshot['keyword'] ?? $snapshot['focus_keyword'] ?? ''));

        $filterSummary = $this->summarizeRoutingNodes($workflowNodes);

        $summary = [
            'article_id' => (int) $article->getKey(),
            'title' => trim((string) ($article->title ?? '')),
            'domain' => $domain !== '' ? $domain : null,
            'post_type' => $postType !== '' ? $postType : null,
            'generation_mode' => $generationMode !== '' ? $generationMode : null,
            'execution_type' => trim((string) ($snapshot['execution_type'] ?? '')) ?: null,
            'keyword' => $keyword !== '' ? $keyword : null,
            'routing' => $filterSummary !== [] ? $filterSummary : null,
        ];

        return array_filter(
            $summary,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $workflowNodes
     * @return list<string>
     */
    private function summarizeRoutingNodes(array $workflowNodes): array
    {
        $lines = [];
        foreach ($workflowNodes as $node) {
            if (! is_array($node) || ($node['type'] ?? '') !== 'article_filter') {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
            $postTypes = is_array($data['postTypes'] ?? null) ? $data['postTypes'] : [];
            $taxonomies = is_array($data['taxonomies'] ?? null) ? $data['taxonomies'] : [];
            if ($actions !== []) {
                $lines[] = 'actions: '.implode(', ', array_map('strval', $actions));
            }
            if ($postTypes !== []) {
                $lines[] = 'post_types: '.implode(', ', array_map('strval', $postTypes));
            }
            if ($taxonomies !== []) {
                $lines[] = 'taxonomies: '.implode(', ', array_map('strval', $taxonomies));
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @return array{task_id: ?int, task_name: ?string, definition_source: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function resolveWorkflowDefinition(SeoProjectRun $run): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $snapshotRaw = $settings['workflow_execution_snapshot'] ?? null;
        $snapshot = WorkflowExecutionSnapshot::tryFromArray(is_array($snapshotRaw) ? $snapshotRaw : null);

        $taskId = $this->settings->getPublishArticleTaskId();
        $task = $taskId !== null && $taskId > 0 ? SeoTask::query()->find($taskId) : null;
        $flow = $task instanceof SeoTask && is_array($task->flow_data) ? $task->flow_data : ['nodes' => [], 'edges' => []];
        $currentHash = WorkflowExecutionSnapshotBuilder::hashFlowData($flow);

        $definitionSource = 'legacy_current_task';
        if ($snapshot instanceof WorkflowExecutionSnapshot && $snapshot->flowDataHash === $currentHash) {
            $definitionSource = 'run_snapshot';
        } elseif ($snapshot instanceof WorkflowExecutionSnapshot) {
            $definitionSource = 'legacy_current_task_hash_mismatch';
        }

        return [
            'task_id' => $task instanceof SeoTask ? (int) $task->id : ($snapshot?->workflowId),
            'task_name' => $task instanceof SeoTask
                ? trim((string) ($task->name ?? ''))
                : ($snapshot?->workflowName),
            'definition_source' => $definitionSource,
            'flow_data_hash' => $currentHash,
            'snapshot_hash' => $snapshot?->flowDataHash,
            'nodes' => is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [],
            'edges' => is_array($flow['edges'] ?? null) ? $flow['edges'] : [],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectRunItem>  $items
     * @return list<array<string, mixed>>
     */
    private function collectExecutionTrace(\Illuminate\Support\Collection $items): array
    {
        $merged = [];
        foreach ($items->sortBy('id') as $item) {
            $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];
            $trace = is_array($output['execution_trace'] ?? null) ? $output['execution_trace'] : [];
            if ($trace !== []) {
                foreach ($trace as $row) {
                    if (is_array($row)) {
                        $merged[] = $row;
                    }
                }
                continue;
            }

            foreach (is_array($output['steps'] ?? null) ? $output['steps'] : [] as $step) {
                if (is_array($step)) {
                    $merged = array_merge($merged, WorkflowExecutionTrace::fromSteps([$step]));
                }
            }
        }

        return $merged;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoProjectRunItem>  $items
     */
    private function hasFullExecutionTrace(\Illuminate\Support\Collection $items): bool
    {
        foreach ($items as $item) {
            $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];
            $trace = is_array($output['execution_trace'] ?? null) ? $output['execution_trace'] : [];
            if ($trace !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  list<array<string, mixed>>  $trace
     * @param  \Illuminate\Support\Collection<int, SeoPromptResultLink>  $links
     * @param  \Illuminate\Support\Collection<int, PromptResult>  $results
     * @return array<string, array<string, mixed>>
     */
    private function buildExecutionByNodeId(
        array $workflow,
        array $trace,
        \Illuminate\Support\Collection $links,
        \Illuminate\Support\Collection $results,
        bool $hasFullTrace,
    ): array {
        $traceByNode = [];
        foreach ($trace as $row) {
            $nodeId = trim((string) ($row['node_id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $traceByNode[$nodeId] = $row;
        }

        $linksByNode = [];
        foreach ($links as $link) {
            $nodeId = trim((string) ($link->workflow_node_id ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $linksByNode[$nodeId][] = $link;
        }

        $overlay = [];
        foreach (is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [] as $defNode) {
            if (! is_array($defNode)) {
                continue;
            }
            $nodeId = trim((string) ($defNode['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }

            $type = trim((string) ($defNode['type'] ?? 'prompt'));
            $traceRow = $traceByNode[$nodeId] ?? null;
            $overlay[$nodeId] = $this->buildNodeExecutionRow(
                $nodeId,
                $type,
                $traceRow,
                $linksByNode[$nodeId] ?? [],
                $results,
                $hasFullTrace,
            );
            unset($traceByNode[$nodeId]);
        }

        foreach ($traceByNode as $nodeId => $traceRow) {
            if (! is_array($traceRow)) {
                continue;
            }
            $type = trim((string) ($traceRow['type'] ?? 'unknown'));
            $overlay[$nodeId] = $this->buildNodeExecutionRow(
                $nodeId,
                $type,
                $traceRow,
                $linksByNode[$nodeId] ?? [],
                $results,
                $hasFullTrace,
                mappingConfidence: 'legacy',
            );
        }

        return $overlay;
    }

    /**
     * @param  array<string, mixed>|null  $traceRow
     * @param  list<SeoPromptResultLink>  $nodeLinks
     * @return array<string, mixed>
     */
    private function buildNodeExecutionRow(
        string $nodeId,
        string $type,
        ?array $traceRow,
        array $nodeLinks,
        \Illuminate\Support\Collection $results,
        bool $hasFullTrace,
        string $mappingConfidence = 'workflow_node_id',
    ): array {
        if ($traceRow === null) {
            $status = $hasFullTrace ? 'not_reached' : 'unknown';
            $skipReason = null;
        } else {
            $status = $this->normalizeNodeStatus((string) ($traceRow['status'] ?? ''));
            $skipReason = $traceRow['skip_reason'] ?? null;
        }

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status, $skipReason),
            'skip_reason' => $skipReason,
            'skip_reason_label' => $this->skipReasonLabel($skipReason),
            'message' => is_array($traceRow) ? ($traceRow['message'] ?? null) : null,
            'hook_key' => is_array($traceRow) ? ($traceRow['hook_key'] ?? null) : null,
            'execution_role' => is_array($traceRow) ? ($traceRow['execution_role'] ?? null) : null,
            'ai_model' => is_array($traceRow) ? ($traceRow['ai_model'] ?? null) : null,
            'duration_ms' => is_array($traceRow) ? ($traceRow['duration_ms'] ?? null) : null,
            'action' => is_array($traceRow) ? ($traceRow['action'] ?? null) : null,
            'filter_type' => is_array($traceRow) ? ($traceRow['filter_type'] ?? null) : null,
            'type' => $type,
            'ai_calls' => $this->buildAiCallsForNode($nodeId, $traceRow, $nodeLinks, $results),
            'is_prompt' => $type === 'prompt',
            'has_prompt_result' => $this->nodeHasPromptResult($traceRow, $nodeLinks),
            'mapping_confidence' => $mappingConfidence,
            'run_item_id' => is_array($traceRow) ? ($traceRow['run_item_id'] ?? null) : null,
            'prompt_result_ids' => is_array($traceRow)
                ? array_values(array_filter(array_map(
                    static fn (mixed $id): int => (int) $id,
                    is_array($traceRow['prompt_result_ids'] ?? null) ? $traceRow['prompt_result_ids'] : [],
                ), static fn (int $id): bool => $id > 0))
                : [],
        ];
    }

    /**
     * Resolve display title from workflow node definition (never raw node id as primary).
     */
    public static function resolveNodeTitle(array $defNode): string
    {
        $title = trim((string) ($defNode['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $dataLabel = trim((string) ($defNode['data']['label'] ?? $defNode['data']['title'] ?? ''));

        return $dataLabel !== '' ? $dataLabel : trim((string) ($defNode['id'] ?? 'Node'));
    }

    /**
     * @param  array<string, mixed>|null  $traceRow
     * @param  list<SeoPromptResultLink>  $nodeLinks
     * @param  \Illuminate\Support\Collection<int, PromptResult>  $results
     * @return list<array<string, mixed>>
     */
    private function buildAiCallsForNode(
        string $nodeId,
        ?array $traceRow,
        array $nodeLinks,
        \Illuminate\Support\Collection $results,
    ): array {
        $calls = [];
        $seen = [];

        $candidateIds = [];
        if (is_array($traceRow)) {
            foreach (is_array($traceRow['prompt_result_ids'] ?? null) ? $traceRow['prompt_result_ids'] : [] as $rid) {
                $id = (int) $rid;
                if ($id > 0) {
                    $candidateIds[] = $id;
                }
            }
            $single = (int) ($traceRow['result_id'] ?? 0);
            if ($single > 0) {
                $candidateIds[] = $single;
            }
        }
        foreach ($nodeLinks as $link) {
            $candidateIds[] = (int) $link->prompt_result_id;
        }

        foreach (array_values(array_unique(array_filter($candidateIds))) as $resultId) {
            if (isset($seen[$resultId])) {
                continue;
            }
            $result = $results->get($resultId);
            if (! $result instanceof PromptResult) {
                continue;
            }
            $seen[$resultId] = true;
            $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            $hookKey = trim((string) ($snapshot['hook_key'] ?? $snapshot['variables']['hook_key'] ?? ''));
            $profile = $hookKey !== ''
                ? $this->profileResolver->resolve(null, $hookKey)->value
                : null;

            $subtask = trim((string) ($snapshot['outline_subtask'] ?? ''));
            $calls[] = [
                'result_id' => $resultId,
                'prompt_result_id' => $resultId,
                'artifact_ref' => ArticleAiHistoryArtifactRef::encodePromptResult($resultId),
                'prompt_name' => trim((string) ($result->prompt?->name ?? '')),
                'hook_key' => $hookKey !== '' ? $hookKey : null,
                'execution_profile' => $profile,
                'model' => trim((string) ($snapshot['raw_model_used'] ?? $snapshot['render_model'] ?? '')),
                'provider' => trim((string) ($snapshot['provider'] ?? '')),
                'status' => (string) $result->status,
                'outline_subtask' => $subtask !== '' ? $subtask : null,
                'mapping_confidence' => $nodeId !== '' ? 'workflow_node_id' : 'legacy',
                'route_position' => $snapshot['route_position'] ?? null,
                'is_free' => $snapshot['is_free'] ?? null,
            ];
        }

        usort($calls, static function (array $a, array $b): int {
            $order = ['outline' => 0, 'vocabulary' => 1];
            $aRank = $order[$a['outline_subtask'] ?? ''] ?? 99;
            $bRank = $order[$b['outline_subtask'] ?? ''] ?? 99;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return (int) ($a['result_id'] ?? 0) <=> (int) ($b['result_id'] ?? 0);
        });

        return $calls;
    }

    /**
     * @param  array<string, mixed>|null  $traceRow
     * @param  list<SeoPromptResultLink>  $nodeLinks
     */
    private function nodeHasPromptResult(?array $traceRow, array $nodeLinks): bool
    {
        if ($nodeLinks !== []) {
            return true;
        }
        if (! is_array($traceRow)) {
            return false;
        }
        if ((int) ($traceRow['result_id'] ?? 0) > 0) {
            return true;
        }

        return collect(is_array($traceRow['prompt_result_ids'] ?? null) ? $traceRow['prompt_result_ids'] : [])
            ->contains(static fn (mixed $id): bool => (int) $id > 0);
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     * @param  array<string, mixed>  $workflow
     * @param  array<string, array<string, mixed>>  $executionByNodeId
     * @return list<array<string, mixed>>
     */
    private function legacyUnmapped(array $trace, array $workflow, array $executionByNodeId): array
    {
        $workflowNodeIds = collect(is_array($workflow['nodes'] ?? null) ? $workflow['nodes'] : [])
            ->map(static fn (mixed $node): string => is_array($node) ? trim((string) ($node['id'] ?? '')) : '')
            ->filter()
            ->all();

        $unmapped = [];
        foreach ($trace as $row) {
            $nodeId = trim((string) ($row['node_id'] ?? ''));
            if ($nodeId !== '' && (isset($executionByNodeId[$nodeId]) || in_array($nodeId, $workflowNodeIds, true))) {
                continue;
            }
            if (($row['type'] ?? '') === 'prompt' && ! $this->nodeHasPromptResult($row, [])) {
                $unmapped[] = [
                    'title' => trim((string) ($row['title'] ?? 'Workflow step')),
                    'status' => 'skipped',
                    'status_label' => 'Skipped',
                    'message' => 'No AI request was stored.',
                ];
            }
        }

        return $unmapped;
    }

    private function normalizeNodeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'completed', 'success', 'ok' => 'completed',
            'failed', 'error' => 'failed',
            'skipped', 'blocked' => 'skipped',
            'running', 'processing', 'pending' => 'running',
            default => $status !== '' ? $status : 'unknown',
        };
    }

    private function statusLabel(string $status, mixed $skipReason): string
    {
        if ($status === 'skipped' && is_string($skipReason) && $skipReason !== '') {
            return 'Skipped';
        }

        return match ($status) {
            'completed' => 'Completed',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'running' => 'Running',
            'not_reached' => 'Not reached',
            'unknown' => 'Unknown / Legacy',
            default => ucfirst($status),
        };
    }

    private function skipReasonLabel(mixed $skipReason): ?string
    {
        if (! is_string($skipReason) || trim($skipReason) === '') {
            return null;
        }

        return match ($skipReason) {
            'not_reachable' => 'Not reachable from rerun start node',
            'upstream_failed' => 'Upstream node failed',
            'outline_failed' => 'Outline step failed',
            'content_artifact_missing' => 'Required content artifact missing',
            'SKIPPED_NOT_APPLICABLE' => 'Not applicable for this run',
            default => str_replace('_', ' ', $skipReason),
        };
    }
}
