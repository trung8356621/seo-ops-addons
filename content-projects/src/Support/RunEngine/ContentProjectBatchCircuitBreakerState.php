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

    /**
     * @param  array<string, mixed>  $engine
     * @return array{engine: array<string, mixed>, tripped: bool, count: int, signature: string}
     */
    public static function recordFailure(array $engine, string $signature): array
    {
        $signature = trim($signature);
        $prev = is_array($engine['consecutive_failure'] ?? null) ? $engine['consecutive_failure'] : [];
        $prevSig = isset($prev['signature']) ? (string) $prev['signature'] : '';
        $prevCount = (int) ($prev['count'] ?? 0);

        $count = ($prevSig === $signature && $signature !== '') ? ($prevCount + 1) : 1;

        $engine['consecutive_failure'] = [
            'signature' => $signature !== '' ? $signature : null,
            'count' => $count,
        ];

        $tripped = $signature !== '' && $count >= self::THRESHOLD;
        if ($tripped) {
            $engine['circuit_breaker'] = [
                'stopped' => true,
                'signature' => $signature,
                'count' => $count,
            ];
        }

        return [
            'engine' => $engine,
            'tripped' => $tripped,
            'count' => $count,
            'signature' => $signature,
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
        unset($engine['circuit_breaker'], $engine['finalized_at'], $engine['final_status']);
        $engine['consecutive_failure'] = [
            'signature' => null,
            'count' => 0,
        ];

        return $engine;
    }
}
