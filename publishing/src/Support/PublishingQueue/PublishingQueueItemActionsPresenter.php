<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Presentation-only action visibility for Publishing Queue row / bulk UX.
 *
 * Immediate publish is mutually exclusive: publish_now XOR retry_now.
 * Recover is never a normal bulk action — only inline when stuck active publisher.
 */
final class PublishingQueueItemActionsPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     state: string,
     *     publish_now: bool,
     *     retry_now: bool,
     *     retry_publish: bool,
     *     immediate_disabled: bool,
     *     immediate_disabled_reason: string|null,
     *     schedule: bool,
     *     unschedule: bool,
     *     cancel_pending_delivery: bool,
     *     remove_from_queue: bool,
     *     return_to_content_project: bool,
     *     stop_publish: bool,
     *     view_error: bool,
     *     view_technical_details: bool,
     *     show_recover_banner: bool,
     *     open_article: bool,
     *     view_on_wordpress: bool,
     *     resync_wordpress: bool,
     *     view_sync_history: bool,
     *     has_publish_group: bool,
     *     has_schedule_group: bool,
     *     has_more_group: bool,
     *     cancel: bool,
     *     recover_awaiting: bool,
     *     open_operation: bool,
     *     has_publishing: bool,
     *     has_lifecycle: bool,
     *     has_other: bool
     * }
     */
    public static function forRow(array $row): array
    {
        $state = strtolower((string) ($row['publish_state'] ?? PublishingQueueStateClassifier::UNSCHEDULED));
        if ($state === 'awaiting_worker') {
            $state = PublishingQueueStateClassifier::AWAITING_DELIVERY;
        }

        $activelyPublishing = (new PublishingActiveProcessing)->isActivelyPublishing($row);
        $stuckActive = PublishingQueueStuckPublishingDefinition::matches($row);
        $openArticle = ! empty($row['article_edit_url']);
        $wpPermalink = trim((string) ($row['wp_permalink'] ?? ''));
        $isAwaiting = $state === PublishingQueueStateClassifier::AWAITING_DELIVERY;
        $isPublishing = $state === PublishingQueueStateClassifier::PUBLISHING;
        $isNeedsAttention = $state === PublishingQueueStateClassifier::NEEDS_ATTENTION;
        $itemType = SeoProjectTask::normalizeType($row['item_type'] ?? null);
        $isUpdateExisting = in_array($itemType, [
            SeoProjectTask::TYPE_REWRITE,
            SeoProjectTask::TYPE_IMPROVE,
        ], true);

        $viewOnWordpress = $state === PublishingQueueStateClassifier::PUBLISHED
            && $wpPermalink !== ''
            && filter_var($wpPermalink, FILTER_VALIDATE_URL) !== false;

        $resyncWordpress = $state === PublishingQueueStateClassifier::PUBLISHED
            && ! $activelyPublishing
            && ! $isPublishing;

        $viewSyncHistory = $state === PublishingQueueStateClassifier::PUBLISHED
            && (
                trim((string) ($row['publish_operation_key'] ?? '')) !== ''
                || trim((string) ($row['last_post_publish_sync_operation_id'] ?? '')) !== ''
            );

        // Mutually exclusive immediate actions.
        $publishNow = ! $activelyPublishing && ! $isAwaiting && ! $isPublishing && in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
        ], true);

        $retryNow = ! $activelyPublishing && ! $isAwaiting && ! $isPublishing && in_array($state, [
            PublishingQueueStateClassifier::FAILED,
            PublishingQueueStateClassifier::RETRY_WAIT,
            PublishingQueueStateClassifier::NEEDS_ATTENTION,
        ], true);

        $immediateDisabled = ($activelyPublishing || $isPublishing) && ! $stuckActive;
        $immediateDisabledReason = $immediateDisabled ? 'Bài đang được xuất bản.' : null;

        $schedule = ! $isUpdateExisting && ! $activelyPublishing && ! $isAwaiting && ! $isPublishing && in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
            PublishingQueueStateClassifier::RETRY_WAIT,
            PublishingQueueStateClassifier::NEEDS_ATTENTION,
        ], true);

        $unschedule = ! $isUpdateExisting && $state === PublishingQueueStateClassifier::SCHEDULED && ! $activelyPublishing;

        $cancelPendingDelivery = $isAwaiting;

        $removeFromQueue = ! $activelyPublishing && in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::FAILED,
            PublishingQueueStateClassifier::RETRY_WAIT,
            PublishingQueueStateClassifier::NEEDS_ATTENTION,
        ], true);

        // Avoid duplicate "Bỏ khỏi" / "Trả về" for unscheduled — one leave-queue action.
        $returnToContentProject = in_array($state, [
            PublishingQueueStateClassifier::SCHEDULED,
            PublishingQueueStateClassifier::FAILED,
            PublishingQueueStateClassifier::RETRY_WAIT,
            PublishingQueueStateClassifier::NEEDS_ATTENTION,
        ], true);

        $viewError = in_array($state, [
            PublishingQueueStateClassifier::FAILED,
            PublishingQueueStateClassifier::RETRY_WAIT,
            PublishingQueueStateClassifier::NEEDS_ATTENTION,
        ], true) && trim((string) ($row['last_publish_error'] ?? $row['last_publish_error_message'] ?? '')) !== '';

        $viewTechnical = $isNeedsAttention || ! empty($row['publish_operation_key']);

        // Recover only after confirmed stuck active publisher — never for retry_wait/failed/scheduled.
        $showRecoverBanner = $stuckActive
            && ($isPublishing || $activelyPublishing)
            && ! in_array($state, [
                PublishingQueueStateClassifier::SCHEDULED,
                PublishingQueueStateClassifier::RETRY_WAIT,
                PublishingQueueStateClassifier::FAILED,
                PublishingQueueStateClassifier::AWAITING_DELIVERY,
                PublishingQueueStateClassifier::UNSCHEDULED,
                PublishingQueueStateClassifier::PUBLISHED,
            ], true);

        // Stop active attempt only when stuck (needs confirm in UI).
        $stopPublish = $showRecoverBanner;

        $hasPublishGroup = $publishNow || $retryNow || $immediateDisabled;
        $hasScheduleGroup = $schedule || $unschedule;
        $hasMoreGroup = $removeFromQueue || $returnToContentProject || $viewTechnical || $viewError
            || $cancelPendingDelivery || $viewOnWordpress || $resyncWordpress || $viewSyncHistory
            || $openArticle || $stopPublish;

        return [
            'state' => $state,
            'publish_now' => $publishNow,
            'retry_now' => $retryNow,
            'retry_publish' => $retryNow, // legacy alias
            'immediate_disabled' => $immediateDisabled,
            'immediate_disabled_reason' => $immediateDisabledReason,
            'schedule' => $schedule,
            'unschedule' => $unschedule,
            'cancel_pending_delivery' => $cancelPendingDelivery,
            'remove_from_queue' => $removeFromQueue,
            'return_to_content_project' => $returnToContentProject,
            'stop_publish' => $stopPublish,
            'view_error' => $viewError,
            'view_technical_details' => $viewTechnical,
            'show_recover_banner' => $showRecoverBanner,
            'open_article' => $openArticle,
            'view_on_wordpress' => $viewOnWordpress,
            'resync_wordpress' => $resyncWordpress,
            'view_sync_history' => $viewSyncHistory,
            'has_publish_group' => $hasPublishGroup,
            'has_schedule_group' => $hasScheduleGroup,
            'has_more_group' => $hasMoreGroup,
            // Legacy keys kept for older blades/tests during cutover.
            'cancel' => $removeFromQueue || $cancelPendingDelivery,
            'recover_awaiting' => false,
            'open_operation' => $viewTechnical || $viewSyncHistory,
            'has_publishing' => $hasPublishGroup || $hasScheduleGroup,
            'has_lifecycle' => $returnToContentProject || $removeFromQueue || $cancelPendingDelivery,
            'has_other' => $openArticle || $viewOnWordpress || $resyncWordpress || $viewSyncHistory || $viewError || $viewTechnical,
        ];
    }

    /**
     * Format bulk action toast body (presentation only).
     *
     * @param  array{succeeded?: int, skipped?: int, failed?: int}  $counts
     */
    public static function bulkSummary(string $verbPast, array $counts): string
    {
        $ok = max(0, (int) ($counts['succeeded'] ?? 0));
        $skipped = max(0, (int) ($counts['skipped'] ?? 0));
        $failed = max(0, (int) ($counts['failed'] ?? 0));

        if ($ok === 0 && $skipped === 0 && $failed === 0) {
            return 'Không có bài phù hợp.';
        }

        $parts = [];
        if ($ok > 0) {
            $parts[] = "{$verbPast} {$ok} bài.";
        }
        if ($skipped > 0) {
            $parts[] = "Bỏ qua {$skipped} bài đang xuất bản.";
        }
        if ($failed > 0) {
            $parts[] = "Thất bại {$failed} bài.";
        }

        return implode(' ', $parts);
    }
}
