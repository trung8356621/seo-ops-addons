<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

/**
 * Mã lỗi chuẩn cho Agent Gateway / MCP adapter.
 */
final class AgentErrorCodes
{
    public const AUTHENTICATION_FAILED = 'agent.authentication_failed';

    public const PERMISSION_DENIED = 'agent.permission_denied';

    public const INVALID_INPUT = 'agent.invalid_input';

    public const CAPABILITY_NOT_FOUND = 'agent.capability_not_found';

    public const CAPABILITY_NOT_ALLOWED = 'agent.capability_not_allowed';

    public const CONTEXT_MISSING = 'agent.context_missing';

    /** Structured fail-closed when capability required_context is absent. */
    public const MISSING_REQUIRED_CONTEXT = 'missing_required_context';

    /** Project/workspace does not belong to the supplied site context. */
    public const CONTEXT_MISMATCH = 'context_mismatch';

    public const RATE_LIMITED = 'agent.rate_limited';

    public const CONFIRMATION_REQUIRED = 'confirmation.required';

    public const CONFIRMATION_INVALID = 'confirmation.invalid';

    public const CONFIRMATION_EXPIRED = 'confirmation.expired';

    public const CONFIRMATION_STALE = 'confirmation.stale';

    public const LIFECYCLE_INVALID_TRANSITION = 'lifecycle.invalid_transition';

    public const OPERATION_LOCKED = 'operation.locked';

    public const OPERATION_ALREADY_PROCESSING = 'operation.already_processing';

    public const OPERATION_NOT_FOUND = 'operation.not_found';

    public const QUOTA_EXCEEDED = 'quota.exceeded';

    public const RESOURCE_NOT_FOUND = 'resource.not_found';

    public const TENANT_ACCESS_DENIED = 'tenant.access_denied';

    public const APPROVAL_REVIEW_REQUIRED = 'approval.review_required';

    public const SESSION_NOT_FOUND = 'agent.session_not_found';

    public const SESSION_EXPIRED = 'agent.session_expired';

    public const INTERNAL_ERROR = 'agent.internal_error';

    public const CONFLICTING_ACTIONS = 'agent.conflicting_actions';

    public const PLAN_INVALID_CAPABILITY = 'plan.invalid_capability';

    public const PLAN_INVALID_INPUT = 'plan.invalid_input';

    public const PLAN_UNSAFE_STEP = 'plan.unsafe_step';

    public const PLAN_POLICY_DENIED = 'plan.policy_denied';

    public const PLAN_INVALID_STATE = 'plan.invalid_state';

    public const PLAN_NOT_FOUND = 'plan.not_found';

    public const BUDGET_EXCEEDED = 'budget.exceeded';

    public const APPROVAL_REQUIRED = 'approval.required';

    public const APPROVAL_INVALID = 'approval.invalid';
}
