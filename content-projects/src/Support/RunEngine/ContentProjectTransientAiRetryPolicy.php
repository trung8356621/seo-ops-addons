<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Support\AiRoutesExhaustionClassifier;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Throwable;

/**
 * Detects retryable AI route exhaustion for Content Project article defer.
 */
final class ContentProjectTransientAiRetryPolicy
{
    public const MAX_TRANSIENT_ARTICLE_ATTEMPTS = 3;

    public const PAYLOAD_FLAG = 'ai_transient_retry';

    public const SETTINGS_KEY = 'ai_transient_retry';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromException(Throwable $exception): ?array
    {
        if ($exception instanceof AiRoutesExhaustedException) {
            if (! $exception->isRetryable()) {
                return null;
            }

            return [
                self::PAYLOAD_FLAG => true,
                'retryable' => true,
                'exhaustion_kind' => $exception->exhaustionKind(),
                'retry_after_seconds' => $exception->retryAfterSeconds() ?? 30,
                'attempt_count' => (int) ($exception->context['attempt_count'] ?? 0),
                'classification' => AiRoutesExhaustedException::CLASSIFICATION,
            ];
        }

        if (! ContentProjectBatchFailureSignature::messageLooksLikeRouteExhaustion($exception->getMessage())) {
            return null;
        }

        // Legacy string-only path: treat "No eligible" / cooldown-style as temporary.
        $lower = strtolower($exception->getMessage());
        $temporary = str_contains($lower, 'no eligible')
            || str_contains($lower, '0 ai attempt')
            || str_contains($lower, 'cooldown')
            || preg_match('/\b[1-9]\d*\s+ai attempt/', $lower) === 1;

        if (! $temporary) {
            return null;
        }

        return [
            self::PAYLOAD_FLAG => true,
            'retryable' => true,
            'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_MIXED,
            'retry_after_seconds' => 30,
            'classification' => AiRoutesExhaustedException::CLASSIFICATION,
        ];
    }

    /**
     * @param  array<string, mixed>  $itemRow
     */
    public static function fromFailedItemRow(array $itemRow): ?array
    {
        $haystack = strtolower(trim(implode(' ', [
            (string) ($itemRow['message'] ?? ''),
            (string) ($itemRow['error_detail'] ?? ''),
            (string) ($itemRow['error_code'] ?? ''),
        ])));
        if (! ContentProjectBatchFailureSignature::messageLooksLikeRouteExhaustion($haystack)) {
            return null;
        }

        // Prefer structured step flags when present.
        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $msg = strtolower((string) ($step['message'] ?? ''));
            if (ContentProjectBatchFailureSignature::messageLooksLikeRouteExhaustion($msg)) {
                $kind = str_contains($msg, 'no eligible')
                    ? AiRoutesExhaustionClassifier::KIND_TEMPORARY_HEALTH
                    : AiRoutesExhaustionClassifier::KIND_TRANSIENT_PROVIDER;

                return [
                    self::PAYLOAD_FLAG => true,
                    'retryable' => true,
                    'exhaustion_kind' => $kind,
                    'retry_after_seconds' => 30,
                    'classification' => AiRoutesExhaustedException::CLASSIFICATION,
                    'failed_hook' => (string) ($step['hook_key'] ?? ''),
                    'outline_subtask' => (string) ($step['outline_subtask'] ?? ''),
                ];
            }
        }

        return [
            self::PAYLOAD_FLAG => true,
            'retryable' => true,
            'exhaustion_kind' => AiRoutesExhaustionClassifier::KIND_MIXED,
            'retry_after_seconds' => 30,
            'classification' => AiRoutesExhaustedException::CLASSIFICATION,
        ];
    }

    public static function isTransientResult(ArticleExecutionResult $result): bool
    {
        if (! empty($result->payload[self::PAYLOAD_FLAG])) {
            return true;
        }

        return $result->status === ContentProjectArticleSemanticStatus::Pending
            && ContentProjectBatchFailureSignature::messageLooksLikeRouteExhaustion($result->message);
    }

    public static function delaySeconds(int $nextAttempt, ?int $retryAfterSeconds): int
    {
        $floor = $nextAttempt <= 2 ? 30 : 90;
        $fromHealth = $retryAfterSeconds !== null && $retryAfterSeconds > 0 ? $retryAfterSeconds : $floor;

        return max($floor, min(300, $fromHealth));
    }

    public static function deferMessage(int $delaySeconds): string
    {
        $minutes = max(1, (int) ceil($delaySeconds / 60));

        return 'Đang chờ AI — thử lại sau ~'.$minutes.' phút';
    }
}
