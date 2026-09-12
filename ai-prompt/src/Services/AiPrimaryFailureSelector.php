<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiNormalizedFailure;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use Omnichannel\Addons\AiPrompt\Support\AiFailureCategory;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode;
use Throwable;

/**
 * Selects the primary user-facing failure without overwriting specific root causes
 * with AI_ROUTES_EXHAUSTED.
 */
final class AiPrimaryFailureSelector
{
    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     * @param  array<string, mixed>  $validationTrace
     */
    public function select(
        ?Throwable $terminalException,
        array $routingAttempts = [],
        int $actualAttempts = 0,
        ?string $promptKey = null,
        ?string $stage = null,
        ?string $correlationId = null,
        array $validationTrace = [],
    ): AiNormalizedFailure {
        $routingTerminal = null;
        if ($terminalException instanceof AiRoutesExhaustedException) {
            $routingTerminal = 'routes_exhausted';
            $actualAttempts = max($actualAttempts, (int) ($terminalException->context['attempt_count'] ?? $actualAttempts));
            if ($routingAttempts === []) {
                $routingAttempts = is_array($terminalException->context['routing_attempts'] ?? null)
                    ? $terminalException->context['routing_attempts']
                    : [];
            }
        }

        // Case 4: SYSTEM — internal PHP exception that is not routing/provider/validation typed.
        if ($terminalException !== null && $this->isSystemException($terminalException)) {
            return new AiNormalizedFailure(
                category: AiFailureCategory::System,
                code: AiNormalizedFailureCode::InternalError,
                userMessage: 'Lỗi ứng dụng khi thực thi AI. Vui lòng thử lại hoặc liên hệ admin.',
                debugMessage: $terminalException->getMessage(),
                promptKey: $promptKey,
                stage: $stage,
                attemptCount: $actualAttempts,
                routingTerminalReason: $routingTerminal,
                exceptionClass: $terminalException::class,
                correlationId: $correlationId,
            );
        }

        // Case 2: VALIDATION — provider returned content but output failed contract.
        $validationFailure = $this->fromValidation($terminalException, $validationTrace, $promptKey, $stage, $correlationId, $actualAttempts, $routingTerminal);
        if ($validationFailure !== null) {
            return $validationFailure;
        }

        // Case 1: ROUTING — zero API attempts.
        if ($actualAttempts <= 0) {
            return $this->routingZeroAttempts(
                $terminalException,
                $routingAttempts,
                $promptKey,
                $stage,
                $correlationId,
                $routingTerminal,
            );
        }

        // Case 3: PROVIDER — all actual provider attempts failed at transport level.
        $providerFailure = $this->fromProviderAttempts(
            $terminalException,
            $routingAttempts,
            $actualAttempts,
            $promptKey,
            $stage,
            $correlationId,
            $routingTerminal,
        );
        if ($providerFailure !== null) {
            return $providerFailure;
        }

        // Fallback: treat exhaustion as routing budget/terminal without erasing prior detail.
        return new AiNormalizedFailure(
            category: AiFailureCategory::Routing,
            code: AiNormalizedFailureCode::RoutingAttemptBudgetExhausted,
            userMessage: 'Routing: đã hết ngân sách thử AI và không có kết quả hợp lệ.',
            debugMessage: $terminalException?->getMessage(),
            promptKey: $promptKey,
            stage: $stage,
            attemptCount: $actualAttempts,
            routingTerminalReason: $routingTerminal ?? 'routes_exhausted',
            exceptionClass: $terminalException !== null ? $terminalException::class : null,
            correlationId: $correlationId,
        );
    }

