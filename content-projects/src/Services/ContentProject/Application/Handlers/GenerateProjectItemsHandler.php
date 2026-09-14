<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveGenerationRunDetector;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\Agent\Extension\Resolvers\PipelineResolver;
use App\Support\RuntimeLogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bulk generate: one SeoProjectRun envelope + lazy per-item JIT decisions.
 * Does not partition into resume/restart child runs.
 */
final class GenerateProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly PipelineResolver $pipelineResolver,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly ContentProjectActiveGenerationRunDetector $activeRuns,
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

            $itemIds = $this->resolveVisitTaskIds($project, $command);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No items to visit for bulk generation.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            $isTestMode = $command->mode === SeoProjectRun::MODE_TEST;
            // Project-level concurrency: any active bulk blocks another bulk OR single-item generate
            // for the same project (max 1 AI article executing per project bulk).
            if ($isTestMode && $this->activeRuns->hasActiveTestRun($projectId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Test run đang chạy.',
                    $projectId,
                    metadata: ['conflict' => 'test_run_active'],
                );
            }
            if ($this->activeRuns->hasActiveBulkGeneration($projectId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Đang có một bulk generation chạy.',
                    $projectId,
                    metadata: ['conflict' => 'bulk_generation_active'],
                );
            }

            // technicalConfirmFullRerun ignored (deprecated) — whole-project visit is safe under JIT.

            $baseSettings = is_array($command->settings) ? $command->settings : [];
            $settings = array_merge($baseSettings, [
                'task_ids' => $itemIds,
                'lazy_bulk' => true,
                'use_php_engine' => true,
            ]);
            // Do not persist obsolete gate flag into run settings.
            unset($settings['technical_confirm_full_rerun']);

            $run = $this->businessLock->withLock(
                $this->businessLock->projectGenerate($projectId),
                function () use ($project, $projectId, $command, $itemIds, $settings): SeoProjectRun {
                    if ($this->activeRuns->hasActiveBulkGeneration($projectId)) {
                        throw new InvalidArgumentException('Đang có một bulk generation chạy.');
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
                        'execution_refs' => [ContentProjectPublicRef::execution((int) $run->getKey())],
                        'task_ids' => $itemIds,
                        'engine_started' => false,
                    ],
                );
            }

            $executionRef = ContentProjectPublicRef::execution((int) $run->getKey());

            RuntimeLogger::info('content_project.generate_started', [
                'project_id' => $projectId,
                'task_ids' => $itemIds,
                'execution_ref' => $executionRef,
                'execution_refs' => [$executionRef],
                'lazy_bulk' => true,
            ]);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_GENERATE_REQUESTED,
                'Bulk generation started for '.count($itemIds).' item(s) (lazy JIT).',
                $projectId,
                // Do not touch task updated_at / lifecycle — membership only.
                [],
                metadata: [
                    'execution_ref' => $executionRef,
                    'execution_refs' => [$executionRef],
                    'task_ids' => $itemIds,
                    'lazy_bulk' => true,
                    'engine_started' => true,
                ],
            );
        });
    }

    /**
     * Snapshot visit order only — not an execution plan.
     *
     * @return list<int>
     */
    private function resolveVisitTaskIds(SeoProject $project, GenerateProjectItemsCommand $command): array
    {
        $explicit = $this->resolveItemIds($command->itemRefs);
        if ($explicit !== []) {
            return $this->orderTaskIds($project, $explicit);
        }

        $query = $project->tasks()
            ->planned()
            ->orderBy('target_date')
            ->orderBy('id');

        if ($command->mode === SeoProjectRun::MODE_TEST) {
            $query->limit(SeoProjectWorkflowRunService::TEST_RUN_LIMIT);
        }

        /** @var list<int> $ids */
        $ids = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $allowImprove = (bool) ($command->settings[ContentProjectImproveManualOnlyGenerationGuard::ALLOW_IMPROVE_GENERATION_SETTING] ?? false);
        if ($allowImprove || $ids === []) {
            return $ids;
        }

        // Soft-exclude improve from visit list when known at launch (JIT also skips).
        $typesById = SeoProjectTask::query()
            ->whereIn('id', $ids)
            ->pluck('type', 'id')
            ->all();
        $guard = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds($ids, $typesById, false);

        return $guard['eligible_ids'];
    }

    /**
     * Preserve caller order when explicit; otherwise project planner order.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function orderTaskIds(SeoProject $project, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ordered = $project->tasks()
            ->planned()
            ->whereIn('id', $ids)
            ->orderBy('target_date')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ordered);
    }
}
