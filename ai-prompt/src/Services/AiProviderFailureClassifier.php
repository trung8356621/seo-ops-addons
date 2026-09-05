<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\BudgetExceeded;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use TypeError;
use Throwable;

/**
 * Central AI failure → fallback decision.
 *
 * STOP (no further candidates):
 *   - system/config/invariant / TypeError / LogicException
 *   - invalid workflow/hook definition
 *   - tenant/permission/database/internal serialization
 *   - deterministic application request bugs (400/422 unsupported parameter)
 *   - application output-quality / business validation (not provider transport)
 *
 * CONTINUE (try next candidate; do not treat as connection health failure):
 *   - provider empty output / refusal / invalid|malformed|truncated provider output
 *   - billing / credential / rate-limit / transient provider HTTP failures
 *
 * Only when all eligible routes fail → AiRoutesExhaustedException.
 */
final class AiProviderFailureClassifier
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function classify(Throwable $exception, array $context = []): AiFailureDecision
    {
        if ($exception instanceof \Omnichannel\Addons\AiPrompt\Exceptions\AiRouteCapabilitySkipException
            || (($exception instanceof PromptRunException) && ($exception->context['capability_skip'] ?? false) === true)
        ) {
            return $this->allow(
                category: AiFailureClass::ContextLimitExceeded,
                scope: AiFailureScope::Model,
                safeMessage: 'Route skipped: capability/budget mismatch before provider call.',
                errorCode: 'capability_skip',
                failureStage: 'pre_request',
                requestSent: false,
                responseReceived: false,
                affectsRuntimeHealth: false,
            );
        }

        if ($this->isOutputQualityFailure($exception, $context)) {
            return $this->deny(
                category: AiFailureClass::OutputQuality,
                scope: AiFailureScope::Model,
                safeMessage: 'Provider output failed content quality checks.',
                errorCode: 'output_quality',
                failureStage: 'validation',
                requestSent: true,
                responseReceived: true,
                affectsRuntimeHealth: false,
            );
        }

        if ($exception instanceof BudgetExceeded) {
            return $this->deny(
                category: AiFailureClass::ContextLimitExceeded,
                scope: AiFailureScope::Model,
                safeMessage: 'Prompt hook budget exceeded.',
                errorCode: 'budget_exceeded',
                failureStage: 'pre_request',
                requestSent: false,
                responseReceived: false,
                affectsRuntimeHealth: false,
            );
        }

        if ($this->isSystemFailure($exception, $context)) {
            return $this->deny(
                category: AiFailureClass::SystemError,
                scope: AiFailureScope::System,
                safeMessage: $this->sanitizeMessage($exception->getMessage()),
                httpStatus: $this->httpStatus($exception) ?: null,
                failureStage: 'pipeline',
                requestSent: false,
                responseReceived: false,
                affectsRuntimeHealth: true,
            );
        }

        $httpStatus = $this->httpStatus($exception);
        $message = $exception->getMessage();
        $lower = strtolower($message);
        $providerCode = $this->providerErrorCode($exception);

        if ($this->matchesContextLimit($httpStatus, $lower, $providerCode)) {
            return $this->deny(
                category: AiFailureClass::ContextLimitExceeded,
                scope: AiFailureScope::Model,
                safeMessage: 'Context or output budget exceeded.',
                errorCode: $httpStatus > 0 ? (string) $httpStatus : 'context_limit',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: $httpStatus > 0,
            );
        }

        if ($this->matchesRequestInvalid($httpStatus, $lower, $providerCode)) {
            return $this->deny(
                category: AiFailureClass::RequestInvalid,
                scope: AiFailureScope::System,
                safeMessage: 'Invalid provider request payload or unsupported parameter.',
                errorCode: $httpStatus > 0 ? (string) $httpStatus : 'request_invalid',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                failureStage: 'pre_request',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: $httpStatus > 0,
            );
        }

        // --- BILLING (allow fallback) ---
        if ($httpStatus === 402 || $this->matchesBilling($lower, $providerCode)) {
            $billingExhausted = $this->matchesBillingExhausted($lower, $providerCode);

            return $this->allow(
                category: $billingExhausted
                    ? AiFailureClass::BillingExhausted
                    : AiFailureClass::InsufficientBudgetForRequest,
                scope: AiFailureScope::ConnectionPaid,
                safeMessage: $billingExhausted
                    ? 'Paid balance exhausted.'
                    : 'Insufficient budget for this request.',
                errorCode: '402',
                httpStatus: 402,
                healthStatus: AiRuntimeHealthStatus::BudgetLimited,
                manualUnlockRequired: true,
                lockConnectionPaid: true,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
            );
        }

        // --- PROVIDER API REQUEST FAILURES (allow fallback) ---
        if ($httpStatus === 401 || $this->matchesCredentialInvalid($lower, $providerCode)) {
            return $this->allow(
                category: AiFailureClass::CredentialInvalid,
                scope: AiFailureScope::Connection,
                safeMessage: 'Invalid API credentials.',
                errorCode: '401',
                httpStatus: 401,
                healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
                manualUnlockRequired: true,
                lockConnection: true,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
            );
        }

        if ($httpStatus === 403) {
            if ($this->matchesModelAccessDenied($lower)) {
                return $this->allow(
                    category: AiFailureClass::ModelAccessDenied,
                    scope: AiFailureScope::Model,
                    safeMessage: 'Model access denied.',
                    errorCode: '403',
                    httpStatus: 403,
                    healthStatus: AiRuntimeHealthStatus::Degraded,
                    failureStage: 'provider_http',
                    providerErrorCode: $providerCode,
                    requestSent: true,
                    responseReceived: true,
                );
            }

            return $this->allow(
                category: AiFailureClass::AccountRestricted,
                scope: AiFailureScope::Connection,
                safeMessage: 'Account or credential restriction.',
                errorCode: '403',
                httpStatus: 403,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                manualUnlockRequired: true,
                lockConnection: true,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
            );
        }

        if ($httpStatus === 404 || $this->matchesModelNotFound($lower)) {
            return $this->allow(
                category: AiFailureClass::ModelNotFound,
                scope: AiFailureScope::Model,
                safeMessage: 'Model not found.',
                errorCode: '404',
                httpStatus: 404,
                healthStatus: AiRuntimeHealthStatus::Unavailable,
                markModelUnavailable: true,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
            );
        }

        if ($httpStatus === 429 || $this->matchesRateLimit($lower, $providerCode)) {
            return $this->allow(
                category: AiFailureClass::RateLimited,
                scope: AiFailureScope::Model,
                safeMessage: 'Rate limited.',
                errorCode: '429',
                httpStatus: 429,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                applyCooldown: true,
                failureStage: 'provider_http',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
            );
        }

        if ($this->matchesTransientProvider($httpStatus, $lower, $providerCode)
            || GeminiModelVersionPolicy::isProviderUnavailableError($message)
        ) {
            return $this->allow(
                category: AiFailureClass::TransientProvider,
                scope: AiFailureScope::Model,
                safeMessage: 'Transient provider failure.',
                errorCode: $httpStatus > 0 ? (string) $httpStatus : 'transient',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                applyCooldown: true,
                markModelUnavailable: GeminiModelVersionPolicy::isProviderUnavailableError($message),
                failureStage: $httpStatus > 0 ? 'provider_http' : 'transport',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: $httpStatus > 0,
            );
        }

        // --- POST-RESPONSE PROVIDER OUTPUT (allow fallback; do not lock connection health) ---
        if ($this->matchesProviderRefusal($lower)) {
            return $this->allow(
                category: AiFailureClass::ProviderRefusal,
                scope: AiFailureScope::Model,
                safeMessage: 'Provider refused the request.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                failureStage: 'output',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
                affectsRuntimeHealth: false,
            );
        }

        if ($this->matchesEmptyOutput($lower)) {
            return $this->allow(
                category: AiFailureClass::ProviderEmptyOutput,
                scope: AiFailureScope::Model,
                safeMessage: 'Provider returned empty output.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                failureStage: 'output',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
                affectsRuntimeHealth: false,
            );
        }

        if ($this->matchesInvalidOutput($lower)) {
            return $this->allow(
                category: AiFailureClass::ProviderInvalidOutput,
                scope: AiFailureScope::Model,
                safeMessage: 'Provider output invalid for expected contract.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                failureStage: 'parse',
                providerErrorCode: $providerCode,
                requestSent: true,
                responseReceived: true,
                affectsRuntimeHealth: false,
            );
        }

        // Explicit retryable flag alone is NOT evidence of provider failure.
        // Only Continue when transport/provider patterns already matched above.
        return $this->deny(
            category: AiFailureClass::SystemError,
            scope: AiFailureScope::System,
            safeMessage: $this->sanitizeMessage($message),
            httpStatus: $httpStatus > 0 ? $httpStatus : null,
            failureStage: 'pipeline',
            providerErrorCode: $providerCode,
            requestSent: null,
            responseReceived: null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isOutputQualityFailure(Throwable $exception, array $context): bool
    {
        if (($context['classification'] ?? null) === AiFailureClass::OutputQuality->value) {
            return true;
        }

        if ($exception instanceof PromptRunException) {
            if ($exception->classification() === AiFailureClass::OutputQuality->value) {
                return true;
            }
            if (($exception->context['classification'] ?? null) === AiFailureClass::OutputQuality->value) {
                return true;
            }
        }

        $lower = strtolower($exception->getMessage());

        return str_contains($lower, 'output quality')
            || str_contains($lower, 'content quality rejected')
            || str_contains($lower, 'failed content quality');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isSystemFailure(Throwable $exception, array $context): bool
    {
        if ($exception instanceof TypeError) {
            return true;
        }

        if ($exception instanceof \LogicException || $exception instanceof \InvalidArgumentException) {
            return true;
        }

        if ($context['system_error'] ?? false) {
            return true;
        }

        $lower = strtolower($exception->getMessage());
        $systemPatterns = [
            'invalid prompt hook',
            'hook definition',
            'missing required',
            'required internal variable',
            'invalid workflow',
            'workflow configuration',
            'tenant mismatch',
            'permission violation',
            'database',
            'sqlstate',
            'serialization',
            'invariant',
            'prompt không có nội dung',
            'prompt không có khối nhiệm vụ',
            'prompt thiếu khối',
            'unknown routing profile',
            'ai connection not found',
            'cross tenant',
            'model lacks capability',
            'capability mismatch',
            'keyword discovery prompt is not bound',
            'keyword discovery prompt record is missing',
        ];

        foreach ($systemPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        if ($exception instanceof PromptRunException) {
            $classification = $exception->classification();
            if ($classification !== null && str_starts_with($classification, 'system.')) {
                return true;
            }
        }

        return false;
    }

    private function httpStatus(Throwable $exception): int
    {
        $code = (int) $exception->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        if ($exception instanceof PromptRunException) {
            $audit = $exception->audit();
            $status = (int) ($audit['http_status'] ?? $audit['status'] ?? 0);
            if ($status >= 400 && $status <= 599) {
                return $status;
            }
        }

        if (preg_match('/\b(401|402|403|404|408|413|422|429|5\d{2})\b/', $exception->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function providerErrorCode(Throwable $exception): ?string
    {
        if (! $exception instanceof PromptRunException) {
            return null;
        }

        $audit = $exception->audit();
        $code = $audit['provider_error_code'] ?? $audit['error_type'] ?? null;

        return is_string($code) && $code !== '' ? strtolower($code) : null;
    }

    private function matchesCredentialInvalid(string $lower, ?string $providerCode): bool
    {
        if ($providerCode !== null && in_array($providerCode, ['invalid_api_key', 'authentication_error', 'invalid_authentication'], true)) {
            return true;
        }

        return str_contains($lower, 'invalid api key')
            || str_contains($lower, 'invalid authentication')
            || str_contains($lower, 'unauthorized')
            || str_contains($lower, 'revoked key')
            || str_contains($lower, 'invalid credential');
    }

    private function matchesBilling(string $lower, ?string $providerCode): bool
    {
        if ($providerCode !== null && in_array($providerCode, [
            'insufficient_quota',
            'billing_hard_limit_reached',
            'insufficient_balance',
            'payment_required',
        ], true)) {
            return true;
        }

        return str_contains($lower, 'requires more credits')
            || str_contains($lower, 'insufficient credits')
            || str_contains($lower, 'insufficient balance')
            || str_contains($lower, 'no credits')
            || str_contains($lower, 'zero balance')
            || str_contains($lower, 'billing exhausted')
            || str_contains($lower, 'payment required')
            || str_contains($lower, 'quota exceeded for billing')
            || str_contains($lower, 'billing hard limit');
    }

    private function matchesBillingExhausted(string $lower, ?string $providerCode): bool
    {
        if ($providerCode !== null && in_array($providerCode, [
            'insufficient_quota',
            'billing_hard_limit_reached',
            'insufficient_balance',
        ], true)) {
            return true;
        }

        return str_contains($lower, 'no credits')
            || str_contains($lower, 'zero balance')
            || str_contains($lower, 'billing exhausted')
            || str_contains($lower, 'insufficient credits')
            || str_contains($lower, 'insufficient balance');
    }

    private function matchesModelAccessDenied(string $lower): bool
    {
        return str_contains($lower, 'model access denied')
            || str_contains($lower, 'model permission')
            || str_contains($lower, 'does not have access to model');
    }

    private function matchesModelNotFound(string $lower): bool
    {
        return str_contains($lower, 'model not found')
            || str_contains($lower, 'no longer exists')
            || str_contains($lower, 'endpoint model unavailable')
            || str_contains($lower, 'model unavailable');
    }

    private function matchesRateLimit(string $lower, ?string $providerCode): bool
    {
        if ($providerCode !== null && in_array($providerCode, ['rate_limit_exceeded', 'rate_limit_error', 'too_many_requests'], true)) {
            return true;
        }

        return str_contains($lower, 'rate limit')
            || str_contains($lower, 'rate_limit')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'quota temporarily');
    }

    private function matchesTransientProvider(int $httpStatus, string $lower, ?string $providerCode): bool
    {
        if (in_array($httpStatus, [408, 500, 502, 503, 504, 529], true)) {
            return true;
        }

        if ($providerCode !== null && in_array($providerCode, [
            'server_error',
            'timeout',
            'overloaded',
            'service_unavailable',
        ], true)) {
            return true;
        }

        return str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'dns')
            || str_contains($lower, 'ssl')
            || str_contains($lower, 'tls')
            || str_contains($lower, 'socket')
            || str_contains($lower, 'network failure')
            || str_contains($lower, 'provider unavailable')
            || str_contains($lower, 'service unavailable')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'capacity exhausted')
            || str_contains($lower, 'temporary upstream')
            || str_contains($lower, 'temporarily unavailable');
    }

    private function matchesContextLimit(int $httpStatus, string $lower, ?string $providerCode): bool
    {
        if ($httpStatus === 413) {
            return true;
        }

        if ($providerCode !== null && in_array($providerCode, [
            'context_length_exceeded',
            'token_limit_exceeded',
            'prompt_too_long',
        ], true)) {
            return true;
        }

        return str_contains($lower, 'context length')
            || str_contains($lower, 'context_length')
            || str_contains($lower, 'maximum context')
            || str_contains($lower, 'prompt is too long')
            || str_contains($lower, 'prompt too long')
            || str_contains($lower, 'too many tokens')
            || str_contains($lower, 'token limit exceeded')
            || str_contains($lower, 'tokens exceed')
            || str_contains($lower, 'request too large')
            || str_contains($lower, 'payload too large')
            || str_contains($lower, 'request entity too large');
    }

    private function matchesRequestInvalid(int $httpStatus, string $lower, ?string $providerCode): bool
    {
        if (in_array($httpStatus, [400, 422], true)) {
            // Prefer context-limit wording when both match.
            if ($this->matchesContextLimit($httpStatus, $lower, $providerCode)) {
                return false;
            }

            return true;
        }

        if ($providerCode !== null && in_array($providerCode, [
            'invalid_request_error',
            'invalid_parameter',
            'unsupported_parameter',
        ], true)) {
            return true;
        }

        return str_contains($lower, 'unsupported parameter')
            || str_contains($lower, 'invalid parameter')
            || str_contains($lower, 'invalid_request_error')
            || str_contains($lower, 'invalid request');
    }

    private function matchesProviderRefusal(string $lower): bool
    {
        return str_contains($lower, 'refus')
            || str_contains($lower, 'safety')
            || str_contains($lower, 'content policy')
            || str_contains($lower, 'content_filter')
            || str_contains($lower, 'blocked by');
    }

    private function matchesEmptyOutput(string $lower): bool
    {
        return str_contains($lower, 'empty content')
            || str_contains($lower, 'returned empty')
            || str_contains($lower, 'provider returned empty')
            || str_contains($lower, 'không trả về nội dung');
    }

    private function matchesInvalidOutput(string $lower): bool
    {
        return str_contains($lower, 'output invalid')
            || str_contains($lower, 'malformed')
            || str_contains($lower, 'truncated response')
            || str_contains($lower, 'truncated output')
            || str_contains($lower, 'json decode')
            || str_contains($lower, 'schema validation')
            || str_contains($lower, 'structured output')
            || str_contains($lower, 'planner structured output')
            || (str_contains($lower, 'validation failed') && ! str_contains($lower, 'business validation'));
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|token|password|secret)([=:]+\s*)\S+/i', '$1$2[redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 500);
    }

    private function allow(
        AiFailureClass $category,
        AiFailureScope $scope,
        string $safeMessage,
        ?string $errorCode = null,
        ?int $httpStatus = null,
        ?AiRuntimeHealthStatus $healthStatus = null,
        bool $manualUnlockRequired = false,
        bool $applyCooldown = false,
        bool $markModelUnavailable = false,
        bool $lockConnection = false,
        bool $lockConnectionPaid = false,
        ?string $failureStage = null,
        ?string $providerErrorCode = null,
        ?bool $requestSent = null,
        ?bool $responseReceived = null,
        bool $affectsRuntimeHealth = true,
    ): AiFailureDecision {
        return new AiFailureDecision(
            category: $category,
            scope: $scope,
            recoverable: true,
            runtimeAction: AiFailureRuntimeAction::Continue,
            healthStatus: $healthStatus,
            manualUnlockRequired: $manualUnlockRequired,
            errorCode: $errorCode,
            safeMessage: $safeMessage,
            httpStatus: $httpStatus,
            applyCooldown: $applyCooldown,
            markModelUnavailable: $markModelUnavailable,
            lockConnection: $lockConnection,
            lockConnectionPaid: $lockConnectionPaid,
            affectsRuntimeHealth: $affectsRuntimeHealth,
            failureStage: $failureStage,
            providerErrorCode: $providerErrorCode,
            requestSent: $requestSent,
            responseReceived: $responseReceived,
        );
    }

    private function deny(
        AiFailureClass $category,
        AiFailureScope $scope,
        string $safeMessage,
        ?string $errorCode = null,
        ?int $httpStatus = null,
        ?string $failureStage = null,
        ?string $providerErrorCode = null,
        ?bool $requestSent = null,
        ?bool $responseReceived = null,
        bool $affectsRuntimeHealth = true,
    ): AiFailureDecision {
        return new AiFailureDecision(
            category: $category,
            scope: $scope,
            recoverable: false,
            runtimeAction: AiFailureRuntimeAction::Stop,
            errorCode: $errorCode,
            safeMessage: $safeMessage,
            httpStatus: $httpStatus,
            affectsRuntimeHealth: $affectsRuntimeHealth,
            failureStage: $failureStage,
            providerErrorCode: $providerErrorCode,
            requestSent: $requestSent,
            responseReceived: $responseReceived,
        );
    }
}
