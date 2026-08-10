<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use RuntimeException;

/**
 * Shared eligibility for read-model available_actions + command assertCan (Batch D verify).
 * Schedule/PublishNow: Review (Needs Review / In Review reporting) or Approved / WaitingPublish.
 * Block Archived / Generating / Draft / Failed / busy. Reporting states are not hard gates
 * against each other — Approved is optional marker, not required before Schedule.
 * Archive blocked while generation or publish-queue busy (matches ArchiveProjectItemsHandler).
 *
 * Actively publishing = PublishingActiveProcessing::isActivelyPublishing only.
 * retry_wait / scheduled waiting are NOT actively publishing.
 */
final class ContentProjectItemActionGuard
{
    /**
     * @return list<ContentProjectItemAction>
     */
    public function availableActions(
        ContentProjectLifecyclePhase $lifecycle,
        ContentProjectItemArchiveState $archive,
        ContentProjectItemPublishState $publish,
        ContentProjectItemGenerationState $generation,
        ContentProjectItemReviewState $review,
        bool $hasPublished,
        bool $latestPublishAttemptFailed = false,
        ?ContentProjectPublishQueueStatus $queue = null,
        bool $isActivelyPublishing = false,
    ): array {
        if ($archive === ContentProjectItemArchiveState::ContentArchived) {
            // Option B: item-level Restore is not offered — restoring content-archived
            // items happens at the project level via content_project.restore.
            return [];
        }

        $actions = [];

        $genBusy = $generation === ContentProjectItemGenerationState::Writing
            || $generation === ContentProjectItemGenerationState::Processing;
        $inPublishPipeline = $queue === ContentProjectPublishQueueStatus::Processing
            || $queue === ContentProjectPublishQueueStatus::QueuedForDelivery
            || $queue === ContentProjectPublishQueueStatus::Retrying
            || $queue === ContentProjectPublishQueueStatus::Waiting
            || $publish === ContentProjectItemPublishState::Queued;

        if (! $genBusy && ! $inPublishPipeline) {
            $actions[] = ContentProjectItemAction::Archive;

            // Generate-pending: draft or generation-failed only (not review/publish-failed).
            if (! $hasPublished && (
                $lifecycle === ContentProjectLifecyclePhase::Draft
                || ($lifecycle === ContentProjectLifecyclePhase::Failed
                    && $generation === ContentProjectItemGenerationState::Failed)
            )) {
                $actions[] = ContentProjectItemAction::Generate;
            }

            $canRerunFailedDraft = $lifecycle === ContentProjectLifecyclePhase::Draft
                && (
                    $generation === ContentProjectItemGenerationState::Failed
                    // Stuck Pending (dead queue / empty article shell) — smart CTA may Rerun.
                    || $generation === ContentProjectItemGenerationState::Pending
                );

            if ($canRerunFailedDraft || in_array($lifecycle, [
                ContentProjectLifecyclePhase::Review,
                ContentProjectLifecyclePhase::Approved,
                ContentProjectLifecyclePhase::Published,
                ContentProjectLifecyclePhase::WaitingPublish,
                ContentProjectLifecyclePhase::Failed,
            ], true) || $hasPublished) {
                $actions[] = ContentProjectItemAction::Rerun;
            }

            // Align StartReviewHandler: pending/completed → reviewing (not approve/publish).
            if (! $hasPublished && $review !== ContentProjectItemReviewState::Approved) {
                if ($lifecycle === ContentProjectLifecyclePhase::Draft
                    && in_array($generation, [
                        ContentProjectItemGenerationState::Pending,
                        ContentProjectItemGenerationState::Completed,
                        ContentProjectItemGenerationState::Idle,
                    ], true)
                ) {
                    $actions[] = ContentProjectItemAction::StartReview;
                }
                if ($lifecycle === ContentProjectLifecyclePhase::Review
                    && $generation === ContentProjectItemGenerationState::Completed
                    && $review !== ContentProjectItemReviewState::ReviewArchived
                ) {
                    $actions[] = ContentProjectItemAction::StartReview;
                }
            }
        }

        if ($lifecycle === ContentProjectLifecyclePhase::Review
            && $review !== ContentProjectItemReviewState::Approved
            && $review !== ContentProjectItemReviewState::None
            && $review !== ContentProjectItemReviewState::ReviewArchived
        ) {
            $actions[] = ContentProjectItemAction::Approve;
        }

        $scheduleEligible = $this->queueScheduleEligible($lifecycle, $genBusy, $isActivelyPublishing);

        if ($scheduleEligible) {
            if (in_array($publish, [
                ContentProjectItemPublishState::None,
                ContentProjectItemPublishState::Cancelled,
                ContentProjectItemPublishState::Skipped,
            ], true) && ! $latestPublishAttemptFailed) {
                $actions[] = ContentProjectItemAction::Schedule;
                $actions[] = ContentProjectItemAction::PublishNow;
            }
            if ($publish === ContentProjectItemPublishState::Scheduled) {
                $actions[] = ContentProjectItemAction::Unschedule;
                $actions[] = ContentProjectItemAction::PublishNow;
            }
        }

        // Waiting / retry_wait: unschedule + cancel/skip + publish/retry when not actively publishing.
        // Processing with valid lease: no Cancel — use Recover stuck publishing.
        if ($queue === ContentProjectPublishQueueStatus::Waiting && ! $isActivelyPublishing) {
            $actions[] = ContentProjectItemAction::Unschedule;
            $actions[] = ContentProjectItemAction::CancelPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
            $actions[] = ContentProjectItemAction::PublishNow;
        }

        if ($queue === ContentProjectPublishQueueStatus::QueuedForDelivery && ! $isActivelyPublishing) {
            $actions[] = ContentProjectItemAction::Unschedule;
            $actions[] = ContentProjectItemAction::CancelPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
            $actions[] = ContentProjectItemAction::PublishNow;
            $actions[] = ContentProjectItemAction::Schedule;
        }

        if ($queue === ContentProjectPublishQueueStatus::Retrying && ! $isActivelyPublishing) {
            $actions[] = ContentProjectItemAction::Unschedule;
            $actions[] = ContentProjectItemAction::CancelPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
            $actions[] = ContentProjectItemAction::RetryPublish;
            $actions[] = ContentProjectItemAction::PublishNow;
        }

        if ($queue === ContentProjectPublishQueueStatus::Processing && ! $isActivelyPublishing) {
            // Expired / stale processing — allow explicit Retry / Publish Now; Recover stuck remains.
            $actions[] = ContentProjectItemAction::RetryPublish;
            $actions[] = ContentProjectItemAction::PublishNow;
        }

        if ($publish === ContentProjectItemPublishState::PublishFailed || $latestPublishAttemptFailed) {
            $actions[] = ContentProjectItemAction::RetryPublish;
            $actions[] = ContentProjectItemAction::SkipPublish;
        }

        return array_values(array_unique($actions, SORT_REGULAR));
    }

