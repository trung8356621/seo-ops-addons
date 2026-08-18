<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectGenerationRequested;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\Agent\Extension\Resolvers\PipelineResolver;
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

            $this->assertGenerateAllowed($itemIds);

            if ($preview->failClosed && ! $command->technicalConfirmFullRerun) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Generate pending fail-closed: would select entire project despite historical execution.',
                    $projectId,
                    metadata: ['preview' => $preview->toArray()],
                );
            }

            $settings = array_merge(
                $command->settings,
                [
                    'task_ids' => $itemIds,
                    'technical_confirm_full_rerun' => $command->technicalConfirmFullRerun,
                    'use_php_engine' => true,
                    AiCostPolicy::SETTING_KEY => AiCostPolicy::FreeOnly->value,
                ],
            );

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
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
                // Idempotent: ContentProjectRunEngine::start skips duplicate dispatch when already live.
                $this->runEngine->start($run);
            } catch (\Throwable $e) {
                RuntimeLogger::report($e, [
                    'endpoint' => 'content_project.generate_engine_start',
                    'project_id' => $projectId,
                    'run_id' => (int) $run->getKey(),
                    'task_ids' => $itemIds,
                ]);

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    'Generate queue prepared but engine start failed: '.$e->getMessage(),
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: [
                        'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                        'task_ids' => $itemIds,
                        'engine_started' => false,
                    ],
                );
            }

            RuntimeLogger::info('content_project.generate_started', [
                'project_id' => $projectId,
                'run_id' => (int) $run->getKey(),
                'task_ids' => $itemIds,
            ]);

            SeoProjectTask::query()->whereIn('id', $itemIds)->update(['updated_at' => now()]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Generate pending started for '.count($itemIds).' item(s).',
                $projectId,
                $itemIds,
                metadata: [
                    'execution_ref' => ContentProjectPublicRef::execution((int) $run->getKey()),
                    'task_ids' => $itemIds,
                    'engine_started' => true,
                ],
            );
        });
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
