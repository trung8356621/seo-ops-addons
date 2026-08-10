<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

/**
 * Prometheus-ready metric key names for Content Project ops.
 */
final class ContentProjectMetricKeys
{
    public const AI_GENERATE_TOTAL = 'ai_generate_total';

    public const PUBLISH_TOTAL = 'publish_total';

    public const PUBLISH_RETRY_TOTAL = 'publish_retry_total';

    public const ARCHIVE_TOTAL = 'archive_total';

    public const RESTORE_TOTAL = 'restore_total';

    public const WORKSPACE_DESTROY_TOTAL = 'workspace_destroy_total';

    public const QUEUE_WAIT_SECONDS = 'queue_wait_seconds';

    public const PUBLISH_DURATION_MS = 'publish_duration_ms';

    public const AGENT_PLAN_CREATED_TOTAL = 'agent_plan_created_total';

    public const AGENT_PLAN_COMPLETED_TOTAL = 'agent_plan_completed_total';

    public const AGENT_PLAN_FAILED_TOTAL = 'agent_plan_failed_total';

    public const AGENT_STEP_EXECUTED_TOTAL = 'agent_step_executed_total';

    public const AGENT_STEP_RETRY_TOTAL = 'agent_step_retry_total';

    public const AGENT_APPROVAL_REQUESTED_TOTAL = 'agent_approval_requested_total';

    public const AGENT_APPROVAL_REJECTED_TOTAL = 'agent_approval_rejected_total';

    public const AGENT_REPLAN_TOTAL = 'agent_replan_total';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AI_GENERATE_TOTAL,
            self::PUBLISH_TOTAL,
            self::PUBLISH_RETRY_TOTAL,
            self::ARCHIVE_TOTAL,
            self::RESTORE_TOTAL,
            self::WORKSPACE_DESTROY_TOTAL,
            self::QUEUE_WAIT_SECONDS,
            self::PUBLISH_DURATION_MS,
            self::AGENT_PLAN_CREATED_TOTAL,
            self::AGENT_PLAN_COMPLETED_TOTAL,
            self::AGENT_PLAN_FAILED_TOTAL,
            self::AGENT_STEP_EXECUTED_TOTAL,
            self::AGENT_STEP_RETRY_TOTAL,
            self::AGENT_APPROVAL_REQUESTED_TOTAL,
            self::AGENT_APPROVAL_REJECTED_TOTAL,
            self::AGENT_REPLAN_TOTAL,
        ];
    }
}
