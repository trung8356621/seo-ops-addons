<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Enums\AgentWorkspace;

/**
 * Normalized Agent Workspace error categories for UI/result cards.
 */
enum AgentErrorCategory: string
{
    case ValidationError = 'validation_error';
    case PermissionDenied = 'permission_denied';
    case NotConfigured = 'not_configured';
    case CapabilityUnavailable = 'capability_unavailable';
    case ConfirmationExpired = 'confirmation_expired';
    case ConfirmationStale = 'confirmation_stale';
    case Conflict = 'conflict';
    case BusinessRuleViolation = 'business_rule_violation';
    case RateLimited = 'rate_limited';
    case ProviderError = 'provider_error';
    case QueueError = 'queue_error';
    case InternalError = 'internal_error';

    public function retryable(): bool
    {
        return in_array($this, [
            self::RateLimited,
            self::ProviderError,
            self::QueueError,
            self::InternalError,
            self::CapabilityUnavailable,
        ], true);
    }

    public static function fromGatewayCode(string $code): self
    {
        $c = strtolower(trim($code));

        return match (true) {
            str_contains($c, 'permission') || str_contains($c, 'forbidden') || str_contains($c, 'unauthorized') => self::PermissionDenied,
            str_contains($c, 'validation') || str_contains($c, 'invalid_input') || str_contains($c, 'missing') => self::ValidationError,
            str_contains($c, 'not_configured') || str_contains($c, 'not-configured') => self::NotConfigured,
            str_contains($c, 'unavailable') || str_contains($c, 'coming_soon') => self::CapabilityUnavailable,
            str_contains($c, 'confirmation.expired') || str_contains($c, 'confirmation_expired') => self::ConfirmationExpired,
            str_contains($c, 'confirmation.stale') || str_contains($c, 'confirmation_stale') => self::ConfirmationStale,
            str_contains($c, 'conflict') || str_contains($c, 'idempotent') => self::Conflict,
            str_contains($c, 'rate') || str_contains($c, 'throttle') => self::RateLimited,
            str_contains($c, 'queue') => self::QueueError,
            str_contains($c, 'provider') || str_contains($c, 'upstream') => self::ProviderError,
            str_contains($c, 'business') || str_contains($c, 'rule') => self::BusinessRuleViolation,
            default => self::InternalError,
        };
    }
}
