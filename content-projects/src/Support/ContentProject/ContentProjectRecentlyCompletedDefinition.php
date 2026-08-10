<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Reporting state — Needs Review.
 *
 * AI generated AND Content Manager has not canonical-Saved.
 * Not generation. Not lifecycle. Not Schedule/Approve/Publish gate.
 * Auto-clears (does not match) once Approved / Scheduled / Published.
 * Filter key `recently_completed` kept for query-string compatibility.
 */
final class ContentProjectRecentlyCompletedDefinition
{
    public const FILTER = 'recently_completed';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (ContentProjectPublishedDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectScheduledDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectApprovedDefinition::matches($row)) {
            return false;
        }
        if (! empty($row['is_genuinely_running'])) {
            return false;
        }

        if (! empty($row['is_content_manager_reviewed'])) {
            return false;
        }

        $cmReviewedAt = trim((string) ($row['content_manager_reviewed_at'] ?? ''));
        if ($cmReviewedAt !== '') {
            return false;
        }

        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? '')));
        if (in_array($reviewStatus, ['pending_review', 'approved', 'archived'], true)) {
            return false;
        }

        $generationStatus = strtolower(trim((string) ($row['generation_status'] ?? '')));
        if ($generationStatus === 'reviewing') {
            return false;
        }
        if ($generationStatus !== 'completed') {
            return false;
        }

        $executionStatus = strtolower(trim((string) ($row['execution_status'] ?? '')));
        if (! in_array($executionStatus, ['success', 'completed'], true)) {
            return false;
        }

        $completedAt = self::parseTimestamp($row['generation_completed_at'] ?? null);
        if ($completedAt === null) {
            return false;
        }

        $viewedAt = self::parseTimestamp($row['viewed_generation_completed_at'] ?? null);
        if ($viewedAt === null) {
            return true;
        }

        return $completedAt->gt($viewedAt);
    }

    public static function parseTimestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortNewestFirst(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $aAt = self::parseTimestamp($a['generation_completed_at'] ?? null);
            $bAt = self::parseTimestamp($b['generation_completed_at'] ?? null);
            if ($aAt === null && $bAt === null) {
                return ((int) ($b['task_id'] ?? 0)) <=> ((int) ($a['task_id'] ?? 0));
            }
            if ($aAt === null) {
                return 1;
            }
            if ($bAt === null) {
                return -1;
            }
            $cmp = $bAt <=> $aAt;
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($b['task_id'] ?? 0)) <=> ((int) ($a['task_id'] ?? 0));
        });

        return $rows;
    }
}
