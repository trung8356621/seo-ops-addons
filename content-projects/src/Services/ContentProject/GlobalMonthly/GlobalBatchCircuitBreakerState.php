<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

/**
 * Global Monthly batch-scoped consecutive failure guard.
 * Survives child-run boundaries (1-item Auto Cost runs cannot trip project-run CB).
 *
 * @phpstan-type BreakerArray array{
 *   stopped?: bool,
 *   consecutive_item_failures?: int,
 *   threshold?: int,
 *   reason?: string,
 *   last_error_category?: ?string,
 *   last_error_message?: ?string,
 *   last_batch_item_id?: ?int,
 *   last_child_run_id?: ?int,
 *   trigger?: string,
 *   stopped_at?: string,
 * }
 */
final class GlobalBatchCircuitBreakerState
{
    public const THRESHOLD = 3;

    public const TRIGGER_CONSECUTIVE_ITEM_FAILURES = 'consecutive_item_failures';

    public const TRIGGER_INFRASTRUCTURE = 'infrastructure_failure';

    public const SETTINGS_KEY = 'circuit_breaker';

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function isStopped(array $settings): bool
    {
        $breaker = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : null;

        return is_array($breaker) && ! empty($breaker['stopped']);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function recordSuccess(array $settings): array
    {
        $breaker = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];
        if (! empty($breaker['stopped'])) {
            return $settings;
        }

        $settings[self::SETTINGS_KEY] = array_merge($breaker, [
            'stopped' => false,
            'consecutive_item_failures' => 0,
            'last_error_category' => null,
            'last_error_message' => null,
        ]);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{settings: array<string, mixed>, tripped: bool, consecutive: int}
     */
    public static function recordItemFailure(
        array $settings,
        string $category,
        ?string $message,
        ?int $batchItemId,
        ?int $childRunId,
        bool $infrastructure = false,
    ): array {
        $breaker = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];
        if (! empty($breaker['stopped'])) {
            return ['settings' => $settings, 'tripped' => true, 'consecutive' => (int) ($breaker['consecutive_item_failures'] ?? 0)];
        }

        $consecutive = max(0, (int) ($breaker['consecutive_item_failures'] ?? 0)) + 1;
        $threshold = self::THRESHOLD;
        $tripped = $infrastructure || $consecutive >= $threshold;
        $trigger = $infrastructure
            ? self::TRIGGER_INFRASTRUCTURE
            : self::TRIGGER_CONSECUTIVE_ITEM_FAILURES;

        $reason = $infrastructure
            ? 'Infrastructure/systemic failure — Global Batch stopped to prevent further damage.'
            : sprintf(
                'Circuit breaker: %d consecutive item failures (threshold %d).',
                $consecutive,
                $threshold,
            );

        $breaker = [
            'stopped' => $tripped,
            'consecutive_item_failures' => $consecutive,
            'threshold' => $threshold,
            'reason' => $tripped ? $reason : ($breaker['reason'] ?? null),
            'last_error_category' => $category !== '' ? $category : null,
            'last_error_message' => $message !== null && $message !== '' ? mb_substr($message, 0, 500) : null,
            'last_batch_item_id' => $batchItemId,
            'last_child_run_id' => $childRunId,
            'trigger' => $tripped ? $trigger : ($breaker['trigger'] ?? null),
            'stopped_at' => $tripped ? now()->toIso8601String() : ($breaker['stopped_at'] ?? null),
        ];
        $settings[self::SETTINGS_KEY] = $breaker;
        if ($tripped) {
            $settings['stop_reason'] = $reason;
            $settings['auto_rerun_disabled'] = true;
        }

        return ['settings' => $settings, 'tripped' => $tripped, 'consecutive' => $consecutive];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function clearForResume(array $settings): array
    {
        unset($settings[self::SETTINGS_KEY], $settings['stop_reason'], $settings['auto_rerun_disabled']);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function consecutiveFailures(array $settings): int
    {
        $breaker = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];

        return max(0, (int) ($breaker['consecutive_item_failures'] ?? 0));
    }
}
