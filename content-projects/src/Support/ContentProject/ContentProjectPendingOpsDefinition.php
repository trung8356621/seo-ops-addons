<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Ops Pending — AI queued or running (between Draft and Needs Review).
 *
 * Not Draft (never started). Not reporting. Not publishing.
 */
final class ContentProjectPendingOpsDefinition
{
    public const FILTER = 'pending';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row): bool
    {
        if (! empty($row['generation_blocked'])) {
            return false;
        }
        // Dead/stale runtime must not look like live Pending (blocks Generate CTA).
        if (! empty($row['is_generation_stale'])) {
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

        // Runtime resolver is authoritative when present — a stale "processing" row with
        // no live dispatch is not Pending work, and sticky task.status=writing is not
        // enough on its own.
        $runtimeState = strtolower(trim((string) ($row['runtime_status']['state'] ?? '')));
        if ($runtimeState !== '') {
            return in_array($runtimeState, [
                ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING,
                ContentProjectArticleRuntimeStatus::STATE_QUEUED,
                ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY,
            ], true);
        }

        if (! empty($row['is_genuinely_running'])) {
            return true;
        }

        $gs = strtolower(trim((string) ($row['generation_status'] ?? '')));
        if ($gs === 'writing') {
            return true;
        }

        $exec = strtolower(trim((string) ($row['execution_status'] ?? '')));

        return in_array($exec, ['pending', 'processing'], true);
    }
}
