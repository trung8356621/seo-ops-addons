<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;

final class AiRoutesExhaustedException extends PromptRunException
{
    public const CLASSIFICATION = 'AI_ROUTES_EXHAUSTED';

    /**
     * @param  list<array<string, mixed>>  $routingAttempts
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        int $attemptCount,
        array $routingAttempts = [],
        ?\Throwable $previous = null,
        ?int $promptResultId = null,
        array $diagnostics = [],
    ) {
        $classified = (new AiRoutesExhaustionClassifier())->classify($routingAttempts, $attemptCount);
        $retryable = (bool) ($diagnostics['retryable'] ?? $classified['retryable']);
        $kind = (string) ($diagnostics['exhaustion_kind'] ?? $classified['exhaustion_kind']);
        $mergedDiagnostics = array_merge($classified, $diagnostics, [
            'attempt_count' => $attemptCount,
            'routing_attempts' => $routingAttempts,
        ]);

        $context = [
            'classification' => self::CLASSIFICATION,
            'user_message' => self::userFacingMessage($attemptCount, $routingAttempts, $retryable, $mergedDiagnostics),
            'technical_details' => self::CLASSIFICATION,
            'retryable' => $retryable,
            'temporary' => $retryable,
            'attempt_count' => $attemptCount,
            'routing_attempts' => $routingAttempts,
            'exhaustion_kind' => $kind,
            'health_skip_count' => $classified['health_skip_count'],
            'hard_skip_count' => $classified['hard_skip_count'],
            'transient_failure_count' => $classified['transient_failure_count'],
            'hard_failure_count' => $classified['hard_failure_count'],
        ];
        foreach ($diagnostics as $key => $value) {
            if (! is_string($key) || $key === '' || array_key_exists($key, $context)) {
                continue;
            }
            $context[$key] = $value;
        }
        if ($promptResultId !== null && $promptResultId > 0) {
            $context['prompt_result_id'] = $promptResultId;
        }

        parent::__construct(
            message: self::CLASSIFICATION.': '.self::technicalAttemptPhrase($attemptCount, $mergedDiagnostics),
            code: 0,
            previous: $previous,
            context: $context,
        );
    }

    public function isRetryable(): bool
    {
        return (bool) ($this->context['retryable'] ?? false);
    }

    public function exhaustionKind(): string
    {
        return (string) ($this->context['exhaustion_kind'] ?? AiRoutesExhaustionClassifier::KIND_HARD);
    }

    public function retryAfterSeconds(): ?int
    {
        $value = $this->context['retry_after_seconds'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public static function technicalAttemptPhrase(int $attemptCount, array $diagnostics = []): string
    {
        if ($attemptCount <= 0) {
            $skipCounts = is_array($diagnostics['skip_counts'] ?? null) ? $diagnostics['skip_counts'] : [];
            if ((int) ($skipCounts['connection_locked'] ?? 0) > 0) {
                $reason = (string) ($diagnostics['last_failure_class'] ?? $diagnostics['connection_lock_reason'] ?? '');
                if ($reason === AiFailureClass::CredentialInvalid->value || str_contains($reason, 'credential')) {
                    return 'All eligible routes blocked by connection lock (invalid credentials)';
                }
                if ($reason === AiFailureClass::InsufficientBudgetForRequest->value
                    || $reason === AiFailureClass::BillingExhausted->value
                    || (int) ($skipCounts['connection_paid_locked'] ?? 0) > 0) {
                    return 'All eligible routes blocked by connection lock (credits/quota)';
                }

                return 'All eligible routes blocked by connection lock';
            }
            if ((int) ($skipCounts['connection_paid_locked'] ?? 0) > 0
                || (int) ($skipCounts['connection_suppressed'] ?? 0) > 0) {
                return 'All eligible routes blocked by connection-level failure';
            }
            if ((int) ($skipCounts['model_unavailable'] ?? 0) > 0
                && (int) ($skipCounts['model_cooldown'] ?? 0) === 0
                && (int) ($skipCounts['connection_cooldown'] ?? 0) === 0) {
                return 'All eligible routes marked unavailable';
            }
            $rejectionCounts = is_array($diagnostics['live_compatible_rejection_counts'] ?? null)
                ? $diagnostics['live_compatible_rejection_counts']
                : [];
            if ((int) ($rejectionCounts['missing_credentials'] ?? 0) > 0
                && (int) ($diagnostics['live_compatible_count'] ?? 0) === 0) {
                return 'No usable API credentials for eligible models';
            }

            return 'No eligible AI route was attempted';
        }

        return $attemptCount.' AI attempt(s) failed';
    }

    /**
     * Short actionable copy for Content Project / operator UI.
     * Technical classification stays on getMessage() / context.
     *
     * @param  list<array<string, mixed>>  $routingAttempts
     * @param  array<string, mixed>  $diagnostics
     */
    public static function userFacingMessage(
        int $attemptCount,
        array $routingAttempts = [],
        bool $retryable = false,
        array $diagnostics = [],
    ): string {
        $skipCounts = is_array($diagnostics['skip_counts'] ?? null) ? $diagnostics['skip_counts'] : [];
        $failCounts = is_array($diagnostics['fail_counts'] ?? null) ? $diagnostics['fail_counts'] : [];
        $rejectionCounts = is_array($diagnostics['live_compatible_rejection_counts'] ?? null)
            ? $diagnostics['live_compatible_rejection_counts']
            : [];

        $hasCredential = ((int) ($failCounts[AiFailureClass::CredentialInvalid->value] ?? 0) > 0)
            || ((int) ($skipCounts['connection_locked'] ?? 0) > 0
                && ((string) ($diagnostics['connection_lock_reason'] ?? '') === AiFailureClass::CredentialInvalid->value
                    || (string) ($diagnostics['last_failure_class'] ?? '') === AiFailureClass::CredentialInvalid->value));

        $hasCredits = ((int) ($failCounts[AiFailureClass::InsufficientBudgetForRequest->value] ?? 0) > 0)
            || ((int) ($failCounts[AiFailureClass::BillingExhausted->value] ?? 0) > 0)
            || ((int) ($skipCounts['connection_paid_locked'] ?? 0) > 0);

        $noConfigured = ((int) ($diagnostics['live_compatible_count'] ?? -1) === 0
                && (int) ($rejectionCounts['missing_credentials'] ?? 0) > 0)
            || ((int) ($diagnostics['eligible_count'] ?? -1) === 0
                && (int) ($diagnostics['candidates_before_production_eligibility'] ?? 0) === 0);

        if ($noConfigured || ((int) ($rejectionCounts['missing_credentials'] ?? 0) > 0 && $attemptCount <= 0 && (int) ($diagnostics['eligible_count'] ?? 0) === 0)) {
            return 'Không có kết nối AI khả dụng. Hãy kiểm tra Cài đặt → API Connections.';
        }

        if ($hasCredential && ! $hasCredits) {
            $provider = trim((string) ($diagnostics['last_failure_provider'] ?? ''));
            if ($provider !== '') {
                return 'Kết nối '.$provider.' không hợp lệ. Hãy kiểm tra API key trong Cài đặt → API Connections.';
            }

            return 'Kết nối AI không hợp lệ. Hãy kiểm tra API key trong Cài đặt → API Connections.';
        }

        if ($hasCredits) {
            return 'Kết nối AI hiện không còn hạn mức sử dụng và không có kết nối dự phòng khả dụng.';
        }

        if ((int) ($diagnostics['coverage_missing_count'] ?? 0) > 0) {
            return 'Kết nối AI hiện tại không khả dụng và routing profile chưa có kết nối dự phòng phù hợp.';
        }

        if ($attemptCount <= 0
            && ((int) ($skipCounts['connection_locked'] ?? 0) > 0
                || (int) ($skipCounts['connection_suppressed'] ?? 0) > 0
                || (int) ($skipCounts['connection_paid_locked'] ?? 0) > 0
                || (int) ($skipCounts['paid_lane_suppressed'] ?? 0) > 0
                || (int) ($skipCounts['model_unavailable'] ?? 0) > 0)) {
            return 'Không còn kết nối AI khả dụng cho tác vụ này. Hãy kiểm tra API Connections và routing profile.';
        }

        if ($retryable) {
            return 'Dịch vụ AI tạm thời không khả dụng. Hệ thống đã thử các kết nối dự phòng.';
        }

        return 'Không thể hoàn tất yêu cầu AI. Hãy kiểm tra Cài đặt → API Connections rồi thử lại.';
    }

    /** Warning when paid lane is blocked but free/other routes remain eligible (not a hard fail). */
    public static function paidLaneBlockedWarning(): string
    {
        return 'OpenRouter đã hết hạn mức cho các model trả phí. Hệ thống vẫn có thể dùng các model miễn phí dự phòng.';
    }

    public static function fallbackSucceededWarning(): string
    {
        return 'Route AI chính không khả dụng. Hệ thống đã chuyển sang kết nối dự phòng.';
    }
}
