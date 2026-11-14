<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Reporting state — Đã biên tập / In Review.
 *
 * Matches when:
 * - Content Manager (or higher) completed editorial Save (reporting stamp), OR
 * - Item has entered Publishing Queue (membership implies editorial completion).
 *
 * Not lifecycle. Not a gate.
 * Auto-clears once Approved / Scheduled / Published — except Queue membership
 * still counts as Đã biên tập even when queue_status is waiting/processing
 * (ScheduledDefinition would otherwise clear stamp-only rows).
 *
 * Count SoT (project list + detail):
 * edited = DISTINCT(stamped_items ∪ publishing_queue_items) within the same guards.
 */
final class ContentProjectInReviewReportingDefinition
{
    public const FILTER = 'in_review_reporting';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (ContentProjectPublishedDefinition::matches($row)) {
            return false;
        }

        // Queue membership before Scheduled/Approved clears — business rule:
        // entered Publishing Queue ⇒ Đã biên tập.
        if (self::inPublishingQueue($row)) {
            return true;
        }

        if (ContentProjectScheduledDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectApprovedDefinition::matches($row)) {
            return false;
        }

        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? '')));
        if (in_array($reviewStatus, ['approved', 'archived'], true)) {
            return false;
        }

        if (! empty($row['is_content_manager_reviewed'])) {
            return true;
        }

        if (trim((string) ($row['content_manager_reviewed_at'] ?? '')) !== '') {
            return true;
        }

        // Legacy handoff residue.
        if ($reviewStatus === 'pending_review') {
            return true;
        }

        return strtolower(trim((string) ($row['generation_status'] ?? ''))) === 'reviewing';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function inPublishingQueue(array $row): bool
    {
        if (! empty($row['in_publishing_queue'])) {
            return true;
        }

        return trim((string) ($row['publishing_queued_at'] ?? '')) !== '';
    }
}
