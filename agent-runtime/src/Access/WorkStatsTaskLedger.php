<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectNeedsEditingIndex;

/**
 * Outstanding assigned work is the Content Project "Cần biên tập" card.
 * Matching lives in {@see ContentProjectNeedsEditingIndex}.
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
        return ContentProjectNeedsEditingIndex::evidenceRow(
            $taskStatus,
            $latestExecution,
            $contentManagerReviewedAt,
            $articleReviewStatus,
            $observedPostStatus,
            $viewedGenerationCompletedAt,
            $publishQueueStatus,
            $publishPublishedAt,
            $publishingQueuedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isOutstanding(array $row): bool
    {
        return ContentProjectNeedsEditingIndex::isOutstanding($row);
    }
}
