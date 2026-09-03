<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Bounded automatic recovery for one-click Keyword Discovery.
 * consecutive_no_progress is a recovery signal, not a terminal stop.
 */
final class NewContentAutoContinuationPolicy
{
    public const PHASE_RUNNING = 'running';

    public const PHASE_RECOVERING = 'recovering';

    public const PHASE_WAITING_RETRY = 'waiting_retry';

    public const LEVEL_REFRESH_CONTINUATION = 1;

    public const LEVEL_COVERAGE_SLICE = 2;

    public const LEVEL_OVERSAMPLE = 3;

    public const LEVEL_REDUCE_BATCH = 4;

    public const LEVEL_FRESH_BATCH = 5;

    public const MAX_RECOVERY_LEVEL = 5;

    /** Max automatic continuation job slices for one logical run. */
    public const MAX_CONTINUATION_SLICES = 12;

    /** Max no-progress escalations within a single job slice before yielding to next slice. */
    public const MAX_NO_PROGRESS_ESCALATIONS_PER_SLICE = 5;

    /** Soft wall-clock budget (seconds) before yielding a continuation job. */
    public const JOB_TIME_BUDGET_SECONDS = 700;

    /** Max automatic provider-transient recovery schedules. */
    public const MAX_PROVIDER_RETRY_CYCLES = 4;

    public const PROVIDER_BACKOFF_SECONDS = [15, 45, 120, 300];

    public const RECOVERY_REASON_NO_PROGRESS = 'consecutive_no_progress';

    public const RECOVERY_REASON_TRUNCATED = 'truncated_output';

    public const RECOVERY_REASON_PROVIDER_TRANSIENT = 'provider_transient';

    public const RECOVERY_REASON_TIME_SLICE = 'job_time_budget';

    public const ACTION_CONTINUE = 'continue';

    public const ACTION_YIELD_SLICE = 'yield_slice';

    public const ACTION_WAIT_RETRY = 'wait_retry';

    public const ACTION_TERMINAL = 'terminal';

    /**
     * @return array{
     *   action: string,
     *   recovery_level: int,
     *   recovery_reason: string,
     *   forced_batch_cap: int|null,
     *   oversample_factor: float,
     *   rotate_coverage: bool,
     *   delay_seconds: int
     * }
     */
    public static function afterNoProgress(int $currentLevel, int $escalationsInSlice, int $continuationSlicesUsed): array
    {
        if ($continuationSlicesUsed >= self::MAX_CONTINUATION_SLICES
            && $currentLevel >= self::MAX_RECOVERY_LEVEL
        ) {
            return self::terminal(self::RECOVERY_REASON_NO_PROGRESS, $currentLevel);
        }

        if ($escalationsInSlice >= self::MAX_NO_PROGRESS_ESCALATIONS_PER_SLICE) {
            return [
                'action' => self::ACTION_YIELD_SLICE,
                'recovery_level' => max(1, $currentLevel),
                'recovery_reason' => self::RECOVERY_REASON_NO_PROGRESS,
                'forced_batch_cap' => null,
                'oversample_factor' => 1.0,
                'rotate_coverage' => false,
                'delay_seconds' => 2,
            ];
        }

        $next = min(self::MAX_RECOVERY_LEVEL, max(1, $currentLevel) + 1);

        return [
            'action' => self::ACTION_CONTINUE,
            'recovery_level' => $next,
            'recovery_reason' => self::RECOVERY_REASON_NO_PROGRESS,
            'forced_batch_cap' => $next >= self::LEVEL_REDUCE_BATCH ? 5 : null,
            'oversample_factor' => $next >= self::LEVEL_OVERSAMPLE ? 1.4 : 1.0,
            'rotate_coverage' => $next >= self::LEVEL_COVERAGE_SLICE,
            'delay_seconds' => 0,
        ];
    }

    /**
     * @return array{
     *   action: string,
     *   recovery_level: int,
     *   recovery_reason: string,
     *   forced_batch_cap: int|null,
     *   oversample_factor: float,
     *   rotate_coverage: bool,
     *   delay_seconds: int
     * }
     */
    public static function afterTruncatedRepairFailed(int $currentLevel): array
    {
        $next = max($currentLevel, self::LEVEL_REDUCE_BATCH);

        return [
            'action' => self::ACTION_CONTINUE,
            'recovery_level' => $next,
            'recovery_reason' => self::RECOVERY_REASON_TRUNCATED,
            'forced_batch_cap' => 5,
            'oversample_factor' => 1.0,
            'rotate_coverage' => true,
            'delay_seconds' => 0,
        ];
    }

    /**
     * @return array{
     *   action: string,
     *   recovery_level: int,
     *   recovery_reason: string,
     *   forced_batch_cap: int|null,
     *   oversample_factor: float,
     *   rotate_coverage: bool,
     *   delay_seconds: int
     * }
     */
    public static function afterProviderTransient(int $providerRetryCycle): array
    {
        if ($providerRetryCycle >= self::MAX_PROVIDER_RETRY_CYCLES) {
            return self::terminal(self::RECOVERY_REASON_PROVIDER_TRANSIENT, self::MAX_RECOVERY_LEVEL);
        }

        $delay = self::PROVIDER_BACKOFF_SECONDS[$providerRetryCycle]
            ?? self::PROVIDER_BACKOFF_SECONDS[array_key_last(self::PROVIDER_BACKOFF_SECONDS)];

        return [
            'action' => self::ACTION_WAIT_RETRY,
            'recovery_level' => self::LEVEL_REFRESH_CONTINUATION,
            'recovery_reason' => self::RECOVERY_REASON_PROVIDER_TRANSIENT,
            'forced_batch_cap' => null,
            'oversample_factor' => 1.0,
            'rotate_coverage' => false,
            'delay_seconds' => $delay,
        ];
    }

    public static function shouldYieldForTimeBudget(float $elapsedSeconds, int $remaining): bool
    {
        return $remaining > 0 && $elapsedSeconds >= self::JOB_TIME_BUDGET_SECONDS;
    }

    public static function oversampleRawTarget(int $remainingUnique, float $factor, int $hardCap): int
    {
        $remainingUnique = max(1, $remainingUnique);
        $raw = (int) ceil($remainingUnique * max(1.0, $factor));

        return max($remainingUnique, min($hardCap, $raw));
    }

    /**
     * Rotate under-covered topics to the front (exact coverage slice change).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function rotateCoverageSlice(array $items): array
    {
        if (count($items) <= 1) {
            return array_values($items);
        }

        $head = array_shift($items);
        $items[] = $head;

        return array_values($items);
    }

    public static function isActivePhase(string $status): bool
    {
        return in_array($status, [
            'queued',
            'running',
            'recovering',
            'waiting_retry',
        ], true);
    }

    /**
     * @return array{
     *   action: string,
     *   recovery_level: int,
     *   recovery_reason: string,
     *   forced_batch_cap: int|null,
     *   oversample_factor: float,
     *   rotate_coverage: bool,
     *   delay_seconds: int
     * }
     */
    private static function terminal(string $reason, int $level): array
    {
        return [
            'action' => self::ACTION_TERMINAL,
            'recovery_level' => $level,
            'recovery_reason' => $reason,
            'forced_batch_cap' => null,
            'oversample_factor' => 1.0,
            'rotate_coverage' => false,
            'delay_seconds' => 0,
        ];
    }
}
