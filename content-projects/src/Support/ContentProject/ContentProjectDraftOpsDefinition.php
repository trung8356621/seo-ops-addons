<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Content Project Draft — never generated AI.
 *
 * Not articles.status=draft. Not WordPress draft.
 * No active execution. No canonical generation result.
 */
final class ContentProjectDraftOpsDefinition
{
    public const FILTER = 'draft';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (! empty($row['generation_blocked'])) {
            return false;
        }
        if (ContentProjectPublishedDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectScheduledDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectApprovedDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectFailedOpsDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectPendingOpsDefinition::matches($row)) {
            return false;
        }
        if (! empty($row['is_genuinely_running'])) {
            return false;
        }

        $completed = trim((string) ($row['generation_completed_at'] ?? ''));
        if ($completed !== '') {
            return false;
        }

        $exec = strtolower(trim((string) ($row['execution_status'] ?? '')));
        if (in_array($exec, ['pending', 'processing', 'success', 'completed'], true)) {
            return false;
        }

        $gs = strtolower(trim((string) ($row['generation_status'] ?? '')));
        if (in_array($gs, ['completed', 'reviewing', 'writing', 'failed'], true)) {
            return false;
        }

        if (! empty($row['can_generate'])) {
            return true;
        }

        return $gs === 'pending' || $gs === '';
    }
}
