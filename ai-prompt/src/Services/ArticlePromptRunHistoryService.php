<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Illuminate\Support\Collection;

final class ArticlePromptRunHistoryService
{
    /** @var list<string> */
    private const HIDDEN_SOURCES = [
        'workflow_run_backfill',
        'snapshot_backfill',
        'legacy_pivot_backfill',
    ];

    /**
     * Node workflow hệ thống — không phải prompt AI nội dung.
     *
     * @var list<string>
     */
    private const HIDDEN_STEP_TYPES = [
        'article',
        'article_filter',
        'filter',
        'action',
    ];

    /**
     * @param  list<int>  $accessibleProjectIds
     * @return list<array<string, mixed>>
     */
    public function build(SeoArticle $article, array $accessibleProjectIds): array
    {
        $articleId = (int) $article->getKey();

        $accessibleRunIds = SeoProjectRun::query()
            ->whereIn('project_id', $accessibleProjectIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $runsWithDbItems = $accessibleRunIds === []
            ? []
            : SeoProjectRunItem::query()
                ->whereIn('run_id', $accessibleRunIds)
                ->distinct()
                ->pluck('run_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        $runsWithDbSet = array_fill_keys($runsWithDbItems, true);

        $dbMatchedByRun = $accessibleRunIds === []
            ? collect()
            : SeoProjectRunItem::query()
                ->whereIn('run_id', $accessibleRunIds)
                ->where('article_id', $articleId)
                ->orderBy('id')
                ->get()
                ->groupBy(static fn (SeoProjectRunItem $item): int => (int) $item->run_id);

        $candidateRunIds = array_values(array_unique(array_merge(
            $dbMatchedByRun->keys()->map(static fn (mixed $id): int => (int) $id)->all(),
            // Legacy JSON chỉ cho run chưa có bất kỳ DB run item nào.
            array_values(array_filter(
                $accessibleRunIds,
                static fn (int $runId): bool => ! isset($runsWithDbSet[$runId]),
            )),
        )));

        $runModels = $candidateRunIds === []
            ? collect()
            : SeoProjectRun::query()
                ->with('project:id,name')
                ->whereIn('id', $candidateRunIds)
                ->latest('id')
                ->get()
                ->keyBy(static fn (SeoProjectRun $run): int => (int) $run->id);

        $runs = collect($candidateRunIds)
            ->map(function (int $runId) use ($articleId, $dbMatchedByRun, $runModels, $runsWithDbSet): ?array {
                $run = $runModels->get($runId);
                if (! $run instanceof SeoProjectRun) {
                    return null;
                }

                if (isset($runsWithDbSet[$runId])) {
                    $dbItems = $dbMatchedByRun->get($runId, collect());
                    if ($dbItems->isEmpty()) {
                        return null;
                    }

                    $matchingItems = $dbItems
                        ->map(static function (SeoProjectRunItem $item): array {
                            $output = is_array($item->output_snapshot) ? $item->output_snapshot : [];
                            $input = is_array($item->input_snapshot) ? $item->input_snapshot : [];

                            return [
                                'run_item_id' => (int) $item->id,
                                'task_id' => $item->task_id !== null ? (int) $item->task_id : 0,
                                'article_id' => $item->article_id !== null ? (int) $item->article_id : null,
                                'status' => (string) $item->status,
                                'action' => (string) ($item->action ?? ''),
                                'execution_type' => (string) ($input['execution_type'] ?? (
                                    str_starts_with((string) ($item->action ?? ''), 'step:rr:')
                                        ? 'rerun'
                                        : ((int) ($item->attempt ?? 1) > 1 ? 'retry' : 'first')
                                )),
                                'attempt' => (int) ($item->attempt ?? 1),
                                'target_node_id' => (string) ($input['target_node_id'] ?? $input['node_id'] ?? ''),
                                'target_execution_role' => $input['target_execution_role'] ?? null,
                                'step_label' => (string) ($input['step_label'] ?? ''),
                                'source_run_id' => $input['source_run_id'] ?? null,
                                'source_run_item_id' => $input['source_run_item_id'] ?? null,
                                'persist_status' => (string) ($output['persist_status'] ?? $input['persist_status'] ?? ''),
                                'created_at' => $item->created_at,
                                'steps' => is_array($output['steps'] ?? null) ? $output['steps'] : [],
                            ];
                        })
                        ->values();

                    return [
                        'run' => $run,
                        'items' => $matchingItems,
                        'source' => 'database',
                    ];
                }

                // Legacy fallback — chỉ khi run không có DB items.
                $matchingItems = collect(is_array($run->items) ? $run->items : [])
                    ->filter(
                        fn (mixed $item): bool => is_array($item)
                            && (int) ($item['article_id'] ?? 0) === $articleId,
                    )
                    ->values();

                if ($matchingItems->isEmpty()) {
                    return null;
                }

                return [
                    'run' => $run,
                    'items' => $matchingItems,
                    'source' => 'legacy_json',
                ];
            })
            ->filter()
            ->values();

        $linkedRows = SeoPromptResultLink::query()
            ->where('article_id', $articleId)
            ->where(function ($query) use ($accessibleRunIds): void {
                $query->whereNull('project_run_id');
                if ($accessibleRunIds !== []) {
                    $query->orWhereIn('project_run_id', $accessibleRunIds);
                }
            })
            ->orderBy('id')
            ->get();

        $resultIds = $runs
            ->flatMap(fn (array $entry): Collection => $entry['items'])
            ->flatMap(
                fn (array $item): array => array_values(array_filter(
                    is_array($item['steps'] ?? null) ? $item['steps'] : [],
                    'is_array',
                )),
            )
            ->flatMap(function (array $step): array {
                $ids = [(int) ($step['result_id'] ?? 0)];
                foreach (['outline_result_id', 'vocabulary_result_id'] as $key) {
                    $ids[] = (int) ($step[$key] ?? 0);
                }
                foreach (is_array($step['prompt_result_ids'] ?? null) ? $step['prompt_result_ids'] : [] as $rid) {
                    $ids[] = (int) $rid;
                }

                return $ids;
            })
            ->filter()
            ->unique()
            ->values();

        // Nhiều luồng editor chỉ lưu article_id trong input_snapshot, cần suy luận thêm từ JSON snapshot.
        $snapshotResults = PromptResult::query()
            ->with('prompt')
            ->where(function ($query) use ($articleId): void {
                $query
                    ->where('input_snapshot->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->article_id', $articleId)
                    ->orWhere('input_snapshot->variables->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->variables->article_id', $articleId);
            })
            ->orderBy('created_at')
            ->get();

        $resultIds = $resultIds
            ->merge($snapshotResults->pluck('id')->map(static fn (mixed $id): int => (int) $id))
            ->merge($linkedRows->pluck('prompt_result_id')->map(static fn (mixed $id): int => (int) $id))
            ->filter()
            ->unique()
            ->values();

        $results = PromptResult::query()
            ->with('prompt')
            ->whereIn('id', $resultIds)
            ->get()
            ->keyBy(fn (PromptResult $result): int => (int) $result->getKey());

        $seenResultIds = [];
        $seenRunItemIds = [];
        $seenLinkIds = [];
        $groups = $runs
            ->map(function (array $entry) use ($results, $linkedRows, &$seenResultIds, &$seenRunItemIds, &$seenLinkIds): ?array {
                /** @var SeoProjectRun $run */
                $run = $entry['run'];
                /** @var Collection<int, array<string, mixed>> $items */
                $items = $entry['items'];

                $prompts = $items
                    ->flatMap(function (array $item) use ($run, $results, &$seenResultIds, &$seenRunItemIds): array {
                        $runItemId = (int) ($item['run_item_id'] ?? 0);
                        if ($runItemId > 0) {
                            if (isset($seenRunItemIds[$runItemId])) {
                                return [];
                            }
                            $seenRunItemIds[$runItemId] = true;
                        }

                        $steps = array_values(array_filter(
                            is_array($item['steps'] ?? null) ? $item['steps'] : [],
                            'is_array',
                        ));

                        if ($steps === [] && (
                            trim((string) ($item['execution_type'] ?? '')) !== ''
                            || trim((string) ($item['step_label'] ?? '')) !== ''
                        )) {
                            $steps = [[
                                'type' => 'prompt',
                                'title' => (string) ($item['step_label'] ?? 'Workflow step'),
                                'status' => (string) ($item['status'] ?? ''),
                                'execution_role' => $item['target_execution_role'] ?? null,
                                'execution_type' => $item['execution_type'] ?? null,
                                'persist_status' => $item['persist_status'] ?? null,
                                'node_id' => $item['target_node_id'] ?? null,
                            ]];
                        }

                        return collect($steps)
                            ->filter(fn (array $step): bool => ! $this->isHiddenWorkflowStep($step))
                            ->flatMap(function (array $step, int $index) use ($item, $run, $results, &$seenResultIds): array {
                                $step['execution_type'] = $step['execution_type']
                                    ?? $item['execution_type']
                                    ?? null;
                                $step['persist_status'] = $step['persist_status']
                                    ?? $item['persist_status']
                                    ?? null;
                                $step['source_run_id'] = $item['source_run_id'] ?? null;
                                $step['source_run_item_id'] = $item['source_run_item_id'] ?? null;
                                $step['run_item_created_at'] = $item['created_at'] ?? null;
                                $step['run_item_id'] = $step['run_item_id'] ?? ($item['run_item_id'] ?? null);
                                $step['attempt'] = $step['attempt'] ?? ($item['attempt'] ?? null);

                                $children = $this->expandSplitChildSteps($step);
                                $normalizedChildren = [];
                                foreach ($children as $childIndex => $childStep) {
                                    $resultId = (int) ($childStep['result_id'] ?? 0);
                                    $result = $resultId > 0 ? $results->get($resultId) : null;
                                    if ($resultId > 0) {
                                        $seenResultIds[$resultId] = true;
                                    }

                                    $normalizedChildren[] = $this->normalizePromptItem(
                                        $childStep,
                                        $result instanceof PromptResult ? $result : null,
                                        (int) $run->id,
                                        (int) ($item['task_id'] ?? 0),
                                        ($index * 10) + $childIndex,
                                    );
                                }

                                return $normalizedChildren;
                            })
                            ->all();
                    })
                    ->values()
                    ->all();

                $runLinkedPrompts = $linkedRows
                    ->filter(fn (SeoPromptResultLink $link): bool => (int) ($link->project_run_id ?? 0) === (int) $run->id)
                    ->map(function (SeoPromptResultLink $link) use ($run, $results, &$seenResultIds, &$seenLinkIds): ?array {
                        $linkId = (int) $link->getKey();
                        if ($linkId > 0) {
                            if (isset($seenLinkIds[$linkId])) {
                                return null;
                            }
                            $seenLinkIds[$linkId] = true;
                        }

                        $source = trim((string) $link->source);
                        if (in_array($source, self::HIDDEN_SOURCES, true)) {
                            return null;
                        }

                        $resultId = (int) $link->prompt_result_id;
                        if ($resultId <= 0) {
                            return null;
                        }

                        if (isset($seenResultIds[$resultId])) {
                            return null;
                        }

                        $result = $results->get($resultId);
                        if (! $result instanceof PromptResult) {
                            return null;
                        }

                        $seenResultIds[$resultId] = true;

                        return $this->normalizePromptItem(
                            [
                                '_source' => $source,
                                'prompt_name' => (string) ($result->prompt?->name ?? ''),
                                'status' => (string) $result->status,
                                'output' => (string) ($result->output_text ?? ''),
                                'message' => (string) ($result->error_message ?? ''),
                            ],
                            $result,
                            (int) $run->id,
                            (int) ($link->project_task_id ?? 0),
                            0,
                        );
                    })
                    ->filter()
                    ->values()
                    ->all();

                $prompts = $this->finalizePromptList(
                    collect($prompts)->merge($runLinkedPrompts)->all(),
                );

                if ($prompts === []) {
                    return null;
                }

                $runAt = $run->started_at ?? $run->created_at;
                $latestPromptAt = $this->latestPromptTimestamp($prompts);

                return [
                    'id' => 'run-'.$run->id,
                    'run_id' => (int) $run->id,
                    'project_name' => trim((string) ($run->project?->name ?? '')),
                    'mode' => (string) $run->mode,
                    'status' => (string) $run->status,
                    'ran_at' => $latestPromptAt ?? $runAt,
                    'prompts' => $prompts,
                ];
            })
            ->filter()
            ->values();

        $linkedResults = $linkedRows
            ->map(fn (SeoPromptResultLink $link): ?PromptResult => $results->get((int) $link->prompt_result_id))
            ->filter(fn (mixed $result): bool => $result instanceof PromptResult)
            ->values();

        $articleLinkedResults = $snapshotResults
            ->merge($linkedResults)
            ->unique(fn (PromptResult $result): int => (int) $result->id)
            ->values();

        $orphanPrompts = $this->finalizePromptList(
            $articleLinkedResults
                ->filter(fn (PromptResult $result): bool => ! isset($seenResultIds[(int) $result->id]))
                ->map(function (PromptResult $result): array {
                    $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
                    $promptName = (string) ($result->prompt?->name ?? '');
                    if (($snapshot['generation_source'] ?? '') === 'product_review_template') {
                        $promptName = 'Review';
                    }

                    return $this->normalizePromptItem(
                        [
                            'prompt_name' => $promptName,
                            'status' => (string) $result->status,
                            'output' => (string) ($result->output_text ?? ''),
                            'message' => (string) ($result->error_message ?? ''),
                            'hook_key' => $snapshot['hook_key']
                                ?? ($snapshot['variables']['hook_key'] ?? null),
                        ],
                        $result,
                        0,
                        0,
                        0,
                    );
                })
                ->all(),
        );

        if ($orphanPrompts !== []) {
            $groups->push([
                'id' => 'article-prompts',
                'run_id' => null,
                'project_name' => '',
                'mode' => 'article',
                'status' => '',
                'ran_at' => $this->latestPromptTimestamp($orphanPrompts)
                    ?? $articleLinkedResults->max('created_at'),
                'prompts' => $orphanPrompts,
            ]);
        }

        return $groups
            ->filter(fn (array $group): bool => ($group['prompts'] ?? []) !== [])
            ->sortByDesc(fn (array $group): int => $group['ran_at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizePromptItem(
        array $step,
        ?PromptResult $result,
        int $runId,
        int $taskId,
        int $index,
    ): array {
        $snapshot = is_array($result?->input_snapshot) ? $result->input_snapshot : [];
        $compiledPrompt = trim((string) ($snapshot['compiled_prompt'] ?? ''));
        $promptTemplate = trim((string) ($result?->prompt?->markdown_content ?? ''));
        $fallbackInput = trim((string) ($step['input_used'] ?? ''));

        $prompt = $compiledPrompt !== ''
            ? $compiledPrompt
            : ($promptTemplate !== '' ? $promptTemplate : $fallbackInput);

        $output = trim((string) ($result?->output_text ?? ''));
        if ($output === '') {
            $output = trim((string) ($step['output'] ?? ''));
        }

        $type = trim((string) ($step['type'] ?? ''));
        $source = trim((string) ($step['_source'] ?? ''));
        $name = trim((string) ($step['prompt_name'] ?? $step['title'] ?? $result?->prompt?->name ?? ''));
        $displayType = $this->resolveDisplayType($type, $source, $name);

        $renderModel = trim((string) (
            $snapshot['render_model']
            ?? $step['render_model']
            ?? ''
        ));
        $plannerModel = trim((string) (
            $snapshot['planner_model']
            ?? $step['planner_model']
            ?? ''
        ));
        $validationModel = trim((string) ($snapshot['validation_model'] ?? $step['validation_model'] ?? ''));
        $workflowMode = trim((string) ($snapshot['workflow_execution_mode'] ?? $step['workflow_execution_mode'] ?? ''));

        // Media AI: ưu tiên snapshot render; không lấy step.ai_model (thường là planner category).
        if ($renderModel === '' && $this->isMediaAiHistory($displayType, $source, $snapshot)) {
            $renderModel = trim((string) ($snapshot['raw_model_used'] ?? ''));
        }

        if ($renderModel === '' && $plannerModel === '') {
            // Text path / legacy: raw_model_used; không ưu tiên step.ai_model cho media.
            $legacy = trim((string) ($snapshot['raw_model_used'] ?? ''));
            if ($legacy !== '') {
                if ($this->isMediaAiHistory($displayType, $source, $snapshot)) {
                    $renderModel = $legacy;
                } else {
                    $plannerModel = $legacy;
                }
            } elseif (! $this->isMediaAiHistory($displayType, $source, $snapshot)) {
                $plannerModel = trim((string) ($step['ai_model'] ?? ''));
            }
        }

        $primaryModel = $renderModel !== '' ? $renderModel : $plannerModel;

        $snapshotVariables = is_array($snapshot['variables'] ?? null)
            ? $snapshot['variables']
            : (is_array($snapshot) ? $snapshot : []);

        $debug = array_filter([
            'article_generation_source' => $snapshotVariables['article_generation_source'] ?? null,
            'article_writing_source_type' => $snapshotVariables['article_writing_source_type']
                ?? $snapshotVariables['source_type']
                ?? null,
            'source_type' => $snapshotVariables['source_type']
                ?? $snapshotVariables['article_writing_source_type']
                ?? null,
            'source_hash' => $snapshotVariables['source_hash'] ?? null,
            'source_run_id' => $snapshotVariables['source_run_id'] ?? null,
            'source_run_item_id' => $snapshotVariables['source_run_item_id'] ?? null,
            'source_prompt_result_id' => $snapshotVariables['source_prompt_result_id'] ?? null,
            'article_length' => $snapshotVariables['article_length'] ?? null,
            'actual_word_count' => $snapshotVariables['actual_word_count']
                ?? $snapshot['actual_word_count']
                ?? null,
            'minimum_acceptable_words' => $snapshotVariables['minimum_acceptable_words']
                ?? $snapshot['minimum_acceptable_words']
                ?? null,
            'target_article_length' => $snapshotVariables['target_article_length']
                ?? $snapshot['target_article_length']
                ?? null,
            'length_validation_result' => $snapshotVariables['length_validation_result']
                ?? $snapshot['length_validation_result']
                ?? null,
            'description_present' => $snapshotVariables['description_present'] ?? null,
            'outline_marker_found' => $snapshotVariables['outline_marker_found'] ?? null,
            'writing_instructions_marker_found' => $snapshotVariables['writing_instructions_marker_found'] ?? null,
            'artifact_version' => $snapshotVariables['artifact_version'] ?? null,
            'prompt_owner_type' => $snapshotVariables['prompt_owner_type'] ?? null,
            'prompt_owner_id' => $snapshotVariables['prompt_owner_id'] ?? null,
            'hook_key' => $snapshotVariables['hook_key'] ?? $step['hook_key'] ?? null,
            'workflow_node_title' => $snapshotVariables['workflow_node_title']
                ?? $step['title']
                ?? $step['prompt_name']
                ?? null,
            'execution_role' => $snapshotVariables['execution_role']
                ?? $step['execution_role']
                ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $sourceTypeRaw = trim((string) (
            $debug['article_writing_source_type']
            ?? $debug['source_type']
            ?? ''
        ));
        $sourceBadge = match ($sourceTypeRaw) {
            'outline' => 'Source: Outline',
            'existing_article' => 'Source: Existing article',
            'brief' => 'Source: Brief',
            default => null,
        };

        $ownerTypeRaw = trim((string) ($debug['prompt_owner_type'] ?? ''));
        $ownerBadge = match ($ownerTypeRaw) {
            'settings_binding' => 'Owner: Settings',
            'workflow_node' => 'Owner: Workflow',
            default => null,
        };

        $executionType = strtolower(trim((string) ($step['execution_type'] ?? '')));
        if ($executionType === '') {
            $executionType = 'first';
        }
        $executionTypeLabel = match ($executionType) {
            'rerun' => 'Chạy lại',
            'retry' => 'Thử lại',
            default => 'Lần chạy đầu',
        };

        $persistStatus = strtolower(trim((string) ($step['persist_status'] ?? '')));
        $rawStatus = strtolower(trim((string) ($step['status'] ?? $result?->status ?? '')));
        $uiStatus = match (true) {
            $persistStatus === 'ignored_stale' => 'Bỏ qua vì bài đã thay đổi',
            $rawStatus === 'blocked' || $persistStatus === 'blocked' => 'Bị chặn do bước trước lỗi',
            in_array($rawStatus, ['pending', 'processing', 'running'], true) => 'Đang chạy',
            in_array($rawStatus, ['failed', 'error'], true) => 'Lỗi',
            in_array($rawStatus, ['success', 'completed'], true) => 'Thành công',
            default => trim((string) ($step['status'] ?? $result?->status ?? '')),
        };

        return [
            'key' => $result !== null
                ? 'result-'.$result->id
                : sprintf('run-%d-task-%d-step-%d', $runId, $taskId, $index),
            'result_id' => $result?->id,
            'prompt_id' => (int) ($step['prompt_id'] ?? $result?->prompt_id ?? 0),
            'type' => $displayType,
            'prompt_name' => $name,
            'prompt' => $prompt,
            'result' => $output,
            'status' => $rawStatus,
            'status_label' => $uiStatus,
            'execution_type' => $executionType,
            'execution_type_label' => $executionTypeLabel,
            'message' => trim((string) ($step['message'] ?? $result?->error_message ?? '')),
            'model' => $primaryModel,
            'render_model' => $renderModel,
            'planner_model' => $plannerModel,
            'validation_model' => $validationModel,
            'workflow_execution_mode' => $workflowMode,
            'candidate_count' => $snapshot['candidate_count'] ?? null,
            'winner_score' => $snapshot['winner_score'] ?? null,
            'validation_passed' => $snapshot['validation_passed'] ?? null,
            'ran_at' => $result?->started_at ?? $result?->created_at ?? ($step['run_item_created_at'] ?? null),
            'variables' => $debug !== [] ? $debug : null,
            'source_badge' => $sourceBadge,
            'owner_badge' => $ownerBadge,
            'prompt_owner_type' => $ownerTypeRaw !== '' ? $ownerTypeRaw : null,
            'prompt_owner_id' => $debug['prompt_owner_id'] ?? null,
            'hook_key' => $debug['hook_key'] ?? null,
            'workflow_node_title' => $debug['workflow_node_title'] ?? null,
            'execution_role' => $debug['execution_role'] ?? null,
            'outline_subtask' => $this->trimmedOrNull($step['outline_subtask'] ?? $snapshot['outline_subtask'] ?? null),
            'execution_sequence' => isset($step['execution_sequence']) && is_numeric($step['execution_sequence'])
                ? (int) $step['execution_sequence']
                : null,
            'article_length' => $debug['article_length'] ?? null,
            'actual_word_count' => $debug['actual_word_count'] ?? null,
            'minimum_acceptable_words' => $debug['minimum_acceptable_words'] ?? null,
            'target_article_length' => $debug['target_article_length'] ?? null,
            'length_validation_result' => $debug['length_validation_result'] ?? null,
            'article_writing_source_type' => $sourceTypeRaw !== '' ? $sourceTypeRaw : null,
            'article_generation_source' => $debug['article_generation_source'] ?? null,
            'source_run_id' => $debug['source_run_id'] ?? null,
            'source_run_item_id' => $debug['source_run_item_id'] ?? null,
            'outline_marker_found' => $debug['outline_marker_found'] ?? null,
            'writing_instructions_marker_found' => $debug['writing_instructions_marker_found'] ?? null,
            'artifact_version' => $debug['artifact_version'] ?? null,
            // Article AI History application layer — Article AI History reads these
            // to build stable artifact refs and fail-closed classification.
            'artifact_type' => $this->trimmedOrNull($step['artifact_type'] ?? null),
            'outline_markdown' => $this->trimmedOrNull($step['outline_markdown'] ?? null),
            'persists_as_outline' => (bool) ($step['persists_as_outline'] ?? false),
            'run_item_id' => isset($step['run_item_id']) && $step['run_item_id'] !== null ? (int) $step['run_item_id'] : null,
            'step_index' => $index,
            'attempt' => isset($step['attempt']) && $step['attempt'] !== null ? (int) $step['attempt'] : null,
        ];
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function isHiddenWorkflowStep(array $step): bool
    {
        $type = strtolower(trim((string) ($step['type'] ?? '')));
        if (in_array($type, self::HIDDEN_STEP_TYPES, true)) {
            return true;
        }

        $name = strtolower(trim((string) ($step['prompt_name'] ?? $step['title'] ?? '')));

        return in_array($name, self::HIDDEN_STEP_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isHiddenPromptItem(array $item): bool
    {
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        if (in_array($type, self::HIDDEN_STEP_TYPES, true)) {
            return true;
        }

        $name = strtolower(trim((string) ($item['prompt_name'] ?? '')));

        return in_array($name, self::HIDDEN_STEP_TYPES, true);
    }

    /**
     * Expand aggregate outline+vocabulary split steps into child cards so
     * Outline never displays Vocabulary errors (and vice versa).
     *
     * @param  array<string, mixed>  $step
     * @return list<array<string, mixed>>
     */
    private function expandSplitChildSteps(array $step): array
    {
        $outlineId = (int) ($step['outline_result_id'] ?? 0);
        $vocabId = (int) ($step['vocabulary_result_id'] ?? 0);
        $ids = array_values(array_filter(
            is_array($step['prompt_result_ids'] ?? null) ? $step['prompt_result_ids'] : [],
            static fn (mixed $id): bool => (int) $id > 0,
        ));

        $isSplit = $outlineId > 0
            || $vocabId > 0
            || count($ids) > 1
            || in_array((string) ($step['execution_source'] ?? ''), ['split_outline_vocabulary'], true)
            || str_contains(strtolower((string) ($step['hook_key'] ?? '')), 'outline.structure');

        if (! $isSplit) {
            if (! isset($step['execution_sequence'])) {
                $step['execution_sequence'] = 10;
            }

            return [$step];
        }

        if ($outlineId <= 0 && $vocabId <= 0 && count($ids) >= 2) {
            $outlineId = (int) $ids[0];
            $vocabId = (int) $ids[1];
        }

        $primaryId = (int) ($step['result_id'] ?? 0);
        $outlineSubtask = strtolower(trim((string) ($step['outline_subtask'] ?? '')));
        $aggregateMessage = trim((string) ($step['message'] ?? ''));
        $vocabFailed = $outlineSubtask === 'vocabulary_failed'
            || str_contains(strtolower($aggregateMessage), 'vocabulary');

        // Legacy bug: vocab fail stored outline_result_id=result_id and left vocabulary_result_id null.
        if ($vocabId <= 0 && $vocabFailed && $primaryId > 0 && $primaryId !== $outlineId) {
            $vocabId = $primaryId;
        }
        if ($outlineId <= 0 && $vocabFailed && $primaryId > 0 && count($ids) === 1) {
            // Only one id and vocab failed → that id is usually outline success in older payloads.
            $outlineId = $primaryId;
        }

        $baseTitle = trim((string) ($step['title'] ?? $step['prompt_name'] ?? 'Workflow step'));
        $baseTitle = preg_replace('/\s*[—-]\s*Outline\s*$/u', '', $baseTitle) ?? $baseTitle;
        $children = [];

        if ($outlineId > 0 || ($vocabFailed && trim((string) ($step['outline_status'] ?? '')) === 'completed')) {
            if ($outlineId <= 0) {
                $outlineId = $primaryId;
            }
            $outlineStatus = trim((string) ($step['outline_status'] ?? ''));
            if ($outlineStatus === '') {
                $outlineStatus = $vocabFailed ? 'completed' : (string) ($step['status'] ?? '');
            }
            $outlineMessage = trim((string) ($step['outline_message'] ?? ''));
            if (in_array(strtolower($outlineStatus), ['completed', 'success'], true)) {
                $outlineMessage = '';
            } elseif ($outlineMessage === '' && strtolower($outlineStatus) === 'failed') {
                $msg = $aggregateMessage;
                if (! str_contains(strtolower($msg), 'vocabulary')) {
                    $outlineMessage = $msg;
                }
            } elseif (str_contains(strtolower($outlineMessage), 'vocabulary')) {
                $outlineMessage = '';
            }

            $children[] = array_merge($step, [
                'result_id' => $outlineId > 0 ? $outlineId : null,
                'title' => $baseTitle.' — Outline',
                'prompt_name' => $baseTitle.' — Outline',
                'status' => $outlineStatus !== '' ? $outlineStatus : (string) ($step['status'] ?? ''),
                'message' => $outlineMessage,
                'outline_subtask' => 'outline',
                'execution_sequence' => 1,
                'hook_key' => $step['hook_key'] ?? 'article.outline.structure.generate',
            ]);
        }

        if ($vocabId > 0 || $vocabFailed) {
            $vocabStatus = trim((string) ($step['vocabulary_status'] ?? ''));
            if ($vocabStatus === '') {
                $vocabStatus = $vocabFailed ? 'failed' : (string) ($step['status'] ?? '');
            }
            $vocabMessage = trim((string) ($step['vocabulary_message'] ?? ''));
            if ($vocabMessage === '' && strtolower($vocabStatus) === 'failed') {
                $vocabMessage = $aggregateMessage;
            }

            $children[] = array_merge($step, [
                'result_id' => $vocabId > 0 ? $vocabId : null,
                'title' => $baseTitle.' — Vocabulary',
                'prompt_name' => $baseTitle.' — Vocabulary',
                'status' => $vocabStatus !== '' ? $vocabStatus : (string) ($step['status'] ?? ''),
                'message' => $vocabMessage,
                'outline_subtask' => 'vocabulary',
                'execution_sequence' => 2,
                'hook_key' => 'article.vocabulary.generate',
            ]);
        }

        if ($children === []) {
            if (! isset($step['execution_sequence'])) {
                $step['execution_sequence'] = 10;
            }

            return [$step];
        }

        return $children;
    }

    /**
     * Ẩn node hệ thống. Trong cùng run/attempt: ASC theo execution_sequence
     * (Outline → Vocabulary → Writer). Không sort chỉ bằng created_at DESC.
     *
     * @param  list<array<string, mixed>>  $prompts
     * @return list<array<string, mixed>>
     */
    private function finalizePromptList(array $prompts): array
    {
        return collect($prompts)
            ->filter(fn (array $item): bool => ! $this->isHiddenPromptItem($item))
            ->sort(function (array $a, array $b): int {
                $attemptA = (int) ($a['attempt'] ?? 0);
                $attemptB = (int) ($b['attempt'] ?? 0);
                if ($attemptA !== $attemptB) {
                    // Newer attempts first within a run group.
                    return $attemptB <=> $attemptA;
                }

                $seqA = $a['execution_sequence'] ?? null;
                $seqB = $b['execution_sequence'] ?? null;
                $hasSeqA = is_numeric($seqA);
                $hasSeqB = is_numeric($seqB);
                if ($hasSeqA && $hasSeqB && (int) $seqA !== (int) $seqB) {
                    return (int) $seqA <=> (int) $seqB;
                }
                if ($hasSeqA !== $hasSeqB) {
                    return $hasSeqA ? -1 : 1;
                }

                $subOrder = ['outline' => 0, 'vocabulary' => 1];
                $subA = $subOrder[strtolower((string) ($a['outline_subtask'] ?? ''))] ?? 50;
                $subB = $subOrder[strtolower((string) ($b['outline_subtask'] ?? ''))] ?? 50;
                if ($subA !== $subB) {
                    return $subA <=> $subB;
                }

                $stepA = (int) ($a['step_index'] ?? 0);
                $stepB = (int) ($b['step_index'] ?? 0);
                if ($stepA !== $stepB) {
                    return $stepA <=> $stepB;
                }

                // Fallback: oldest first when no explicit sequence.
                $tsA = $a['ran_at']?->getTimestamp() ?? 0;
                $tsB = $b['ran_at']?->getTimestamp() ?? 0;

                return $tsA <=> $tsB;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $prompts
     */
    private function latestPromptTimestamp(array $prompts): mixed
    {
        $latest = null;
        $latestTs = 0;
        foreach ($prompts as $item) {
            $ranAt = $item['ran_at'] ?? null;
            $ts = is_object($ranAt) && method_exists($ranAt, 'getTimestamp')
                ? (int) $ranAt->getTimestamp()
                : 0;
            if ($ts > $latestTs) {
                $latestTs = $ts;
                $latest = $ranAt;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isMediaAiHistory(string $displayType, string $source, array $snapshot): bool
    {
        if ($source === 'editor_media_generation' || $displayType === 'Media AI') {
            return true;
        }

        $tools = strtolower(trim((string) ($snapshot['tools'] ?? '')));

        return in_array($tools, ['image', 'image_typography'], true)
            || ! empty($snapshot['direct_image_preview'])
            || filled($snapshot['render_model'] ?? null);
    }

    private function resolveDisplayType(string $type, string $source, string $name): string
    {
        if ($type !== '' && $type !== 'prompt') {
            return $type;
        }

        return match ($source) {
            'editor_media_generation' => 'Media AI',
            'quick_review_workflow' => 'Review AI',
            'workflow_run',
            'workflow_run_failed' => $name !== '' ? $name : 'Prompt AI',
            default => $name !== '' ? $name : 'Prompt AI',
        };
    }
}
