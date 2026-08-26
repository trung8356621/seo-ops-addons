<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiFailureRuntimeAction;
use Omnichannel\Addons\AiPrompt\Support\AiFailureScope;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use TypeError;
use Throwable;

final class AiProviderFailureClassifier
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function classify(Throwable $exception, array $context = []): AiFailureDecision
    {
        if ($this->isOutputQualityFailure($exception, $context)) {
            return new AiFailureDecision(
                category: AiFailureClass::OutputQuality,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                errorCode: 'output_quality',
                safeMessage: 'Provider output failed content quality checks.',
                affectsRuntimeHealth: false,
            );
        }

        if ($this->isSystemFailure($exception, $context)) {
            return new AiFailureDecision(
                category: AiFailureClass::SystemError,
                scope: AiFailureScope::System,
                recoverable: false,
                runtimeAction: AiFailureRuntimeAction::Stop,
                safeMessage: $this->sanitizeMessage($exception->getMessage()),
                httpStatus: $this->httpStatus($exception),
            );
        }

        $httpStatus = $this->httpStatus($exception);
        $message = $exception->getMessage();
        $lower = strtolower($message);
        $providerCode = $this->providerErrorCode($exception);

        if ($httpStatus === 401 || $this->matchesCredentialInvalid($lower, $providerCode)) {
            return new AiFailureDecision(
                category: AiFailureClass::CredentialInvalid,
                scope: AiFailureScope::Connection,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::ConnectionLocked,
                manualUnlockRequired: true,
                errorCode: '401',
                safeMessage: 'Invalid API credentials.',
                httpStatus: 401,
                lockConnection: true,
            );
        }

        if ($httpStatus === 402 || $this->matchesBilling($lower)) {
            $billingExhausted = str_contains($lower, 'no credits')
                || str_contains($lower, 'zero balance')
                || str_contains($lower, 'billing exhausted')
                || str_contains($lower, 'insufficient credits');

            return new AiFailureDecision(
                category: $billingExhausted
                    ? AiFailureClass::BillingExhausted
                    : AiFailureClass::InsufficientBudgetForRequest,
                scope: AiFailureScope::ConnectionPaid,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::BudgetLimited,
                manualUnlockRequired: true,
                errorCode: '402',
                safeMessage: $billingExhausted
                    ? 'Paid balance exhausted.'
                    : 'Insufficient budget for this request.',
                httpStatus: 402,
                lockConnectionPaid: true,
            );
        }

        if ($httpStatus === 403) {
            if ($this->matchesModelAccessDenied($lower)) {
                return new AiFailureDecision(
                    category: AiFailureClass::ModelAccessDenied,
                    scope: AiFailureScope::Model,
                    recoverable: true,
                    runtimeAction: AiFailureRuntimeAction::Continue,
                    healthStatus: AiRuntimeHealthStatus::Degraded,
                    errorCode: '403',
                    safeMessage: 'Model access denied.',
                    httpStatus: 403,
                );
            }

            return new AiFailureDecision(
                category: AiFailureClass::AccountRestricted,
                scope: AiFailureScope::Connection,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                manualUnlockRequired: true,
                errorCode: '403',
                safeMessage: 'Account or credential restriction.',
                httpStatus: 403,
                lockConnection: true,
            );
        }

        if ($httpStatus === 404 || $this->matchesModelNotFound($lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::ModelNotFound,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Unavailable,
                errorCode: '404',
                safeMessage: 'Model not found.',
                httpStatus: 404,
                markModelUnavailable: true,
            );
        }

        if ($httpStatus === 429 || $this->matchesRateLimit($lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::RateLimited,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                errorCode: '429',
                safeMessage: 'Rate limited.',
                httpStatus: 429,
                applyCooldown: true,
            );
        }

        if ($this->matchesTransientProvider($httpStatus, $lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::TransientProvider,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                errorCode: $httpStatus > 0 ? (string) $httpStatus : 'transient',
                safeMessage: 'Transient provider failure.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                applyCooldown: true,
            );
        }

        if ($this->matchesProviderRefusal($lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::ProviderRefusal,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                safeMessage: 'Provider refused the request.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
            );
        }

        if ($this->matchesEmptyOutput($lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::ProviderEmptyOutput,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                safeMessage: 'Provider returned empty output.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
            );
        }

        if ($this->matchesInvalidOutput($lower)) {
            return new AiFailureDecision(
                category: AiFailureClass::ProviderInvalidOutput,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                safeMessage: 'Provider output invalid for expected contract.',
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
            );
        }

        if (GeminiModelVersionPolicy::isProviderUnavailableError($message)) {
            return new AiFailureDecision(
                category: AiFailureClass::TransientProvider,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                safeMessage: 'Provider temporarily unavailable.',
                applyCooldown: true,
                markModelUnavailable: true,
            );
        }

        if ($exception instanceof PromptRunException && $exception->isRetryable()) {
            return new AiFailureDecision(
                category: AiFailureClass::TransientProvider,
                scope: AiFailureScope::Model,
                recoverable: true,
                runtimeAction: AiFailureRuntimeAction::Continue,
                healthStatus: AiRuntimeHealthStatus::Degraded,
                safeMessage: $this->sanitizeMessage($message),
                httpStatus: $httpStatus > 0 ? $httpStatus : null,
                applyCooldown: true,
            );
        }

        return new AiFailureDecision(
            category: AiFailureClass::SystemError,
            scope: AiFailureScope::System,
            recoverable: false,
            runtimeAction: AiFailureRuntimeAction::Stop,
            safeMessage: $this->sanitizeMessage($message),
            httpStatus: $httpStatus > 0 ? $httpStatus : null,
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

        if (preg_match('/\b(401|402|403|404|408|429|5\d{2})\b/', $exception->getMessage(), $matches)) {
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

    private function matchesBilling(string $lower): bool
    {
        return str_contains($lower, 'requires more credits')
            || str_contains($lower, 'insufficient')
            || str_contains($lower, 'billing')
            || str_contains($lower, 'payment required')
            || str_contains($lower, 'quota exceeded for billing');
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

    private function matchesRateLimit(string $lower): bool
    {
        return str_contains($lower, 'rate limit')
            || str_contains($lower, 'rate_limit')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'quota temporarily');
    }

    private function matchesTransientProvider(int $httpStatus, string $lower): bool
    {
        if (in_array($httpStatus, [408, 500, 502, 503, 504, 529], true)) {
            return true;
        }

        return str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'network failure')
            || str_contains($lower, 'provider unavailable')
            || str_contains($lower, 'service unavailable')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'capacity exhausted')
            || str_contains($lower, 'temporary upstream')
            || str_contains($lower, 'temporarily');
    }

    private function matchesProviderRefusal(string $lower): bool
    {
        return str_contains($lower, 'refus')
            || str_contains($lower, 'safety')
            || str_contains($lower, 'content policy')
            || str_contains($lower, 'blocked');
    }

    private function matchesEmptyOutput(string $lower): bool
    {
        return str_contains($lower, 'empty content')
            || str_contains($lower, 'returned empty')
            || str_contains($lower, 'không trả về nội dung');
    }

    private function matchesInvalidOutput(string $lower): bool
    {
        return str_contains($lower, 'output invalid')
            || str_contains($lower, 'malformed')
            || str_contains($lower, 'truncated response');
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|token|password|secret)([=:]+\s*)\S+/i', '$1$2[redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 500);
    }
}
