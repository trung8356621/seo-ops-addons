<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemDashboardBucket;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;

/**
 * Maps canonical item state → dashboard counter bucket.
 * Contract source for ContentProjectDashboardStatsService SQL CASE expressions.
 */
final class ContentProjectItemDashboardBucketMapper
{
    public static function fromState(ContentProjectItemState $state): ContentProjectItemDashboardBucket
    {
        if ($state->archiveState->value === 'content_archived'
            || $state->lifecycleState === ContentProjectLifecyclePhase::Archived
        ) {
            return ContentProjectItemDashboardBucket::Archived;
        }

        if ($state->hasPublishedRevision
            || $state->lifecycleState === ContentProjectLifecyclePhase::Published
            || $state->publishState === ContentProjectItemPublishState::Published
        ) {
            return ContentProjectItemDashboardBucket::Published;
        }

        if ($state->lifecycleState === ContentProjectLifecyclePhase::WaitingPublish
            || $state->publishState === ContentProjectItemPublishState::Queued
            || $state->publishState === ContentProjectItemPublishState::Scheduled
        ) {
            return ContentProjectItemDashboardBucket::WaitingPublish;
        }

        if ($state->lifecycleState === ContentProjectLifecyclePhase::Failed
            || $state->publishState === ContentProjectItemPublishState::PublishFailed
            || $state->generationState === ContentProjectItemGenerationState::Failed
        ) {
            return ContentProjectItemDashboardBucket::Failed;
        }

        if ($state->generationState === ContentProjectItemGenerationState::Writing
            || $state->generationState === ContentProjectItemGenerationState::Processing
            || $state->lifecycleState === ContentProjectLifecyclePhase::Generating
        ) {
            return ContentProjectItemDashboardBucket::AiRunning;
        }

        if ($state->lifecycleState === ContentProjectLifecyclePhase::Approved) {
            return ContentProjectItemDashboardBucket::Approved;
        }

        if ($state->lifecycleState === ContentProjectLifecyclePhase::Review) {
            return ContentProjectItemDashboardBucket::WaitingReview;
        }

        if ($state->generationState === ContentProjectItemGenerationState::Pending) {
            return ContentProjectItemDashboardBucket::WaitingAi;
        }

        return ContentProjectItemDashboardBucket::Other;
    }

    /**
     * Pure PHP evaluator mirroring Dashboard SQL predicates for one row (fixture parity).
     *
     * @param  array{
     *     archived_at?: mixed,
     *     status?: string,
     *     publish_queue_status?: string|null,
     *     publish_published_at?: mixed,
     *     scheduled_publish_at?: mixed,
     *     observed_post_status?: string|null,
     *     article_status?: string|null,
     *     review_status?: string|null
     * }  $row
     */
    public static function fromRawRow(array $row): ContentProjectItemDashboardBucket
    {
        $archived = ($row['archived_at'] ?? null) !== null && ($row['archived_at'] ?? '') !== '';
        $taskStatus = (string) ($row['status'] ?? '');
        if ($archived || $taskStatus === 'archived') {
            return ContentProjectItemDashboardBucket::Archived;
        }

        $queue = (string) ($row['publish_queue_status'] ?? 'none');
        $published = ContentProjectPublishedEvidence::fromRow([
            'observed_post_status' => $row['observed_post_status'] ?? null,
            'publish_published_at' => $row['publish_published_at'] ?? null,
            'queue_status' => $queue,
            'publishing_queued_at' => $row['publishing_queued_at'] ?? null,
            'in_publishing_queue' => $row['in_publishing_queue'] ?? false,
        ]);

        if ($published) {
            return ContentProjectItemDashboardBucket::Published;
        }

        if (in_array($queue, ['waiting', 'processing', 'retrying'], true)
            || (($row['scheduled_publish_at'] ?? null) !== null && ($row['scheduled_publish_at'] ?? '') !== '')
        ) {
            return ContentProjectItemDashboardBucket::WaitingPublish;
        }

        if ($taskStatus === 'failed' || $queue === 'failed') {
            return ContentProjectItemDashboardBucket::Failed;
        }

        if ($taskStatus === 'writing') {
            return ContentProjectItemDashboardBucket::AiRunning;
        }

        $review = (string) ($row['review_status'] ?? '');
        if ($taskStatus === 'completed' && $review === 'approved') {
            return ContentProjectItemDashboardBucket::Approved;
        }

        if ($taskStatus === 'reviewing' || ($taskStatus === 'completed' && $review !== 'approved')) {
            return ContentProjectItemDashboardBucket::WaitingReview;
        }

        if ($taskStatus === 'pending') {
            return ContentProjectItemDashboardBucket::WaitingAi;
        }

        return ContentProjectItemDashboardBucket::Other;
    }
}
