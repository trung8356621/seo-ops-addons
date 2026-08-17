<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectGenerationRequested;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use App\Support\RuntimeLogger;
use InvalidArgumentException;

final class RerunProjectItemStepHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ContentProjectGenerationRecoveryService $generationRecovery,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly ContentProjectRerunEligibilityGuard $eligibility,
        private readonly ContentProjectExistingArticleReconciler $articleReconciler,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RerunProjectItemStepCommand) {
            throw new InvalidArgumentException('Expected RerunProjectItemStepCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — step rerun blocked.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Step rerun requires explicit item selection.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            foreach ($itemIds as $itemId) {
                $task = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->find((int) $itemId);
                if ($task instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask) {
                    $this->generationRecovery->recoverTaskIfStale($task);
                    $this->articleReconciler->reconcileTask(
                        $task,
                        (int) ($project->site_id ?? 0) > 0 ? (int) $project->site_id : null,
                        persist: true,
                    );
                }
            }

            // Validate BEFORE any run / run_item / status mutation.
            $gate = $this->eligibility->validateStep(
                $project,
                $itemIds,
                $command->fromStep,
                $command->includeDownstream,
            );
            if (! $gate['ok']) {
                $rejectMessage = $gate['message'];
                $code = ContentProjectActionCodes::VALIDATION_FAILED;
                if (str_contains($rejectMessage, 'publishing is processing')
                    || str_contains($rejectMessage, 'Publish queue is active')
                ) {
                    $code = ContentProjectActionCodes::PUBLISHING_ALREADY_PROCESSING;
                } elseif (str_contains($rejectMessage, 'Active conflicting execution')
                    || str_contains($rejectMessage, 'Generation is running')
                ) {
                    $code = ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING;
                }

                return ContentProjectActionResult::fail(
                    $code,
                    $rejectMessage,
                    $projectId,
                    metadata: [
                        'rejected' => $gate['rejected'],
                        'rerun_from_step' => $command->fromStep->value,
                    ],
                );
            }

            $itemIds = $gate['eligible_ids'];
            $settings = [
                'task_ids' => $itemIds,
                'rerun' => true,
                'rerun_scope' => 'step',
                'rerun_from_step' => $command->fromStep->value,
                'rerun_include_downstream' => $command->includeDownstream,
                'rerun_sync' => $command->syncExecution,
                'use_php_engine' => true,
            ];
            if ($command->sourceArticleId !== null && $command->sourceArticleId > 0) {
                $settings['source_article_id'] = $command->sourceArticleId;
                $settings['article_id'] = $command->sourceArticleId;
            }

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                    // Re-check conflict inside lock.
                    foreach ($itemIds as $itemId) {
                        if ($this->eligibility->hasConflictingActiveExecution($projectId, (int) $itemId)) {
                            throw new InvalidArgumentException(
                                'Active conflicting execution — step rerun blocked.',
                            );
                        }
                    }

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
                    'endpoint' => 'content_project.rerun_step_engine_start',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                    'task_ids' => $itemIds,
                    'rerun_from_step' => $command->fromStep->value,
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Step rerun queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'engine_started' => false,
                        'rerun_from_step' => $command->fromStep->value,
                    ],
                );
            }

            RuntimeLogger::info('content_project.rerun_step_started', [
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
                'rerun_from_step' => $command->fromStep->value,
                'include_downstream' => $command->includeDownstream,
            ]);

            SeoProjectTask::query()->whereIn('id', $itemIds)->update(['updated_at' => now()]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Step rerun ('.$command->fromStep->value.') started for '.count($itemIds).' item(s).',
                $projectId,
                $itemIds,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'task_ids' => $itemIds,
                    'engine_started' => true,
                    'rerun_from_step' => $command->fromStep->value,
                    'rerun_include_downstream' => $command->includeDownstream,
                ],
            );
        });
    }
}
