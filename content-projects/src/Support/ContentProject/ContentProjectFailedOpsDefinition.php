<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Ops Failed summary/list — generation failed/stale (not publish-failed overlay alone).
 */
final class ContentProjectFailedOpsDefinition
{
    public const FILTER = 'failed';

    /**
     * @param  array{
     *     generation_status?: string|null,
     *     is_generation_stale?: bool,
     *     is_genuinely_running?: bool,
     *     execution_status?: string|null,
     *     lifecycle?: string|null,
     *     queue_status?: string|null,
     *     publish_published_at?: string|null,
     *     has_published_revision?: bool,
     *     is_scheduled?: bool,
     *     scheduled_raw?: string|null,
     * }  $row
     */
    public static function matches(array $row): bool
    {
        if (ContentProjectPublishedDefinition::matches($row)) {
            return false;
        }
        if (ContentProjectScheduledDefinition::matches($row)) {
            return false;
        }
        if (! empty($row['is_genuinely_running'])) {
            return false;
        }

        // Stale pending/processing runtime is failed-recoverable, not live Pending.
        if (! empty($row['is_generation_stale'])) {
            return true;
        }

        $exec = strtolower(trim((string) ($row['execution_status'] ?? '')));
        $runtimeState = strtolower(trim((string) ($row['runtime_status']['state'] ?? '')));

        // Live claimed/queued runtime suppresses Failed overlay — membership pending does not.
        if (in_array($runtimeState, [
            ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING,
            ContentProjectArticleRuntimeStatus::STATE_QUEUED,
            ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY,
        ], true)) {
            return false;
        }
        if ($runtimeState === ContentProjectArticleRuntimeStatus::STATE_FAILED) {
            return true;
        }
        if ($exec === 'processing' && ! empty($row['is_genuinely_running'])) {
            return false;
        }

        return strtolower(trim((string) ($row['generation_status'] ?? ''))) === 'failed';
    }
}
