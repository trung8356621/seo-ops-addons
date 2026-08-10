<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

/**
 * Allowlisted span/event types for Agent Workspace observability.
 */
final class AgentObservabilityCatalog
{
    /** @var list<string> */
    public const SPAN_TYPES = [
        'request',
        'intent_routing',
        'planning',
        'model_call',
        'context_assembly',
        'knowledge_retrieval',
        'plan_validation',
        'execution_preview',
        'confirmation',
        'execution',
        'gateway_call',
        'result_rendering',
        'context_update',
        'automation_dispatch',
        'automation_run',
        'condition_evaluation',
        'notification',
        'policy_check',
        'evaluation',
    ];

    /** @var list<string> */
    public const EVENT_TYPES = [
        'trace.started',
        'trace.finished',
        'span.started',
        'span.finished',
        'metric.recorded',
        'policy.violation',
        'feedback.recorded',
        'review.created',
        'evaluation.completed',
        'security.audit',
        'pack.discovered',
        'pack.validation_failed',
        'pack.compatibility_failed',
        'pack.compiled',
        'pack.enabled',
        'pack.disabled',
        'pack.revision_activated',
        'pack.rollback',
        'pack.import_rejected',
        'pack.quality_gate_failed',
    ];

    /** @var list<string> */
    public const METRIC_KEYS = [
        'planning.count',
        'planning.success',
        'planning.failure',
        'planning.response_type',
        'planning.low_confidence',
        'planning.clarification',
        'planning.validation_fail',
        'planning.repair',
        'planning.latency_ms',
        'planning.tokens',
        'execution.preview',
        'execution.confirm',
        'execution.success',
        'execution.failure',
        'execution.retry',
        'execution.duration_ms',
        'knowledge.retrieval',
        'knowledge.no_result',
        'knowledge.conflict',
        'knowledge.stale',
        'knowledge.citation_reject',
        'knowledge.proposal_approve',
        'automation.run',
        'automation.success',
        'automation.no_change',
        'automation.failure',
        'automation.approval_wait',
        'automation.notify_dedupe',
        'automation.permission_lost',
        'automation.quota_skip',
        'automation.overlap_skip',
        'security.cross_site_reject',
        'security.internal_skill_reject',
        'security.auto_confirm_stripped',
        'security.secret_rejection',
        'security.policy_violation',
        'cost.tokens_input',
        'cost.tokens_output',
        'cost.estimate',
        'cost.unknown',
    ];

    /** @var list<string> */
    public const ALLOWED_DIMENSIONS = [
        'response_type',
        'skill_key',
        'capability',
        'provider',
        'model',
        'status',
        'error_category',
        'severity',
        'automation_type',
        'policy_code',
        'gate_status',
    ];

    public static function isSpanType(string $type): bool
    {
        return in_array($type, self::SPAN_TYPES, true);
    }

    public static function isEventType(string $type): bool
    {
        return in_array($type, self::EVENT_TYPES, true);
    }

    public static function isMetricKey(string $key): bool
    {
        return in_array($key, self::METRIC_KEYS, true);
    }
}
