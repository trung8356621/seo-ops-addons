<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiErrorPresentation;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiNormalizedFailure;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationCategory;
use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationSource;
use Omnichannel\Addons\AiPrompt\Support\AiNormalizedFailureCode;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * Presentation adapter at AI error boundaries. Does not change routing/retry policy.
 */
final class AiErrorPresenter
{
    public function __construct(
        private readonly AiPrimaryFailureSelector $selector = new AiPrimaryFailureSelector(),
    ) {}

    /**
     * @param  array{
     *   step?: string|null,
     *   hook_key?: string|null,
     *   correlation_id?: string|null,
     * }  $context
     */
    public function presentFromThrowable(Throwable $exception, array $context = []): AiErrorPresentation
    {
        $hookKey = $this->stringOrNull($context['hook_key'] ?? null);
        $step = $this->stringOrNull($context['step'] ?? null) ?? $this->stepLabelForHook($hookKey);
        $correlationId = $this->stringOrNull($context['correlation_id'] ?? null);

        if ($exception instanceof MethodNotAllowedHttpException
            || $this->messageLooksLikeMethodMismatch($exception->getMessage())) {
            return $this->build(
                category: AiErrorPresentationCategory::HttpRouteMismatch,
                source: AiErrorPresentationSource::Internal,
                step: $step,
                hookKey: $hookKey,
                errorCode: 'HTTP_METHOD_NOT_ALLOWED',
                cause: 'API endpoint không hỗ trợ HTTP method được gửi.',
                upstreamMessage: $this->sanitize($exception->getMessage()),
                retryable: false,
                correlationId: $correlationId,
            );
        }

        if ($exception instanceof PromptHookException) {
            $hookPresentation = $this->fromPromptHookErrorCode($exception, $step, $hookKey, $correlationId);
            if ($hookPresentation !== null) {
                return $hookPresentation;
            }
            if ($exception->getPrevious() !== null) {
                return $this->presentFromThrowable($exception->getPrevious(), [
                    'step' => $step,
                    'hook_key' => $hookKey,
                    'correlation_id' => $correlationId,
                ]);
            }
        }

        $routingAttempts = [];
        $actualAttempts = 0;
        $retryable = null;
        $retryAfter = null;
        $fallbackExhausted = null;
        $validationTrace = [];

        if ($exception instanceof AiRoutesExhaustedException) {
            $routingAttempts = is_array($exception->context['routing_attempts'] ?? null)
                ? $exception->context['routing_attempts']
                : [];
            $actualAttempts = (int) ($exception->context['attempt_count'] ?? 0);
            $retryable = $exception->isRetryable();
            $retryAfter = $exception->retryAfterSeconds();
            $fallbackExhausted = true;
            $correlationId ??= $this->stringOrNull($exception->context['correlation_id'] ?? null);
            if (is_array($exception->context['validation_trace'] ?? null)) {
                $validationTrace = $exception->context['validation_trace'];
            }
        } elseif ($exception instanceof PromptRunException) {
            $routingAttempts = is_array($exception->context['routing_attempts'] ?? null)
                ? $exception->context['routing_attempts']
                : [];
            $actualAttempts = (int) ($exception->context['attempt_count'] ?? 0);
            $retryable = $exception->isRetryable();
            $correlationId ??= $this->stringOrNull($exception->context['correlation_id'] ?? null);
            if (($exception->context['classification'] ?? null) === AiRoutesExhaustedException::CLASSIFICATION) {
                $fallbackExhausted = true;
            }
            if (is_array($exception->context['validation_trace'] ?? null)) {
                $validationTrace = $exception->context['validation_trace'];
            }
        }

        $normalized = $this->selector->select(
            terminalException: $exception,
            routingAttempts: $routingAttempts,
            actualAttempts: $actualAttempts,
            promptKey: $hookKey,
            stage: $step,
            correlationId: $correlationId,
            validationTrace: $validationTrace,
        );

        return $this->fromNormalizedFailure(
            $normalized,
            $exception,
            $step,
            $hookKey,
            $fallbackExhausted,
            $retryable,
            $retryAfter,
            $routingAttempts,
        );
    }

