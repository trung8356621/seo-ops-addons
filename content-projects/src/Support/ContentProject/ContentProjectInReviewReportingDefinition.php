<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Reporting state — In Review / Reviewed by Content Manager.
 *
 * Content Manager canonical Save stamped. Not lifecycle. Not a gate.
 * Auto-clears once Approved / Scheduled / Published.
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
}