    /**
     * @param  array<string, mixed>  $validationTrace
     */
    private function fromValidation(
        ?Throwable $exception,
        array $validationTrace,
        ?string $promptKey,
        ?string $stage,
        ?string $correlationId,
        int $actualAttempts,
        ?string $routingTerminal,
    ): ?AiNormalizedFailure {
        $contract = is_string($validationTrace['validation_contract'] ?? null)
            ? $validationTrace['validation_contract']
            : ($promptKey ?? null);

        if ($exception instanceof OutputTruncated
            || ($exception instanceof PromptRunException && $this->looksLikeLengthFailure($exception))) {
            $actual = (int) ($validationTrace['actual_word_count'] ?? $exception->context['actual_word_count'] ?? 0);
            $minimum = (int) ($validationTrace['minimum_acceptable_words'] ?? $exception->context['minimum_acceptable_words'] ?? 0);
            $target = (int) ($validationTrace['target_article_length'] ?? $exception->context['target_article_length'] ?? 0);

            $user = $minimum > 0
                ? sprintf(
                    'Validation: Bài viết quá ngắn — %d/%d từ tối thiểu%s. Model đã trả nội dung nhưng output không đạt yêu cầu.',
                    $actual > 0 ? $actual : 0,
                    $minimum,
                    $target > 0 ? sprintf(' (mục tiêu %d từ)', $target) : '',
                )
                : 'Validation: Output bị cắt ngắn hoặc không đạt độ dài tối thiểu.';

            return new AiNormalizedFailure(
                category: AiFailureCategory::Validation,
                code: AiNormalizedFailureCode::OutputTooShort,
                userMessage: $user,
                debugMessage: $exception->getMessage(),
                promptKey: $promptKey,
                stage: $stage,
                attemptCount: $actualAttempts,
                validationContract: $contract,
                routingTerminalReason: $routingTerminal,
                exceptionClass: $exception::class,
                correlationId: $correlationId,
                extra: [
                    'validators_applied' => $validationTrace['validators_applied'] ?? null,
                    'actual_word_count' => $actual > 0 ? $actual : null,
                    'minimum_acceptable_words' => $minimum > 0 ? $minimum : null,
                    'target_article_length' => $target > 0 ? $target : null,
                ],
            );
        }

        if ($exception instanceof InvalidOutput
            || ($exception instanceof PromptRunException
                && in_array((string) ($exception->context['failure_code'] ?? ''), [
                    PromptHookFailureCode::InvalidOutput->value,
                    PromptHookFailureCode::MissingRequiredSection->value,
                ], true))) {
            $code = AiNormalizedFailureCode::OutputSchemaInvalid;
            $failureCode = (string) ($exception->context['failure_code'] ?? '');
            if ($failureCode === PromptHookFailureCode::MissingRequiredSection->value) {
                $code = AiNormalizedFailureCode::OutputRequiredSectionMissing;
            }

            return new AiNormalizedFailure(
                category: AiFailureCategory::Validation,
                code: $code,
                userMessage: 'Validation: Output không đúng schema/format yêu cầu của prompt.',
                debugMessage: $exception->getMessage(),
                promptKey: $promptKey,
                stage: $stage,
                attemptCount: $actualAttempts,
                validationContract: $contract,
                routingTerminalReason: $routingTerminal,
                exceptionClass: $exception::class,
                correlationId: $correlationId,
                extra: ['validators_applied' => $validationTrace['validators_applied'] ?? null],
            );
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    private function routingZeroAttempts(
        ?Throwable $exception,
        array $routingAttempts,
        ?string $promptKey,
        ?string $stage,
        ?string $correlationId,
        ?string $routingTerminal,
    ): AiNormalizedFailure {
        $skipReasons = [];
        foreach ($routingAttempts as $row) {
            if (! is_array($row) || (string) ($row['result'] ?? '') !== 'skipped') {
                continue;
            }
            $reason = (string) ($row['skip_reason'] ?? '');
            if ($reason !== '') {
                $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
            }
        }

        $code = AiNormalizedFailureCode::RoutingAllCandidatesBlocked;
        if ($routingAttempts === []) {
            $code = AiNormalizedFailureCode::RoutingNoEligibleRoute;
        } elseif (isset($skipReasons['policy_free_only']) || isset($skipReasons['free_only'])) {
            $code = AiNormalizedFailureCode::RoutingFreeOnlyExhausted;
        } elseif (isset($skipReasons['free_attempt_budget_exhausted'])) {
            $code = AiNormalizedFailureCode::RoutingAttemptBudgetExhausted;
        }

        return new AiNormalizedFailure(
            category: AiFailureCategory::Routing,
            code: $code,
            userMessage: 'Routing: không có route AI nào đủ điều kiện; 0 API calls.',
            debugMessage: $exception?->getMessage() ?? 'No provider attempts',
            promptKey: $promptKey,
            stage: $stage,
            attemptCount: 0,
            routingTerminalReason: $routingTerminal ?? 'no_attemptable_routes',
            exceptionClass: $exception !== null ? $exception::class : null,
            correlationId: $correlationId,
            extra: ['skip_counts' => $skipReasons],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    private function fromProviderAttempts(
        ?Throwable $exception,
        array $routingAttempts,
        int $actualAttempts,
        ?string $promptKey,
        ?string $stage,
        ?string $correlationId,
        ?string $routingTerminal,
    ): ?AiNormalizedFailure {
        $lastFailed = null;
        foreach (array_reverse($routingAttempts) as $row) {
            if (is_array($row) && (string) ($row['result'] ?? '') === 'failed') {
                $lastFailed = $row;
                break;
            }
        }

        if ($lastFailed === null && ! ($exception instanceof ProviderRefused) && ! ($exception instanceof PromptRunException)) {
            return null;
        }

        $failureClass = (string) ($lastFailed['failure_class'] ?? '');
        $http = isset($lastFailed['http_status']) && is_numeric($lastFailed['http_status'])
            ? (int) $lastFailed['http_status']
            : null;

        [$code, $user] = $this->mapProviderCode($failureClass, $http, $exception);

        return new AiNormalizedFailure(
            category: AiFailureCategory::Provider,
            code: $code,
            userMessage: $user,
            debugMessage: $exception?->getMessage() ?? ($lastFailed['error'] ?? null),
            promptKey: $promptKey,
            stage: $stage,
            provider: isset($lastFailed['provider']) ? (string) $lastFailed['provider'] : null,
            connectionId: isset($lastFailed['connection_id']) && is_numeric($lastFailed['connection_id'])
                ? (int) $lastFailed['connection_id']
                : null,
            connectionName: isset($lastFailed['connection_name']) ? (string) $lastFailed['connection_name'] : null,
            logicalModel: isset($lastFailed['logical_model']) ? (string) $lastFailed['logical_model'] : null,
            physicalRoute: isset($lastFailed['physical_route']) ? (string) $lastFailed['physical_route'] : null,
            providerModel: isset($lastFailed['model']) ? (string) $lastFailed['model'] : (isset($lastFailed['candidate_model']) ? (string) $lastFailed['candidate_model'] : null),
            attemptCount: $actualAttempts,
            httpStatus: $http,
            routingTerminalReason: $routingTerminal,
            exceptionClass: $exception !== null ? $exception::class : null,
            correlationId: $correlationId,
        );
    }

    /**
     * @return array{0: AiNormalizedFailureCode, 1: string}
     */
    private function mapProviderCode(string $failureClass, ?int $http, ?Throwable $exception): array
    {
        if ($exception instanceof ProviderRefused || $failureClass === AiFailureClass::ProviderRefusal->value) {
            return [AiNormalizedFailureCode::ProviderRefused, 'Provider: model từ chối tạo nội dung.'];
        }
        if ($http === 402 || $failureClass === AiFailureClass::BillingExhausted->value
            || $failureClass === AiFailureClass::InsufficientBudgetForRequest->value) {
            return [AiNormalizedFailureCode::ProviderBillingLimit, 'Provider: hết credit / giới hạn thanh toán'
                .($http !== null ? ' · HTTP '.$http : '').'.'];
        }
        if ($failureClass === AiFailureClass::DailyFreeQuotaExhausted->value) {
            return [AiNormalizedFailureCode::ProviderRateLimited, 'Provider: hết hạn mức gọi model miễn phí trong ngày (free-models-per-day)'
                .($http !== null ? ' · HTTP '.$http : '').'.'];
        }
        if ($http === 429 || $failureClass === AiFailureClass::RateLimited->value) {
            return [AiNormalizedFailureCode::ProviderRateLimited, 'Provider: bị giới hạn tốc độ (rate limit)'
                .($http !== null ? ' · HTTP '.$http : '').'.'];
        }
        if ($http === 401 || $http === 403 || $failureClass === AiFailureClass::CredentialInvalid->value) {
            return [AiNormalizedFailureCode::ProviderAuthFailed, 'Provider: lỗi xác thực / quyền truy cập'
                .($http !== null ? ' · HTTP '.$http : '').'.'];
        }
        if ($http === 408 || $failureClass === AiFailureClass::TransientProvider->value) {
            return [AiNormalizedFailureCode::ProviderTimeout, 'Provider: hết thời gian chờ / tạm thời không khả dụng.'];
        }
        if ($failureClass === AiFailureClass::ProviderEmptyOutput->value) {
            return [AiNormalizedFailureCode::ProviderEmptyResponse, 'Provider: phản hồi rỗng.'];
        }
        if ($failureClass === AiFailureClass::ProviderInvalidOutput->value) {
            return [AiNormalizedFailureCode::ProviderInvalidResponse, 'Provider: phản hồi không hợp lệ.'];
        }

        return [
            AiNormalizedFailureCode::ProviderUnavailable,
            'Provider: tất cả lần gọi API đều thất bại'
                .($http !== null ? ' · HTTP '.$http : '').'.',
        ];
    }

    private function isSystemException(Throwable $exception): bool
    {
        if ($exception instanceof AiRoutesExhaustedException
            || $exception instanceof OutputTruncated
            || $exception instanceof InvalidOutput
            || $exception instanceof ProviderRefused) {
            return false;
        }

        if ($exception instanceof PromptRunException) {
            $code = (string) ($exception->context['failure_code'] ?? $exception->context['classification'] ?? '');
            if ($code === AiRoutesExhaustedException::CLASSIFICATION
                || str_starts_with($code, 'AI_')
                || in_array($code, [
                    PromptHookFailureCode::ProviderFailed->value,
                    PromptHookFailureCode::ProviderTimeout->value,
                    PromptHookFailureCode::ProviderRefused->value,
                    PromptHookFailureCode::OutputTruncated->value,
                    PromptHookFailureCode::InvalidOutput->value,
                    PromptHookFailureCode::MissingRequiredSection->value,
                ], true)) {
                return false;
            }

            // PromptRunException wrapping infrastructure still counts as provider/routing via other branches.
            if ($exception->getPrevious() !== null) {
                return false;
            }
        }

        // Uncaught Error / TypeError / RuntimeException from PHP app code.
        return $exception instanceof \Error
            || $exception instanceof \RuntimeException
            || $exception instanceof \LogicException;
    }

    private function looksLikeLengthFailure(PromptRunException $exception): bool
    {
        $code = (string) ($exception->context['failure_code'] ?? '');
        if ($code === PromptHookFailureCode::OutputTruncated->value) {
            return true;
        }
        $msg = strtolower($exception->getMessage());

        return str_contains($msg, 'minimum_length')
            || str_contains($msg, 'too short')
            || str_contains($msg, 'output_truncated')
            || isset($exception->context['minimum_acceptable_words']);
    }
}