    /**
     * @param  array{
     *   step?: string|null,
     *   hook_key?: string|null,
     * }  $context
     */
    public function presentFromNormalizedFailure(
        AiNormalizedFailure $failure,
        ?Throwable $exception = null,
        array $context = [],
    ): AiErrorPresentation {
        $hookKey = $this->stringOrNull($context['hook_key'] ?? null) ?? $failure->promptKey;
        $step = $this->stringOrNull($context['step'] ?? null)
            ?? $failure->stage
            ?? $this->stepLabelForHook($hookKey);

        $attempts = [];
        if ($exception instanceof PromptRunException || $exception instanceof AiRoutesExhaustedException) {
            $attempts = is_array($exception->context['routing_attempts'] ?? null)
                ? $exception->context['routing_attempts']
                : [];
        }

        return $this->fromNormalizedFailure(
            $failure,
            $exception,
            $step,
            $hookKey,
            $failure->routingTerminalReason === 'routes_exhausted' ? true : null,
            $exception instanceof PromptRunException ? $exception->isRetryable() : null,
            $exception instanceof AiRoutesExhaustedException ? $exception->retryAfterSeconds() : null,
            $attempts,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    private function fromNormalizedFailure(
        AiNormalizedFailure $failure,
        ?Throwable $exception,
        ?string $step,
        ?string $hookKey,
        ?bool $fallbackExhausted,
        ?bool $retryable,
        ?int $retryAfter,
        array $routingAttempts = [],
    ): AiErrorPresentation {
        $code = $failure->code instanceof AiNormalizedFailureCode
            ? $failure->code
            : null;
        $codeValue = $code?->value ?? (is_string($failure->code) ? $failure->code : null);

        $upstreamMessage = $this->extractUpstreamMessage($exception, $failure, $routingAttempts);
        $relay = $this->looksLikeRelay(
            $upstreamMessage,
            $failure->debugMessage,
            $exception?->getMessage(),
            $exception?->getPrevious()?->getMessage(),
        );

        [$category, $source, $cause] = $this->mapNormalized(
            $failure,
            $code,
            $relay,
            $fallbackExhausted,
        );

        $provider = $failure->provider;
        $model = $failure->providerModel ?? $failure->logicalModel;
        $http = $failure->httpStatus;

        $evidence = $this->firstFailedAttempt($routingAttempts);
        if ($evidence !== null) {
            $provider ??= $this->stringOrNull($evidence['provider'] ?? null);
            $model ??= $this->stringOrNull($evidence['model'] ?? $evidence['candidate_model'] ?? null);
            if ($http === null && isset($evidence['http_status']) && is_numeric($evidence['http_status'])) {
                $http = (int) $evidence['http_status'];
            }
            if ($upstreamMessage === null || $upstreamMessage === '') {
                $upstreamMessage = $this->sanitize((string) ($evidence['error'] ?? $evidence['message'] ?? ''));
            }
        }

        return $this->build(
            category: $category,
            source: $source,
            step: $step,
            hookKey: $hookKey,
            errorCode: $codeValue,
            cause: $cause,
            providerName: $provider,
            modelName: $model,
            upstreamHttpStatus: $http,
            upstreamErrorCode: $this->stringOrNull($failure->extra['provider_error_code'] ?? null),
            upstreamMessage: $upstreamMessage !== '' ? $upstreamMessage : null,
            attemptCount: $failure->attemptCount,
            fallbackExhausted: $fallbackExhausted,
            retryable: $retryable,
            retryAfter: $retryAfter,
            correlationId: $failure->correlationId,
        );
    }

    /**
     * @return array{0: AiErrorPresentationCategory, 1: AiErrorPresentationSource, 2: string}
     */
    private function mapNormalized(
        AiNormalizedFailure $failure,
        ?AiNormalizedFailureCode $code,
        bool $relay,
        ?bool $fallbackExhausted,
    ): array {
        if ($relay) {
            return [
                AiErrorPresentationCategory::ProviderRelay,
                AiErrorPresentationSource::Provider,
                'Request path cannot be relayed.',
            ];
        }

        if ($code === AiNormalizedFailureCode::ProviderAuthFailed) {
            return [
                AiErrorPresentationCategory::InvalidCredential,
                AiErrorPresentationSource::Credential,
                'API key không hợp lệ hoặc bị từ chối.',
            ];
        }
        if ($code === AiNormalizedFailureCode::ProviderBillingLimit) {
            return [
                AiErrorPresentationCategory::ProviderQuota,
                AiErrorPresentationSource::Provider,
                'Không đủ quota/credits để tiếp tục.',
            ];
        }
        if ($code === AiNormalizedFailureCode::ProviderRateLimited) {
            return [
                AiErrorPresentationCategory::ProviderRateLimit,
                AiErrorPresentationSource::Provider,
                'AI Provider giới hạn tốc độ hoặc hạn mức gọi.',
            ];
        }
        if ($code === AiNormalizedFailureCode::ProviderTimeout
            || $code === AiNormalizedFailureCode::ProviderUnavailable
            || $code === AiNormalizedFailureCode::ProviderRelayBlocked) {
            if ($code === AiNormalizedFailureCode::ProviderRelayBlocked) {
                return [
                    AiErrorPresentationCategory::ProviderRelay,
                    AiErrorPresentationSource::Provider,
                    'Request path cannot be relayed.',
                ];
            }

            return [
                AiErrorPresentationCategory::ProviderUnavailable,
                AiErrorPresentationSource::Provider,
                'AI Provider tạm thời không khả dụng.',
            ];
        }
        if ($code === AiNormalizedFailureCode::OutputTooShort
            || $code === AiNormalizedFailureCode::OutputSchemaInvalid
            || $code === AiNormalizedFailureCode::OutputFormatInvalid
            || $code === AiNormalizedFailureCode::OutputRequiredSectionMissing
            || $code === AiNormalizedFailureCode::OutputEmptyAfterParse) {
            return [
                AiErrorPresentationCategory::OutputValidation,
                AiErrorPresentationSource::Internal,
                'Kết quả không đáp ứng định dạng yêu cầu.',
            ];
        }
        if ($code === AiNormalizedFailureCode::InternalError
            || $code === AiNormalizedFailureCode::PromptBuildError
            || $code === AiNormalizedFailureCode::ContextBuildError
            || $code === AiNormalizedFailureCode::PersistenceError
            || $code === AiNormalizedFailureCode::WorkflowStateError
            || $code === AiNormalizedFailureCode::ValidatorInternalError) {
            return [
                AiErrorPresentationCategory::InternalFailure,
                AiErrorPresentationSource::Internal,
                'Lỗi nội bộ khi thực thi AI.',
            ];
        }

        if ($code === AiNormalizedFailureCode::RoutingNoEligibleRoute
            || $code === AiNormalizedFailureCode::RoutingAllCandidatesBlocked
            || $code === AiNormalizedFailureCode::RoutingAttemptBudgetExhausted
            || $code === AiNormalizedFailureCode::RoutingFreeOnlyExhausted
            || $fallbackExhausted === true) {
            if ($failure->provider !== null || $failure->httpStatus !== null) {
                return [
                    AiErrorPresentationCategory::RoutesExhausted,
                    AiErrorPresentationSource::Provider,
                    'Hệ thống đã hết tuyến AI khả dụng sau lỗi provider.',
                ];
            }

            return [
                AiErrorPresentationCategory::RoutesExhausted,
                AiErrorPresentationSource::Internal,
                'Không còn tuyến AI khả dụng.',
            ];
        }

        return match ($failure->category->value) {
            'VALIDATION' => [
                AiErrorPresentationCategory::OutputValidation,
                AiErrorPresentationSource::Internal,
                'Kết quả không đáp ứng định dạng yêu cầu.',
            ],
            'PROVIDER' => [
                AiErrorPresentationCategory::ProviderUnavailable,
                AiErrorPresentationSource::Provider,
                $this->sanitize($failure->userMessage) ?: 'Lỗi AI Provider.',
            ],
            'SYSTEM' => [
                AiErrorPresentationCategory::InternalFailure,
                AiErrorPresentationSource::Internal,
                'Lỗi hệ thống SEO-OPS.',
            ],
            default => [
                AiErrorPresentationCategory::Unknown,
                AiErrorPresentationSource::Unknown,
                'Chưa xác định nguyên nhân.',
            ],
        };
    }

    private function fromPromptHookErrorCode(
        PromptHookException $exception,
        ?string $step,
        ?string $hookKey,
        ?string $correlationId,
    ): ?AiErrorPresentation {
        return match ($exception->errorCode) {
            PromptHookErrorCode::HookInputInvalid => $this->build(
                category: AiErrorPresentationCategory::PromptInputValidation,
                source: AiErrorPresentationSource::Internal,
                step: $step,
                hookKey: $hookKey,
                errorCode: $exception->errorCodeValue(),
                cause: 'Dữ liệu đầu vào không hợp lệ.',
                upstreamMessage: $this->sanitize($exception->getMessage()),
                retryable: false,
                correlationId: $correlationId,
            ),
            PromptHookErrorCode::HookOutputInvalid => $this->build(
                category: AiErrorPresentationCategory::OutputValidation,
                source: AiErrorPresentationSource::Internal,
                step: $step,
                hookKey: $hookKey,
                errorCode: $exception->errorCodeValue(),
                cause: 'Kết quả không đáp ứng định dạng yêu cầu.',
                upstreamMessage: $this->sanitize($exception->getMessage()),
                retryable: true,
                correlationId: $correlationId,
            ),
            PromptHookErrorCode::HookPromptNotConfigured,
            PromptHookErrorCode::HookPromptMismatch,
            PromptHookErrorCode::HookModelUnsupported,
            PromptHookErrorCode::HookNotFound,
            PromptHookErrorCode::HookManifestInvalid,
            PromptHookErrorCode::HookDuplicateKey,
            PromptHookErrorCode::HookArticleForbidden,
            PromptHookErrorCode::HookArticleNotFound => $this->build(
                category: AiErrorPresentationCategory::InternalFailure,
                source: AiErrorPresentationSource::Internal,
                step: $step,
                hookKey: $hookKey,
                errorCode: $exception->errorCodeValue(),
                cause: $this->sanitize($exception->getMessage()) ?: 'Lỗi cấu hình Prompt Hook.',
                retryable: false,
                correlationId: $correlationId,
            ),
            PromptHookErrorCode::HookExecutionFailed => null,
        };
    }

    private function build(
        AiErrorPresentationCategory $category,
        AiErrorPresentationSource $source,
        ?string $step,
        ?string $hookKey,
        ?string $errorCode,
        string $cause,
        ?string $providerName = null,
        ?string $modelName = null,
        ?int $upstreamHttpStatus = null,
        ?string $upstreamErrorCode = null,
        ?string $upstreamMessage = null,
        ?int $attemptCount = null,
        ?bool $fallbackExhausted = null,
        ?bool $retryable = null,
        ?int $retryAfter = null,
        ?string $correlationId = null,
        ?string $requestId = null,
    ): AiErrorPresentation {
        $stepLabel = $step !== null && $step !== '' ? $step : 'Thực thi AI';
        $sourcePart = $source->labelVi();
        if ($providerName !== null && $providerName !== ''
            && in_array($source, [
                AiErrorPresentationSource::Provider,
                AiErrorPresentationSource::Credential,
                AiErrorPresentationSource::Network,
            ], true)) {
            $sourcePart .= ' — '.$providerName;
        }

        $message = sprintf(
            'Không thể hoàn tất %s. Nguồn lỗi: %s. %s',
            $stepLabel,
            $sourcePart,
            rtrim($cause),
        );

        $detailLines = [];
        $detailLines[] = 'Bước: '.$stepLabel;
        $detailLines[] = 'Nguồn: '.$sourcePart.' ('.$category->labelVi().')';
        if ($providerName) {
            $detailLines[] = 'Provider: '.$providerName;
        }
        if ($modelName) {
            $detailLines[] = 'Model: '.$modelName;
        }
        if ($hookKey) {
            $detailLines[] = 'Hook: '.$hookKey;
        }
        if ($errorCode) {
            $detailLines[] = 'Mã lỗi: '.$errorCode;
        }
        if ($upstreamHttpStatus !== null) {
            $line = 'HTTP '.$upstreamHttpStatus;
            if ($upstreamMessage) {
                $line .= ': '.$upstreamMessage;
            }
            $detailLines[] = $line;
        } elseif ($upstreamMessage) {
            $detailLines[] = 'Upstream: '.$upstreamMessage;
        }
        if ($attemptCount !== null) {
            $detailLines[] = 'Số lần thử: '.$attemptCount;
        }
        if ($fallbackExhausted === true) {
            $detailLines[] = 'Hệ quả: đã hết tuyến AI khả dụng.';
        }
        if ($retryable === true) {
            $detailLines[] = 'Có thể thử lại.';
        } elseif ($retryable === false) {
            $detailLines[] = 'Cần kiểm tra cấu hình / nhà cung cấp.';
        }
        if ($retryAfter !== null && $retryAfter > 0) {
            $detailLines[] = 'Retry after: '.$retryAfter.'s';
        }
        if ($correlationId) {
            $detailLines[] = 'Correlation: '.$correlationId;
        }

        return new AiErrorPresentation(
            category: $category,
            source: $source,
            message: $message,
            errorCode: $errorCode,
            providerName: $providerName,
            modelName: $modelName,
            upstreamHttpStatus: $upstreamHttpStatus,
            upstreamErrorCode: $upstreamErrorCode,
            upstreamMessage: $upstreamMessage,
            attemptCount: $attemptCount,
            fallbackExhausted: $fallbackExhausted,
            retryable: $retryable,
            retryAfter: $retryAfter,
            requestId: $requestId,
            correlationId: $correlationId,
            step: $stepLabel,
            hookKey: $hookKey,
            detailLines: $detailLines,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     */
    private function extractUpstreamMessage(
        ?Throwable $exception,
        AiNormalizedFailure $failure,
        array $routingAttempts,
    ): ?string {
        $evidence = $this->firstFailedAttempt($routingAttempts);
        $candidates = [
            is_array($evidence) ? (string) ($evidence['error'] ?? '') : '',
            is_array($evidence) ? (string) ($evidence['fallback_reason'] ?? '') : '',
            is_array($evidence) ? (string) ($evidence['message'] ?? '') : '',
            $failure->debugMessage,
            $exception?->getPrevious()?->getMessage(),
            $exception?->getMessage(),
        ];
        foreach ($candidates as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $sanitized = $this->sanitize($raw);
            if ($sanitized === '' || str_contains($sanitized, 'AI_ROUTES_EXHAUSTED')) {
                continue;
            }

            return $sanitized;
        }

        return null;
    }

    private function looksLikeRelay(?string ...$parts): bool
    {
        foreach ($parts as $part) {
            if (! is_string($part) || $part === '') {
                continue;
            }
            $lower = strtolower($part);
            if (str_contains($lower, 'cannot be relayed')
                || str_contains($lower, 'path cannot be relayed')
                || str_contains($lower, 'relay path unavailable')
                || str_contains($lower, 'relay_path_blocked')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>|null
     */
    private function firstFailedAttempt(array $attempts): ?array
    {
        foreach ($attempts as $row) {
            if (is_array($row) && (string) ($row['result'] ?? '') === 'failed') {
                return $row;
            }
        }

        return null;
    }

    private function stepLabelForHook(?string $hookKey): string
    {
        return match ($hookKey) {
            'article.meta_description_suggestion' => 'Meta Description',
            'article.title_suggestion' => 'Title',
            'article.outline.generate', 'article.outline.structure.generate' => 'Outline',
            'article.vocabulary.generate' => 'Vocabulary',
            'article.content.generate', 'article.content.improve' => 'Article Generation',
            'article.faq.generate' => 'FAQ',
            default => $hookKey !== null && $hookKey !== '' ? $hookKey : 'Thực thi AI',
        };
    }

    private function messageLooksLikeMethodMismatch(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'method is not supported')
            || str_contains($lower, 'supported methods:');
    }

    private function sanitize(?string $message): string
    {
        $message = (string) $message;
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/sk-[a-z0-9\-]+/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|token|password|secret)([=:]+\s*)\S+/i', '$1$2[redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 300);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