    public function assertCan(
        ContentProjectItemAction $action,
        SeoProjectTask $task,
        ?SeoArticle $article = null,
        ?ContentProjectItemStateResolver $resolver = null,
        array $hints = [],
    ): void {
        $resolver ??= new ContentProjectItemStateResolver($this);
        $state = $resolver->resolve($task, $article, $hints);
        if (
            in_array($action, [ContentProjectItemAction::Generate, ContentProjectItemAction::Rerun], true)
            && $task->isGenerationBlocked()
        ) {
            throw new RuntimeException('Item đã được đánh dấu bỏ qua tạo bài.');
        }
        if (! in_array($action, $state->availableActions, true)) {
            $blocking = $state->blockingReason ?? 'n/a';
            if (
                ($action === ContentProjectItemAction::RetryPublish
                    || $action === ContentProjectItemAction::PublishNow)
                && $blocking === 'Publish queue is active.'
            ) {
                throw new RuntimeException(
                    'Item đang Publishing (queue processing). Retry/Publish now không dùng được — dùng Recover stuck publishing.',
                );
            }

            throw new RuntimeException(sprintf(
                'Action %s not allowed in lifecycle=%s (blocking: %s).',
                $action->value,
                $state->lifecycleState->value,
                $blocking,
            ));
        }
    }

    public function allows(ContentProjectItemAction $action, ContentProjectItemState $state): bool
    {
        return in_array($action, $state->availableActions, true);
    }

    private function queueScheduleEligible(
        ContentProjectLifecyclePhase $lifecycle,
        bool $genBusy,
        bool $isActivelyPublishing,
    ): bool {
        if ($genBusy || $isActivelyPublishing) {
            return false;
        }

        // Schedule / Publish Now from Review (Needs Review or In Review reporting) or Approved.
        // Needs Review / In Review / Approved are NOT hard gates against each other.
        return in_array($lifecycle, [
            ContentProjectLifecyclePhase::Review,
            ContentProjectLifecyclePhase::Approved,
            ContentProjectLifecyclePhase::WaitingPublish,
        ], true);
    }
}
