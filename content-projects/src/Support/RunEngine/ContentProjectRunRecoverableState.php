<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;

/**
 * Persisted recoverable terminal markers on php_engine settings.
 * Distinct from normal item failure, stop/cancel, and transient AI retry.
 */
final class ContentProjectRunRecoverableState
{
    public const REASON_CIRCUIT_BREAKER = 'circuit_breaker';

    public const REASON_WORKER_LOST = 'worker_lost';

    public const ERROR_CODE_WORKER_LOST = 'WORKER_LOST';

    public const ERROR_CODE_EXECUTION_INTERRUPTED = 'EXECUTION_INTERRUPTED';

    public const SETTINGS_KEY = 'recoverable';

    public const WORKER_LOST_KEY = 'worker_lost';

    /**
     * @param  array<string, mixed>  $engine
     */
    public static function reasonFromEngine(array $engine): ?string
    {
        $recoverable = is_array($engine[self::SETTINGS_KEY] ?? null)
            ? $engine[self::SETTINGS_KEY]
            : null;
        if (is_array($recoverable) && ! empty($recoverable['reason'])) {
            $reason = (string) $recoverable['reason'];
            if (in_array($reason, [self::REASON_CIRCUIT_BREAKER, self::REASON_WORKER_LOST], true)) {
                return $reason;
            }
        }

        $breaker = is_array($engine['circuit_breaker'] ?? null) ? $engine['circuit_breaker'] : null;
        if ($breaker !== null && ! empty($breaker['stopped'])) {
            return self::REASON_CIRCUIT_BREAKER;
        }

        $lost = is_array($engine[self::WORKER_LOST_KEY] ?? null) ? $engine[self::WORKER_LOST_KEY] : null;
        if ($lost !== null && ! empty($lost['stopped'])) {
            return self::REASON_WORKER_LOST;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    public static function isRecoverableEngine(array $engine): bool
    {
        return self::reasonFromEngine($engine) !== null;
    }

    public static function isRecoverableRun(SeoProjectRun $run): bool
    {
        if ((string) $run->status !== SeoProjectRun::STATUS_FAILED) {
            return false;
        }

        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings['php_engine'] ?? null) ? $settings['php_engine'] : [];

        return self::isRecoverableEngine($engine);
    }

    /**
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    public static function stampCircuitBreaker(array $engine, string $message, string $signature, int $count): array
    {
        $engine[self::SETTINGS_KEY] = [
            'reason' => self::REASON_CIRCUIT_BREAKER,
            'message' => $message,
            'at' => now()->toIso8601String(),
            'signature' => $signature,
            'count' => $count,
        ];

        return $engine;
    }

    /**
     * @param  array<string, mixed>  $engine
     * @param  array<string, mixed>  $activeDispatch
     * @return array<string, mixed>
     */
    public static function stampWorkerLost(array $engine, array $activeDispatch, string $message): array
    {
        $runItemId = (int) ($activeDispatch['run_item_id'] ?? 0);
        $attempt = max(1, (int) ($activeDispatch['attempt'] ?? 1));
        $token = (string) ($activeDispatch['token'] ?? '');

        $engine[self::WORKER_LOST_KEY] = [
            'stopped' => true,
            'run_item_id' => $runItemId,
            'task_id' => (int) ($activeDispatch['task_id'] ?? 0),
            'attempt' => $attempt,
            'token' => $token,
            'at' => now()->toIso8601String(),
            'reason' => $message,
        ];
        $engine[self::SETTINGS_KEY] = [
            'reason' => self::REASON_WORKER_LOST,
            'message' => $message,
            'at' => now()->toIso8601String(),
            'run_item_id' => $runItemId,
            'attempt' => $attempt,
        ];
        $engine['finalized_at'] = now()->toIso8601String();
        $engine['final_status'] = 'failed_worker_lost';
        $engine['stop_reason'] = $message;

        return $engine;
    }

    /**
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    public static function clear(array $engine): array
    {
        unset(
            $engine[self::SETTINGS_KEY],
            $engine[self::WORKER_LOST_KEY],
            $engine['circuit_breaker'],
            $engine['finalized_at'],
            $engine['final_status'],
            $engine['stop_requested_at'],
            $engine['stop_requested_by'],
            $engine['stop_reason'],
            $engine['intentional_unvisited_pending'],
        );

        return ContentProjectBatchCircuitBreakerState::clearForResume($engine);
    }

    /**
     * Clear cooperative-stop / finalize stamps when reopening a run (stopping → running).
     *
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    public static function clearStopAndFinalMarkers(array $engine): array
    {
        unset(
            $engine['stop_requested_at'],
            $engine['stop_requested_by'],
            $engine['stop_reason'],
            $engine['finalized_at'],
            $engine['final_status'],
        );

        return $engine;
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    public static function userVisibleMessage(array $engine): ?string
    {
        $recoverable = is_array($engine[self::SETTINGS_KEY] ?? null)
            ? $engine[self::SETTINGS_KEY]
            : null;
        if (is_array($recoverable)) {
            $message = trim((string) ($recoverable['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        $breaker = is_array($engine['circuit_breaker'] ?? null) ? $engine['circuit_breaker'] : null;
        if ($breaker !== null) {
            $message = trim((string) ($breaker['reason'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        $lost = is_array($engine[self::WORKER_LOST_KEY] ?? null) ? $engine[self::WORKER_LOST_KEY] : null;
        if ($lost !== null) {
            $message = trim((string) ($lost['reason'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        $stop = trim((string) ($engine['stop_reason'] ?? ''));

        return $stop !== '' ? $stop : null;
    }

    /**
     * @param  int  $currentAttempt  Attempt that died (1-based). Message shows the next Resume attempt.
     */
    public static function workerLostItemMessage(int $taskId, int $currentAttempt, int $maxAttempts): string
    {
        $taskLabel = $taskId > 0 ? '#'.$taskId : 'đang chạy';
        $currentAttempt = max(1, $currentAttempt);
        $maxAttempts = max(1, $maxAttempts);
        $nextAttempt = $currentAttempt + 1;

        if ($nextAttempt > $maxAttempts) {
            return 'Worker bị mất trong khi đang chạy bài '.$taskLabel.'.'
                .' Đã hết attempt ('.$maxAttempts.'/'.$maxAttempts.') — không thể Resume thêm.';
        }

        return 'Worker bị mất trong khi đang chạy bài '.$taskLabel.'.'
            .' Có thể Resume để chạy lại attempt '.$nextAttempt.'/'.$maxAttempts.'.';
    }

    public static function isWorkerLostErrorCode(?string $errorCode): bool
    {
        $code = strtoupper(trim((string) $errorCode));

        return in_array($code, [
            self::ERROR_CODE_WORKER_LOST,
            self::ERROR_CODE_EXECUTION_INTERRUPTED,
        ], true);
    }
}
