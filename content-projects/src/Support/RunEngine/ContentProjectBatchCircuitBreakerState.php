<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

/**
 * Pure consecutive-failure counter for Content Project batch runs.
 * Engine persists returned state under settings.php_engine.
 */
final class ContentProjectBatchCircuitBreakerState
{
    public const THRESHOLD = ContentProjectBatchFailureSignature::THRESHOLD;

    public const TRIGGER_CONSECUTIVE_SIGNATURE = 'consecutive_signature';

    public const TRIGGER_AGGREGATE_FAILED_ITEMS = 'aggregate_failed_items';

    /**
     * @param  array<string, mixed>  $engine
     * @return array{engine: array<string, mixed>, tripped: bool, count: int, signature: string, failure_count: int, trigger: string|null}
     */
    public static function recordFailure(array $engine, string $signature): array
    {
        $signature = trim($signature);
        $prev = is_array($engine['consecutive_failure'] ?? null) ? $engine['consecutive_failure'] : [];
        $prevSig = isset($prev['signature']) ? (string) $prev['signature'] : '';
        $prevCount = (int) ($prev['count'] ?? 0);

        $count = ($prevSig === $signature && $signature !== '') ? ($prevCount + 1) : 1;
        $aggregate = is_array($engine['aggregate_failure'] ?? null) ? $engine['aggregate_failure'] : [];
        $failureCount = max(0, (int) ($aggregate['count'] ?? 0)) + 1;

        $engine['consecutive_failure'] = [
            'signature' => $signature !== '' ? $signature : null,
            'count' => $count,
        ];
        $engine['aggregate_failure'] = [
            'count' => $failureCount,
        ];

        $consecutiveTripped = $signature !== '' && $count >= self::THRESHOLD;
        $aggregateTripped = $failureCount >= self::THRESHOLD;
        $tripped = $consecutiveTripped || $aggregateTripped;
        $trigger = $consecutiveTripped
            ? self::TRIGGER_CONSECUTIVE_SIGNATURE
            : ($aggregateTripped ? self::TRIGGER_AGGREGATE_FAILED_ITEMS : null);
        if ($tripped) {
            $engine['circuit_breaker'] = [
                'stopped' => true,
                'signature' => $signature,
                'count' => $count,
                'failure_count' => $failureCount,
                'trigger' => $trigger,
            ];
        }

        return [
            'engine' => $engine,
            'tripped' => $tripped,
            'count' => $count,
            'signature' => $signature,
            'failure_count' => $failureCount,
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    public static function recordSuccess(array $engine): array
    {
        $engine['consecutive_failure'] = [
            'signature' => null,
            'count' => 0,
        ];

        return $engine;
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    public static function isStopped(array $engine): bool
    {
        $breaker = is_array($engine['circuit_breaker'] ?? null) ? $engine['circuit_breaker'] : null;

        return is_array($breaker) && ! empty($breaker['stopped']);
    }

    /**
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    public static function clearForResume(array $engine): array
    {
        unset(
            $engine['circuit_breaker'],
            $engine['finalized_at'],
            $engine['final_status'],
            $engine['stop_requested_at'],
            $engine['stop_requested_by'],
            $engine['stop_reason'],
            $engine['aggregate_failure'],
        );
        $engine['consecutive_failure'] = [
            'signature' => null,
            'count' => 0,
        ];

        return $engine;
    }
}
