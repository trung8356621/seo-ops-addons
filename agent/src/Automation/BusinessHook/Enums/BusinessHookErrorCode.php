<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum BusinessHookErrorCode: string
{
    case EventNotRegistered = 'BUSINESS_EVENT_NOT_REGISTERED';
    case ActionNotRegistered = 'AUTOMATION_ACTION_NOT_REGISTERED';
    case MaxDepthExceeded = 'AUTOMATION_MAX_DEPTH_EXCEEDED';
    case LoopDetected = 'AUTOMATION_LOOP_DETECTED';
    case InvalidCondition = 'AUTOMATION_INVALID_CONDITION';
    case InvalidInputMapping = 'AUTOMATION_INVALID_INPUT_MAPPING';
    case MissingRequiredInput = 'AUTOMATION_MISSING_REQUIRED_INPUT';
    case ExecutionClaimFailed = 'AUTOMATION_EXECUTION_CLAIM_FAILED';
    case RuleValidationFailed = 'AUTOMATION_RULE_VALIDATION_FAILED';
    case SubjectNotFound = 'AUTOMATION_SUBJECT_NOT_FOUND';
    case SubjectDeleted = 'AUTOMATION_SUBJECT_DELETED';
    case GraphValidationFailed = 'AUTOMATION_GRAPH_VALIDATION_FAILED';
    case GraphCycleDetected = 'AUTOMATION_GRAPH_CYCLE_DETECTED';
    case GraphFanInNotAllowed = 'AUTOMATION_GRAPH_FAN_IN_NOT_ALLOWED';
    case NodeClaimFailed = 'AUTOMATION_NODE_CLAIM_FAILED';
    case ExecutionStale = 'AUTOMATION_EXECUTION_STALE';
    case NodeStale = 'AUTOMATION_NODE_STALE';
    case NodeRecoveryUnsafe = 'AUTOMATION_NODE_RECOVERY_UNSAFE';
    case ScheduleMissed = 'AUTOMATION_SCHEDULE_MISSED';
    case ConcurrencyBlocked = 'AUTOMATION_CONCURRENCY_BLOCKED';
    case RateLimited = 'AUTOMATION_RATE_LIMITED';
    case ExecutionCancelled = 'AUTOMATION_EXECUTION_CANCELLED';
    case DraftConflict = 'AUTOMATION_DRAFT_CONFLICT';
    case VersionNotFound = 'AUTOMATION_VERSION_NOT_FOUND';
    case PublishDenied = 'AUTOMATION_PUBLISH_DENIED';
    case TenantScopeDenied = 'AUTOMATION_TENANT_SCOPE_DENIED';
    case RuleDisabled = 'AUTOMATION_RULE_DISABLED';
    case ActionManualDisabled = 'AUTOMATION_ACTION_MANUAL_DISABLED';
    case ActionNotAvailable = 'AUTOMATION_ACTION_NOT_AVAILABLE';
    case RuleNotFound = 'AUTOMATION_RULE_NOT_FOUND';
    case RuleNotPublished = 'AUTOMATION_RULE_NOT_PUBLISHED';
    case RuleInvalid = 'AUTOMATION_RULE_INVALID';
    case ConnectionMissing = 'AUTOMATION_CONNECTION_MISSING';
    case CredentialMissing = 'AUTOMATION_CREDENTIAL_MISSING';
    case PermissionDenied = 'AUTOMATION_PERMISSION_DENIED';
    case TenantMismatch = 'AUTOMATION_TENANT_MISMATCH';
    case ConflictingRules = 'AUTOMATION_CONFLICTING_RULES';
    case ExecutionAlreadyActive = 'AUTOMATION_EXECUTION_ALREADY_ACTIVE';
}
