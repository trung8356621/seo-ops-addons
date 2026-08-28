<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestartGenerationWithKeywordCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectGenerationRequested;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkGenerationPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveGenerationRunDetector;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\Agent\Extension\Resolvers\PipelineResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use App\Support\RuntimeLogger;
use InvalidArgumentException;
use RuntimeException;

final class GenerateProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly PipelineResolver $pipelineResolver,
        private readonly ContentProjectGenerationRecoveryService $generationRecovery,
        private readonly ContentProjectItemGenerationClassifier $classifier,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly ContentProjectActiveGenerationRunDetector $activeRuns,
        private readonly ContentProjectBulkGenerationPlanner $bulkPlanner,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ContentProjectGenerationCapabilityResolver $capability,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof GenerateProjectItemsCommand) {
            throw new InvalidArgumentException('Expected GenerateProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — generate blocked.',
                    $projectId,
                );
            }

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

            if (ContentProjectWriterAssignment::isUnassigned($project)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Please assign a writer before running this project.',
                    $projectId,
                    metadata: ['reason' => 'no_assignee'],
                );
            }

            try {
                $pipeline = $this->pipelineResolver->resolve('article');
                $validation = $pipeline->validate([
                    'project_id' => $projectId,
                    'mode' => $command->mode,
                ]);
                if (! ($validation['ok'] ?? false)) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        implode(' ', $validation['errors'] ?? ['Pipeline validation failed.']),
                        $projectId,
                    );
                }
            } catch (RuntimeException $e) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $e->getMessage(),
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            $this->generationRecovery->reconcileProject($project);
            $preview = $this->classifier->preview($project);

            if ($itemIds === []) {
                if ($preview->runCount() <= 0) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'No truly pending items to generate.',
                        $projectId,
                        metadata: ['preview' => $preview->toArray()],
                    );
                }
                $itemIds = $preview->runnableTaskIds();
            } else {
                $allowed = array_flip($preview->runnableTaskIds());
                $itemIds = array_values(array_filter(
                    $itemIds,
                    static fn (int $id): bool => isset($allowed[$id]),
                ));
                if ($itemIds === []) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Selected items are not eligible for generate-pending (already generated or blocked).',
                        $projectId,
                        metadata: ['preview' => $preview->toArray()],
                    );
                }
            }

            // «improve» is manual-only by default: generic generation must never enqueue AI for it.
            $allowImproveGeneration = (bool) ($command->settings[ContentProjectImproveManualOnlyGenerationGuard::ALLOW_IMPROVE_GENERATION_SETTING] ?? false);
            if (! $allowImproveGeneration && $itemIds !== []) {
                $typesById = SeoProjectTask::query()
                    ->whereIn('id', $itemIds)
                    ->pluck('type', 'id')
                    ->all();

                $guard = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds(
                    $itemIds,
                    $typesById,
                    allowImproveGeneration: false,
                );

                $itemIds = $guard['eligible_ids'];
                if ($itemIds === []) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Improve items are manual-only — AI generation is blocked.',
                        $projectId,
                        metadata: [
                            'skipped_improve_count' => $guard['skipped_improve_count'],
                            'skipped_improve_ids' => $guard['skipped_improve_ids'],
                        ],
                    );
                }
            }

            $isTestMode = $command->mode === SeoProjectRun::MODE_TEST;
            $isProjectLevelBulk = ! $isTestMode && ($command->itemRefs === [] || count($itemIds) > 1);
            if ($isTestMode && $this->activeRuns->hasActiveTestRun($projectId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Test run đang chạy.',
                    $projectId,
                    metadata: ['conflict' => 'test_run_active'],
                );
            }
            if ($isProjectLevelBulk && $this->activeRuns->hasActiveBulkGeneration($projectId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Đang có một bulk generation chạy.',
                    $projectId,
                    metadata: ['conflict' => 'bulk_generation_active'],
                );
            }

            $this->assertGenerateAllowed($itemIds);

            if ($command->itemRefs === [] && $preview->failClosed && ! $command->technicalConfirmFullRerun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Generate pending fail-closed: would select entire project despite historical execution.',
                    $projectId,
                    metadata: ['preview' => $preview->toArray()],
                );
            }

            $partition = $this->bulkPlanner->partition($preview, $itemIds);
            $generateIds = $partition['generate_ids'];
            $restartIds = $partition['restart_with_keyword_ids'];

            // Failed + resumable → Resume from failed step (reuse upstream); never-generated stay on generate.
            $resumePartition = $this->partitionResumableFailed($project, $generateIds);
            $generateIds = $resumePartition['generate_ids'];
            /** @var array<string, list<int>> $resumeByStep */
            $resumeByStep = $resumePartition['resume_by_step'];
            $resumeIds = $resumePartition['resume_ids'];

            if ($generateIds === [] && $restartIds === [] && $resumeIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No truly pending items to generate.',
                    $projectId,
                    metadata: ['preview' => $preview->toArray()],
                );
            }

            $runsStarted = [];
            $restartResults = [];
            $resumeResults = [];
            $allAffected = [];

            foreach ($resumeByStep as $fromStep => $stepTaskIds) {
                $resumeResult = $this->commandBus->dispatch(
                    new ResumeProjectItemFromFailedStepCommand(
                        $projectId,
                        $stepTaskIds,
                        $command->mode,
                    ),
                    $actor,
                );
                $resumeResults[] = array_merge($resumeResult->toArray(), [
                    'from_step' => $fromStep,
                    'task_ids' => $stepTaskIds,
                ]);
                if ($resumeResult->success) {
                    $allAffected = array_merge($allAffected, $stepTaskIds);
                    $ref = is_string($resumeResult->metadata['execution_ref'] ?? null)
                        ? (string) $resumeResult->metadata['execution_ref']
                        : null;
                    if ($ref !== null) {
                        $runsStarted[] = $ref;
                    }
                }
            }

            if ($generateIds !== []) {
                $generateResult = $this->dispatchNormalGenerate(
                    $project,
                    $projectId,
                    $command,
                    $generateIds,
                    is_array($command->settings) ? $command->settings : [],
                );
                if (! $generateResult['ok']) {
                    return $generateResult['result'];
                }
                $runsStarted[] = $generateResult['execution_ref'];
                $allAffected = array_merge($allAffected, $generateIds);
            }

            foreach ($restartIds as $restartTaskId) {
                $task = SeoProjectTask::query()->find((int) $restartTaskId);
                if (! $task instanceof SeoProjectTask) {
                    continue;
                }
                $keyword = ContentProjectGenerationKeyword::effective($task);
                if ($keyword === '') {
                    continue;
                }

                $restartResult = $this->commandBus->dispatch(
                    new RestartGenerationWithKeywordCommand(
                        $projectId,
                        [(int) $restartTaskId],
                        $keyword,
                        $command->mode,
                        $command->settings,
                    ),
                    $actor,
                );
                $restartResults[] = $restartResult->toArray();
                if ($restartResult->success) {
                    $allAffected[] = (int) $restartTaskId;
                    $ref = is_string($restartResult->metadata['execution_ref'] ?? null)
                        ? (string) $restartResult->metadata['execution_ref']
                        : null;
                    if ($ref !== null) {
                        $runsStarted[] = $ref;
                    }
                }
            }

            $failedRestarts = array_filter(
                $restartResults,
                static fn (array $row): bool => ! (bool) ($row['success'] ?? false),
            );
            $failedResumes = array_filter(
                $resumeResults,
                static fn (array $row): bool => ! (bool) ($row['success'] ?? false),
            );
            if ($generateIds === [] && $failedRestarts !== [] && $resumeIds === []) {
                $first = reset($failedRestarts);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    (string) ($first['message'] ?? 'Keyword restart failed.'),
                    $projectId,
                    affectedItemIds: $restartIds,
                    metadata: ['restart_results' => $restartResults],
                );
            }
            if ($generateIds === [] && $restartIds === [] && $failedResumes !== [] && $allAffected === []) {
                $first = reset($failedResumes);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    (string) ($first['message'] ?? 'Resume from failed step failed.'),
                    $projectId,
                    affectedItemIds: $resumeIds,
                    metadata: ['resume_results' => $resumeResults],
                );
            }

            RuntimeLogger::info('content_project.generate_started', [
                'project_id' => $projectId,
                'generate_task_ids' => $generateIds,
                'restart_task_ids' => $restartIds,
                'resume_task_ids' => $resumeIds,
                'resume_by_step' => $resumeByStep,
                'execution_refs' => $runsStarted,
            ]);

            $allAffected = array_values(array_unique($allAffected));
            if ($allAffected !== []) {
                SeoProjectTask::query()->whereIn('id', $allAffected)->update(['updated_at' => now()]);
            }

            $messageParts = [];
            if ($resumeIds !== []) {
                $messageParts[] = 'Resume from failed step started for '.count($resumeIds).' item(s).';
            }
            if ($generateIds !== []) {
                $messageParts[] = 'Generate pending started for '.count($generateIds).' item(s).';
            }
            if ($restartIds !== []) {
                $messageParts[] = 'Keyword restart started for '.count($restartIds).' item(s).';
            }

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                implode(' ', $messageParts),
                $projectId,
                $allAffected,
                metadata: [
                    'execution_ref' => $runsStarted[0] ?? null,
                    'execution_refs' => $runsStarted,
                    'generate_task_ids' => $generateIds,
                    'restart_task_ids' => $restartIds,
                    'resume_task_ids' => $resumeIds,
                    'resume_by_step' => $resumeByStep,
                    'restart_results' => $restartResults,
                    'resume_results' => $resumeResults,
                    'engine_started' => true,
                ],
            );
        });
    }

    /**
     * Split generate-pending IDs: resumable Failed → Resume; rest stay on full generate.
     *
     * @param  list<int>  $generateIds
     * @return array{
     *     generate_ids: list<int>,
     *     resume_ids: list<int>,
     *     resume_by_step: array<string, list<int>>,
     * }
     */
    private function partitionResumableFailed(SeoProject $project, array $generateIds): array
    {
        if ($generateIds === []) {
            return [
                'generate_ids' => [],
                'resume_ids' => [],
                'resume_by_step' => [],
            ];
        }

        $stillGenerate = [];
        $resumeByStep = [];
        $resumeIds = [];

        $tasks = SeoProjectTask::query()
            ->whereIn('id', $generateIds)
            ->with(['article'])
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->getKey());

        foreach ($generateIds as $rawId) {
            $taskId = (int) $rawId;
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $decision = $this->capability->decide($project, $task, [
                'recover_stale' => true,
                'persist_article_repair' => true,
            ]);

            $fromStep = trim((string) ($decision->resumableFromStep ?? ''));
            if (
                $decision->action === ContentProjectGenerationRecoveryDecision::ACTION_RESUME
                && $fromStep !== ''
            ) {
                $resumeByStep[$fromStep] ??= [];
                $resumeByStep[$fromStep][] = $taskId;
                $resumeIds[] = $taskId;
                continue;
            }

            $stillGenerate[] = $taskId;
        }

        return [
            'generate_ids' => array_values($stillGenerate),
            'resume_ids' => array_values(array_unique($resumeIds)),
            'resume_by_step' => $resumeByStep,
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @param  array<string, mixed>  $baseSettings
     * @return array{ok: bool, result?: ContentProjectActionResult, execution_ref?: string|null}
     */
    private function dispatchNormalGenerate(
        SeoProject $project,
        int $projectId,
        GenerateProjectItemsCommand $command,
        array $itemIds,
        array $baseSettings,
    ): array {
        $settings = array_merge(
            $baseSettings,
            [
                'task_ids' => $itemIds,
                'technical_confirm_full_rerun' => $command->technicalConfirmFullRerun,
                'use_php_engine' => true,
            ],
        );

        $run = $this->businessLock->withLock(
            $this->businessLock->projectGenerate($projectId),
            function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                $run = $this->workflowRunService->startRun($project, $command->mode, $settings);
                $limit = $command->mode === SeoProjectRun::MODE_TEST
                    ? SeoProjectWorkflowRunService::TEST_RUN_LIMIT
                    : null;
                $run = $this->workflowRunService->prepareRunQueue($project, $run, $limit);
                $executionRef = ContentProjectPublicRef::execution((int) $run->getKey());
                $this->domainEvents->dispatchAfterCommit(new ContentProjectGenerationRequested(
                    $projectId,
                    $executionRef,
                    $itemIds,
                ));

                return $run;
            },
        );

        try {
            $this->runEngine->start($run);
        } catch (\Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.generate_engine_start',
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
            ]);

            return [
                'ok' => false,
                'result' => ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Generate queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'task_ids' => $itemIds,
                        'engine_started' => false,
                    ],
                ),
            ];
        }

        return [
            'ok' => true,
            'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
        ];
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function assertGenerateAllowed(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('id', $itemIds)
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $this->actionGuard->assertCan(
                ContentProjectItemAction::Generate,
                $task,
                $task->relationLoaded('article') ? $task->article : null,
            );
        }
    }
}
