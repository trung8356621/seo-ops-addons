<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedEvidence;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategy;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategyResolver;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemOutcome;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService;
use InvalidArgumentException;

/**
 * Content Project → Publishing Queue handoff. No WordPress. No auto schedule.
 */
final class SendToPublishingQueueHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queueService,
        private readonly PublishDueItemService $dueItemService,
        private readonly ContentPublishingStrategyResolver $strategyResolver = new ContentPublishingStrategyResolver,
        private readonly ArticleWordPressSyncFlagService $syncFlags = new ArticleWordPressSyncFlagService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SendToPublishingQueueCommand) {
            throw new InvalidArgumentException('Expected SendToPublishingQueueCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if (! SeoAccessControl::canManageContentProjectWorkflow()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Content Manager cannot send items to Publishing Queue.',
                );
            }

            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }
            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);

            $tasks = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $itemIds)
                ->whereNull('archived_at')
                ->with(['article.wordpressLink', 'article.articleMetas'])
                ->get();

            $eligible = [];
            $warnings = [];
            foreach ($tasks as $task) {
                $article = $task->relationLoaded('article') ? $task->article : null;
                $seoArticle = $article instanceof SeoArticle ? $article : null;
                $type = SeoProjectTask::normalizeType($task->type);
                $row = [
                    'article_id' => (int) ($task->article_id ?? 0),
                    'type' => $type,
                    'is_improve' => $type === SeoProjectTask::TYPE_IMPROVE,
                    'publishing_queued_at' => $task->publishing_queued_at?->toIso8601String(),
                    'in_publishing_queue' => $task->publishing_queued_at !== null,
                    'generation_status' => (string) ($task->status ?? ''),
                    'execution_status' => '',
                    'generation_completed_at' => $task->completed_at?->toIso8601String(),
                    'content_manager_reviewed_at' => $task->content_manager_reviewed_at?->toIso8601String(),
                    'is_content_manager_reviewed' => $task->content_manager_reviewed_at !== null,
                    'lifecycle' => '',
                    'queue_status' => (string) ($task->publish_queue_status ?? 'none'),
                    'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                    'observed_post_status' => ContentProjectPublishedEvidence::resolveObservedPostStatus($seoArticle),
                    'has_unpublished_changes' => $seoArticle instanceof SeoArticle
                        && $this->syncFlags->hasUnpublishedChanges($seoArticle),
                    'is_genuinely_running' => in_array((string) $task->status, [
                        SeoProjectTask::STATUS_WRITING,
                        SeoProjectTask::STATUS_PROCESSING,
                    ], true),
                ];
                if (! PublishingQueueHandoffEligibility::canSend($row)) {
                    continue;
                }
                $eligible[] = (int) $task->getKey();
                if (PublishingQueueHandoffEligibility::needsContentManagerWarning($row)) {
                    $warnings[] = 'item_'.$task->getKey().'_needs_review_unmarked';
                }
            }

            if ($eligible === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No eligible items to send to Publishing Queue.',
                    $projectId,
                );
            }

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $eligible,
                    $this->buildFingerprint($command->name(), $projectId, ['item_ids' => $eligible]),
                    ['action' => 'send_to_publishing_queue', 'items' => $eligible],
                    $warnings,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $eligible, $actor, $warnings): ContentProjectActionResult {
                    $tasks = SeoProjectTask::query()
                        ->where('project_id', $projectId)
                        ->whereIn('id', $eligible)
                        ->whereNull('archived_at')
                        ->with(['article'])
                        ->get();

                    $scheduledCreateIds = [];
                    $immediateUpdateIds = [];
                    $missingRemoteIds = [];

                    foreach ($tasks as $task) {
                        $strategy = $this->strategyResolver->resolve(
                            $task,
                            $task->relationLoaded('article') ? $task->article : null,
                        );

                        if ($strategy->isImmediateUpdate()) {
                            $immediateUpdateIds[] = (int) $task->getKey();
                        } elseif ($strategy->isMissingRemote()) {
                            $missingRemoteIds[] = (int) $task->getKey();
                        } else {
                            $scheduledCreateIds[] = (int) $task->getKey();
                        }
                    }

                    $actorId = $actor->actorId !== null ? (int) $actor->actorId : null;
                    $affected = 0;
                    $affected += $this->queueService->acceptHandoff($project, $scheduledCreateIds, $actorId);
                    $affected += $this->queueService->enqueueImmediateUpdateHandoff($project, $immediateUpdateIds, $actorId);
                    $affected += $this->queueService->failMissingRemotePostHandoff($project, $missingRemoteIds, $actorId);

                    $publishOutcomes = $this->dueItemService->executeMany(
                        $immediateUpdateIds,
                        PublishDueItemService::TRIGGER_SCHEDULER,
                    );

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_SENT_TO_PUBLISHING_QUEUE,
                        $this->handoffMessage(
                            count($scheduledCreateIds),
                            count($immediateUpdateIds),
                            count($missingRemoteIds),
                        ),
                        $projectId,
                        $eligible,
                        $warnings,
                        metadata: [
                            'affected_count' => $affected,
                            'publish_state' => $immediateUpdateIds !== [] ? 'awaiting_delivery' : 'unscheduled',
                            'wordpress_called' => $immediateUpdateIds !== [],
                            'scheduled' => false,
                            'strategy_counts' => [
                                ContentPublishingStrategy::SCHEDULED_CREATE => count($scheduledCreateIds),
                                ContentPublishingStrategy::IMMEDIATE_UPDATE => count($immediateUpdateIds),
                                ContentPublishingStrategy::FAILED_MISSING_REMOTE => count($missingRemoteIds),
                            ],
                            'scheduled_create_ids' => $scheduledCreateIds,
                            'immediate_update_ids' => $immediateUpdateIds,
                            'missing_remote_ids' => $missingRemoteIds,
                            'publish_outcomes' => array_map(
                                static fn (PublishDueItemOutcome $outcome): array => $outcome->toLogArray(),
                                $publishOutcomes,
                            ),
                        ],
                    );
                },
            );
        });
    }

    private function handoffMessage(int $scheduledCreate, int $immediateUpdate, int $missingRemote): string
    {
        $parts = [];
        if ($immediateUpdate > 0) {
            $parts[] = sprintf('%d bai viet lai se duoc cap nhat ngay.', $immediateUpdate);
        }
        if ($scheduledCreate > 0) {
            $parts[] = sprintf('%d bai viet moi da dua vao hang doi len lich.', $scheduledCreate);
        }
        if ($missingRemote > 0) {
            $parts[] = sprintf('%d bai viet lai thieu WP post ID da chuyen sang loi.', $missingRemote);
        }

        return $parts !== [] ? implode(' ', $parts) : 'Sent to Publishing Queue.';
    }
}
