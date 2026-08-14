<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use App\Support\RuntimeLogger;
use InvalidArgumentException;

final class RerunProjectItemsHandler extends AbstractPublishingHandler
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
        private readonly ContentProjectGenerationCapabilityResolver $capability,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RerunProjectItemsCommand) {
            throw new InvalidArgumentException('Expected RerunProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — rerun blocked.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds !== []) {
                $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            }

            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Rerun requires explicit item selection.',
                    $projectId,
                );
            }

            foreach ($itemIds as $itemId) {
                $task = SeoProjectTask::query()->find((int) $itemId);
                if ($task instanceof SeoProjectTask) {
                    $this->generationRecovery->recoverTaskIfStale($task);
                    $this->articleReconciler->reconcileTask(
                        $task,
                        (int) ($project->site_id ?? 0) > 0 ? (int) $project->site_id : null,
                        persist: true,
                    );
                    $task->refresh();
                    $capability = $this->capability->decide($project, $task, [
                        'recover_stale' => false,
                        'persist_article_repair' => true,
                    ]);
                    if (
                        ! in_array($capability->action, [
                            ContentProjectGenerationRecoveryDecision::ACTION_RERUN,
                            ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
                        ], true)
                    ) {
                        return ContentProjectActionResult::fail(
                            ContentProjectActionCodes::VALIDATION_FAILED,
                            $capability->reason !== ''
                                ? $capability->reason
                                : 'Rerun not executable for current item state.',
                            $projectId,
                            metadata: [
                                'item_id' => (int) $itemId,
                                'generation_recovery_action' => $capability->action,
                                'existing_article_id' => $capability->existingArticleId,
                            ],
                        );
                    }
                }
            }

            // Validate BEFORE startRun / prepareRunQueue / engine.
            $gate = $this->eligibility->validateFull($project, $itemIds);
            if (! $gate['ok']) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    $gate['message'],
                    $projectId,
                    metadata: ['rejected' => $gate['rejected']],
                );
            }
            $itemIds = $gate['eligible_ids'];

            // Merge user launch settings, then FORCE orchestration keys (caller cannot override).
            $launch = ContentProjectRunSettings::fromUserInput(
                is_array($command->settings) ? $command->settings : [],
            );
            $settings = array_merge($launch->toArray(), [
                'task_ids' => $itemIds,
                'rerun' => true,
                'rerun_scope' => 'full',
                'use_php_engine' => true,
            ]);

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                    foreach ($itemIds as $itemId) {
                        if ($this->eligibility->hasConflictingActiveExecution($projectId, (int) $itemId)) {
                            throw new InvalidArgumentException(
                                'Active conflicting execution — full rerun blocked.',
                            );
                        }
                    }

                    $run = $this->workflowRunService->startRun($project, $command->mode, $settings);
                    $limit = $command->mode === SeoProjectRun::MODE_TEST
                        ? SeoProjectWorkflowRunService::TEST_RUN_LIMIT
                        : null;
                    // Critical: startRun alone creates an empty run — queue + engine kick required.
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
                    'endpoint' => 'content_project.rerun_engine_start',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                    'task_ids' => $itemIds,
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Rerun queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'rerun' => true,
                        'engine_started' => false,
                    ],
                );
            }

            RuntimeLogger::info('content_project.rerun_started', [
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
            ]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Rerun started.',
                $projectId,
                $itemIds,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'rerun' => true,
                    'task_ids' => $itemIds,
                    'engine_started' => true,
                ],
            );
        });
    }
}
