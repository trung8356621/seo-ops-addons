<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestartGenerationWithKeywordCommand;
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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFreshKeywordWorkspaceResetService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use App\Support\RuntimeLogger;
use InvalidArgumentException;

final class RestartGenerationWithKeywordHandler extends AbstractPublishingHandler
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
        private readonly ContentProjectFreshKeywordWorkspaceResetService $workspaceReset,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof RestartGenerationWithKeywordCommand) {
            throw new InvalidArgumentException('Expected RestartGenerationWithKeywordCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived — fresh keyword restart blocked.',
                    $projectId,
                );
            }

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

            $keyword = trim($command->keyword);
            if ($keyword === '') {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Keyword is required.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Fresh keyword restart requires exactly one item.',
                    $projectId,
                );
            }

            if (count($itemIds) !== 1) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Fresh keyword restart supports one item at a time.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            $itemId = (int) $itemIds[0];

            $task = SeoProjectTask::query()->find($itemId);
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
                if ($capability->action === ContentProjectGenerationRecoveryDecision::ACTION_ACTIVE) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                        'Bài viết đang được tạo.',
                        $projectId,
                        metadata: [
                            'item_id' => $itemId,
                            'generation_recovery_action' => $capability->action,
                        ],
                    );
                }
            }

            $gate = $this->eligibility->validateFull($project, $itemIds);
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
                    metadata: ['rejected' => $gate['rejected']],
                );
            }
            $itemIds = $gate['eligible_ids'];

            if ($task instanceof SeoProjectTask) {
                $this->workspaceReset->resetForTask($task);
            }

            $launch = ContentProjectRunSettings::fromUserInput(
                is_array($command->settings) ? $command->settings : [],
            );
            $settings = array_merge($launch->toArray(), [
                'task_ids' => $itemIds,
                'rerun' => true,
                'rerun_scope' => 'full',
                'use_php_engine' => true,
                ContentProjectFreshKeywordRestart::SETTING_MODE => ContentProjectFreshKeywordRestart::MODE,
                ContentProjectFreshKeywordRestart::SETTING_KEYWORD => $keyword,
            ]);

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                    foreach ($itemIds as $lockedItemId) {
                        if ($this->eligibility->hasConflictingActiveExecution($projectId, (int) $lockedItemId)) {
                            throw new InvalidArgumentException(
                                'Active conflicting execution — fresh keyword restart blocked.',
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
                    'endpoint' => 'content_project.restart_with_keyword_engine_start',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                    'task_ids' => $itemIds,
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Fresh keyword restart queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'generation_mode' => ContentProjectFreshKeywordRestart::MODE,
                        'engine_started' => false,
                    ],
                );
            }

            RuntimeLogger::info('content_project.restart_with_keyword_started', [
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
            ]);

            SeoProjectTask::query()->whereIn('id', $itemIds)->update(['updated_at' => now()]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Fresh keyword restart started.',
                $projectId,
                $itemIds,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'generation_mode' => ContentProjectFreshKeywordRestart::MODE,
                    'generation_keyword_override' => $keyword,
                    'task_ids' => $itemIds,
                    'engine_started' => true,
                ],
            );
        });
    }
}
