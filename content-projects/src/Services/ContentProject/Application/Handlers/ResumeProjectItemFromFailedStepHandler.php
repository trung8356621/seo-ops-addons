<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use InvalidArgumentException;

/**
 * Canonical «Tiếp tục từ bước lỗi» — resolve failed step then step-rerun (no full graph).
 */
final class ResumeProjectItemFromFailedStepHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectFailedStepResumeResolver $resumeResolver,
        private readonly RerunProjectItemStepHandler $stepHandler,
        private readonly ContentProjectGenerationCapabilityResolver $capability,
        private readonly ContentProjectExistingArticleReconciler $articleReconciler,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ResumeProjectItemFromFailedStepCommand) {
            throw new InvalidArgumentException('Expected ResumeProjectItemFromFailedStepCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $draftBlock = ContentProjectDraftExecutionGuard::rejectIfDraft($project, $projectId);
            if ($draftBlock !== null) {
                return $draftBlock;
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Resume requires explicit item_refs — empty selection fail-closed.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            $plans = [];
            foreach ($itemIds as $itemId) {
                $task = SeoProjectTask::query()->find((int) $itemId);
                if (! $task instanceof SeoProjectTask) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Task #'.$itemId.' not found — resume fail-closed.',
                        $projectId,
                    );
                }

                $this->articleReconciler->reconcileTask(
                    $task,
                    (int) ($project->site_id ?? 0) > 0 ? (int) $project->site_id : null,
                    persist: true,
                );
                $task->refresh();

                $capability = $this->capability->decide($project, $task, [
                    'recover_stale' => true,
                    'persist_article_repair' => true,
                ]);
                if ($capability->action !== ContentProjectGenerationRecoveryDecision::ACTION_RESUME) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        $capability->reason !== ''
                            ? $capability->reason
                            : 'Resume not executable for current item state.',
                        $projectId,
                        metadata: [
                            'item_id' => (int) $itemId,
                            'generation_recovery_action' => $capability->action,
                            'existing_article_id' => $capability->existingArticleId,
                        ],
                    );
                }

                if (\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOutlineReviewCheckpoint::isWaitingReview($task)) {
                    \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOutlineReviewCheckpoint::clearPause($task);
                    $plans[(int) $itemId] = [
                        'ok' => true,
                        'from_step' => \Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep::Article,
                        'include_downstream' => false,
                        'resumed_from_step' => 'article',
                        'reused_steps' => ['outline'],
                        'invalidated_steps' => ['article'],
                        'failed_step_key' => null,
                        'run_item_id' => null,
                        'attempt' => null,
                        'message' => 'Resume Content after outline review checkpoint.',
                        'split_progress' => null,
                    ];

                    continue;
                }

                $plan = $this->resumeResolver->resolve($task);
                if (! ($plan['ok'] ?? false) || $plan['from_step'] === null) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        (string) ($plan['message'] ?? 'Cannot resolve failed step — resume fail-closed.'),
                        $projectId,
                        metadata: [
                            'item_id' => (int) $itemId,
                            'failed_step_key' => $plan['failed_step_key'] ?? null,
                        ],
                    );
                }
                $plans[(int) $itemId] = $plan;
            }

            $fromSteps = array_unique(array_map(
                static fn (array $p): string => (string) $p['from_step']->value,
                $plans,
            ));
            if (count($fromSteps) !== 1) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Mixed failed steps across items — resume one item at a time.',
                    $projectId,
                    metadata: ['from_steps' => array_values($fromSteps)],
                );
            }

            $firstPlan = $plans[(int) $itemIds[0]];
            $resumeSettings = is_array($command->settings) ? $command->settings : [];
            $resumeSettings['resume_partial_split'] = true;
            if (! empty($firstPlan['run_item_id'])) {
                $resumeSettings['resume_prior_run_item_id'] = (int) $firstPlan['run_item_id'];
            }
            if (is_array($firstPlan['split_progress'] ?? null)) {
                $resumeSettings['resume_split_progress'] = $firstPlan['split_progress'];
            }
            $stepResult = $this->stepHandler->handle(new RerunProjectItemStepCommand(
                $command->projectRef,
                $itemIds,
                $firstPlan['from_step'],
                (bool) $firstPlan['include_downstream'],
                null,
                $command->mode,
                false,
                $resumeSettings,
            ), $actor);

            $meta = is_array($stepResult->metadata) ? $stepResult->metadata : [];
            $meta['resumed_from_step'] = $firstPlan['resumed_from_step'];
            $meta['reused_steps'] = $firstPlan['reused_steps'];
            $meta['invalidated_steps'] = $firstPlan['invalidated_steps'];
            $meta['failed_step_key'] = $firstPlan['failed_step_key'];
            $meta['prior_run_item_id'] = $firstPlan['run_item_id'];
            $meta['prior_attempt'] = $firstPlan['attempt'];
            $meta['operation_id'] = $meta['execution_ref'] ?? null;
            $meta['new_attempt'] = true;
            if (isset($firstPlan['split_progress']) && is_array($firstPlan['split_progress'])) {
                $meta['split_progress'] = $firstPlan['split_progress'];
            }

            if (! $stepResult->success) {
                return ContentProjectActionResult::fail(
                    $stepResult->code,
                    $stepResult->message,
                    $projectId,
                    affectedItemIds: $itemIds,
                    metadata: $meta,
                );
            }

            $label = $firstPlan['from_step']->value === 'article' ? 'Viết bài' : 'Dàn ý';
            $message = 'Đã tiếp tục từ bước '.$label.'; reuse upstream: '.implode(', ', $firstPlan['reused_steps'] ?: ['—']).'.';
            if (is_array($firstPlan['split_progress'] ?? null) && ($firstPlan['split_progress']['resume_hint'] ?? '') !== '') {
                $message .= ' '.$firstPlan['split_progress']['resume_hint'];
            }

            return ContentProjectActionResult::ok(
                $stepResult->code,
                $message,
                $projectId,
                $itemIds,
                metadata: $meta,
            );
        });
    }
}
