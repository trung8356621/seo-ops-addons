<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Stable machine codes for normalized AI execution failures.
 */
enum AiNormalizedFailureCode: string
{
    // ROUTING
    case RoutingNoEligibleRoute = 'AI_ROUTING_NO_ELIGIBLE_ROUTE';
    case RoutingAttemptBudgetExhausted = 'AI_ROUTING_ATTEMPT_BUDGET_EXHAUSTED';
    case RoutingFreeOnlyExhausted = 'AI_ROUTING_FREE_ONLY_EXHAUSTED';
    case RoutingAllCandidatesBlocked = 'AI_ROUTING_ALL_CANDIDATES_BLOCKED';
    case RoutingPolicyExcluded = 'AI_ROUTING_POLICY_EXCLUDED';

    // PROVIDER
    case ProviderAuthFailed = 'AI_PROVIDER_AUTH_FAILED';
    case ProviderBillingLimit = 'AI_PROVIDER_BILLING_LIMIT';
    case ProviderRateLimited = 'AI_PROVIDER_RATE_LIMITED';
    case ProviderTimeout = 'AI_PROVIDER_TIMEOUT';
    case ProviderUnavailable = 'AI_PROVIDER_UNAVAILABLE';
    case ProviderEmptyResponse = 'AI_PROVIDER_EMPTY_RESPONSE';
    case ProviderRefused = 'AI_PROVIDER_REFUSED';
    case ProviderInvalidResponse = 'AI_PROVIDER_INVALID_RESPONSE';

    // VALIDATION
    case OutputTooShort = 'AI_OUTPUT_TOO_SHORT';
    case OutputSchemaInvalid = 'AI_OUTPUT_SCHEMA_INVALID';
    case OutputFormatInvalid = 'AI_OUTPUT_FORMAT_INVALID';
    case OutputRequiredSectionMissing = 'AI_OUTPUT_REQUIRED_SECTION_MISSING';
    case OutputEmptyAfterParse = 'AI_OUTPUT_EMPTY_AFTER_PARSE';

    // SYSTEM
    case InternalError = 'AI_INTERNAL_ERROR';
    case PromptBuildError = 'AI_PROMPT_BUILD_ERROR';
    case ContextBuildError = 'AI_CONTEXT_BUILD_ERROR';
    case PersistenceError = 'AI_PERSISTENCE_ERROR';
    case WorkflowStateError = 'AI_WORKFLOW_STATE_ERROR';
    case ValidatorInternalError = 'AI_VALIDATOR_INTERNAL_ERROR';
}
