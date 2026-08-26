<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiFailureClass: string
{
    case CredentialInvalid = 'credential_invalid';
    case BillingExhausted = 'billing_exhausted';
    case InsufficientBudgetForRequest = 'insufficient_budget_for_request';
    case AccountRestricted = 'account_restricted';
    case ModelAccessDenied = 'model_access_denied';
    case ModelNotFound = 'model_not_found';
    case RateLimited = 'rate_limited';
    case TransientProvider = 'transient_provider';
    case ProviderRefusal = 'provider_refusal';
    case ProviderEmptyOutput = 'provider_empty_output';
    case ProviderInvalidOutput = 'provider_invalid_output';
    case OutputQuality = 'output_quality';
    case SystemError = 'system_error';
    case RoutesExhausted = 'routes_exhausted';
}
