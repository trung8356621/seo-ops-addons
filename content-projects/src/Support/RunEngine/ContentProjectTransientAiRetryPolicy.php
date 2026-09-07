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
     * Routing fields carried verbatim into the returned retry meta.
     *
     * @var list<string>
     */
    private const DIAGNOSTIC_KEYS = [
        'temporary',
        'attempt_count',
        'routing_attempts',
        'health_skip_count',
        'hard_skip_count',
        'transient_failure_count',
        'hard_failure_count',
        'free_budget_skip_count',
        'skip_counts',
        'fail_counts',
        'profile',
        'eligible_models',
        'eligible_count',
        'candidates_before_health',
        'candidates_before_production_eligibility',
        'candidates_after_production_eligibility',
        'production_eligibility_skip_count',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function fromException(Throwable $exception): ?array
    {
        $exhausted = self::routeExhaustionInChain($exception);
        if ($exhausted !== null) {
            if (! $exhausted->isRetryable()) {
                return null;
            }

            return [
                self::PAYLOAD_FLAG => true,
                'retryable' => true,
                'exhaustion_kind' => $exhausted->exhaustionKind(),
                'retry_after_seconds' => $exhausted->retryAfterSeconds() ?? 30,
                'attempt_count' => (int) ($exhausted->context['attempt_count'] ?? 0),
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

    private static function routeExhaustionInChain(Throwable $exception): ?AiRoutesExhaustedException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AiRoutesExhaustedException) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Structured routing metadata is the source of truth; message parsing is the
     * legacy fallback used only when the run carries no structured signal.
     *
     * @param  array<string, mixed>  $itemRow
     * @return array<string, mixed>|null
     */
    public static function fromFailedItemRow(array $itemRow): ?array
    {
        $structured = self::structuredRoutingMetadata($itemRow);
        if ($structured !== null) {
            return self::fromStructuredMetadata($structured);
        }

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

    /**
     * @param  array<string, mixed>  $itemRow
     * @return array<string, mixed>|null
     */
    private static function structuredRoutingMetadata(array $itemRow): ?array
    {
        $found = self::routingMetadataFrom($itemRow);
        if ($found !== null) {
            return $found;
        }

        $steps = is_array($itemRow['steps'] ?? null) ? $itemRow['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $found = self::routingMetadataFrom($step);
            if ($found === null) {
                continue;
            }
            $found['failed_hook'] = (string) ($step['hook_key'] ?? '');
            $found['outline_subtask'] = (string) ($step['outline_subtask'] ?? '');

            return $found;
        }

        return null;
    }

    /**
     * Reads `ai_routing` (preferred) or flattened routing fields off one row.
     * Returns null when the row carries no decisive routing signal.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function routingMetadataFrom(array $row): ?array
    {
        $nested = is_array($row['ai_routing'] ?? null) ? $row['ai_routing'] : [];
        $keys = array_merge(
            ['classification', 'retryable', 'exhaustion_kind', 'retry_after_seconds'],
            self::DIAGNOSTIC_KEYS,
        );

        $meta = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $nested) && $nested[$key] !== null) {
                $meta[$key] = $nested[$key];
            } elseif (array_key_exists($key, $row) && $row[$key] !== null) {
                $meta[$key] = $row[$key];
            }
        }

        $isExhaustion = (string) ($meta['classification'] ?? '') === AiRoutesExhaustedException::CLASSIFICATION;
        $hasKind = is_string($meta['exhaustion_kind'] ?? null) && $meta['exhaustion_kind'] !== '';
        $hasRetryableWithAttempts = array_key_exists('retryable', $meta)
            && is_array($meta['routing_attempts'] ?? null);

        return $isExhaustion || $hasKind || $hasRetryableWithAttempts ? $meta : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private static function fromStructuredMetadata(array $meta): ?array
    {
        $kind = is_string($meta['exhaustion_kind'] ?? null) && $meta['exhaustion_kind'] !== ''
            ? (string) $meta['exhaustion_kind']
            : null;

        $retryable = array_key_exists('retryable', $meta)
            ? self::truthy($meta['retryable'])
            : ($kind !== null && $kind !== AiRoutesExhaustionClassifier::KIND_HARD);

        if (! $retryable) {
            return null;
        }

        $retryAfter = is_numeric($meta['retry_after_seconds'] ?? null)
            ? max(0, (int) $meta['retry_after_seconds'])
            : 0;

        $out = [
            self::PAYLOAD_FLAG => true,
            'retryable' => true,
            'exhaustion_kind' => $kind ?? AiRoutesExhaustionClassifier::KIND_MIXED,
            'retry_after_seconds' => $retryAfter > 0 ? $retryAfter : 30,
            'classification' => AiRoutesExhaustedException::CLASSIFICATION,
        ];

        foreach (self::DIAGNOSTIC_KEYS as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== []) {
                $out[$key] = $meta[$key];
            }
        }

        foreach (['failed_hook', 'outline_subtask'] as $key) {
            if (($meta[$key] ?? '') !== '') {
                $out[$key] = (string) $meta[$key];
            }
        }

        return $out;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
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
