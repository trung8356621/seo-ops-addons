<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemOpsEvidencePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition;

/**
 * Outstanding assigned work is the Content Project "Cần biên tập" card:
 * generation succeeded and the Content Manager has not canonical-saved.
 * Ungenerated plans, published, scheduled, approved, archived and cancelled stay out.
 */
final class WorkStatsTaskLedger
{
    /**
     * @param  array<string, mixed>|null  $latestExecution
     * @return array<string, mixed>
     */
    public static function evidenceRow(
        string $taskStatus,
        ?array $latestExecution,
        ?string $contentManagerReviewedAt,
        ?string $articleReviewStatus,
        ?string $observedPostStatus,
        ?string $viewedGenerationCompletedAt,
        ?string $publishQueueStatus = null,
        ?string $publishPublishedAt = null,
        ?string $publishingQueuedAt = null,
    ): array {
        $presented = ContentProjectItemOpsEvidencePresenter::present(
            $taskStatus,
            $latestExecution,
            null,
            [],
        );
        $executionStatus = strtolower(trim((string) ($presented['execution_status'] ?? '')));
        $finishedAt = $latestExecution['finished_at'] ?? $latestExecution['finished_at_iso'] ?? null;
        $finishedAt = $finishedAt instanceof \DateTimeInterface
            ? $finishedAt->format('Y-m-d H:i:s')
            : (is_string($finishedAt) ? trim($finishedAt) : '');
        $reviewedAt = trim((string) $contentManagerReviewedAt);

        return [
            'generation_status' => $presented['generation_status'],
            'execution_status' => $executionStatus,
            'generation_completed_at' => in_array($executionStatus, ['success', 'completed'], true) && $finishedAt !== ''
                ? $finishedAt
                : null,
            'is_genuinely_running' => (bool) ($presented['is_genuinely_running'] ?? false),
            'is_content_manager_reviewed' => $reviewedAt !== '',
            'content_manager_reviewed_at' => $reviewedAt,
            'review_status' => $articleReviewStatus,
            'observed_post_status' => $observedPostStatus,
            'viewed_generation_completed_at' => $viewedGenerationCompletedAt,
            'queue_status' => $publishQueueStatus ?? 'none',
            'publish_published_at' => $publishPublishedAt,
            'publishing_queued_at' => $publishingQueuedAt,
            'has_published_revision' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isOutstanding(array $row): bool
    {
        $status = strtolower(trim((string) ($row['task_status'] ?? '')));
        if (in_array($status, [
            SeoProjectTask::STATUS_ARCHIVED,
            SeoProjectTask::STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        return ContentProjectRecentlyCompletedDefinition::matches($row);
    }
}
