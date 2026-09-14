<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Separates lazy-bulk membership rows from real execution attempts.
 *
 * SeoProjectRunItem.pending on a lazy_bulk run is bulk membership, not an
 * execution attempt — unless that item owns active_dispatch (JIT claim/queue).
 */
final class ContentProjectRunItemEvidenceIndex
{
    /**
     * @param  list<array<string, mixed>>  $itemsNewestFirst
     *         Each row: id, task_id, run_id, status, action, attempt, message,
     *         error_message, started_at*, finished_at*, lazy_bulk (bool)
     * @param  array<string, mixed>|null  $activeDispatch
     * @return array{
     *     latest_execution_by_task: array<int, array<string, mixed>>,
     *     current_membership_by_task: array<int, array<string, mixed>>,
     * }
     */
    public static function partition(
        array $itemsNewestFirst,
        ?array $activeDispatch,
        ?int $currentRunId,
    ): array {
        $latestExecutionByTask = [];
        $currentMembershipByTask = [];

        foreach ($itemsNewestFirst as $item) {
            if (! is_array($item)) {
                continue;
            }
            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $runId = (int) ($item['run_id'] ?? 0);
            $lazyBulk = (bool) ($item['lazy_bulk'] ?? false);

            if (
                $currentRunId !== null
                && $runId === $currentRunId
                && ! isset($currentMembershipByTask[$taskId])
            ) {
                $currentMembershipByTask[$taskId] = $item;
            }

            if (! self::countsAsLatestExecution($item, $lazyBulk, $activeDispatch)) {
                continue;
            }

            if (! isset($latestExecutionByTask[$taskId])) {
                $latestExecutionByTask[$taskId] = $item;
            }
        }

        return [
            'latest_execution_by_task' => $latestExecutionByTask,
            'current_membership_by_task' => $currentMembershipByTask,
        ];
    }

    /**
     * Unvisited lazy-bulk membership — not an execution attempt.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $activeDispatch
     */
    public static function isUnvisitedLazyBulkMembership(
        array $item,
        bool $lazyBulk,
        ?array $activeDispatch,
    ): bool {
        if (! $lazyBulk) {
            return false;
        }

        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if ($status !== 'pending') {
            return false;
        }

        if (self::hasExecutionTimestamps($item)) {
            return false;
        }

        if (self::matchesActiveDispatch($item, $activeDispatch)) {
            return false;
        }

        return true;
    }

    /**
     * Whether this row may be the task's latest REAL execution evidence.
     *
     * Excludes unvisited membership AND live JIT claim pending that has not
     * started yet (those belong only to current membership / runtime).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $activeDispatch
     */
    public static function countsAsLatestExecution(
        array $item,
        bool $lazyBulk,
        ?array $activeDispatch,
    ): bool {
        if (self::isUnvisitedLazyBulkMembership($item, $lazyBulk, $activeDispatch)) {
            return false;
        }

        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if ($status !== 'pending') {
            return true;
        }

        if (self::hasExecutionTimestamps($item)) {
            return true;
        }

        // Legacy non-lazy pending = prepareOperation queue attempt (real history).
        if (! $lazyBulk) {
            return true;
        }

        // Lazy pending + active_dispatch only = current claim, not historical yet.
        return false;
    }

    /**
     * Run-item fed to the runtime resolver for this task.
     *
     * Live current claim/queue uses membership; otherwise historical execution.
     * Unvisited membership alone must not become runtime evidence.
     *
     * @param  array<string, mixed>|null  $latestExecution
     * @param  array<string, mixed>|null  $currentMembership
     * @param  array<string, mixed>  $runtimeContext
     * @return array<string, mixed>|null
     */
    public static function runtimeRunItem(
        ?array $latestExecution,
        ?array $currentMembership,
        array $runtimeContext,
    ): ?array {
        $dispatch = is_array($runtimeContext['active_dispatch'] ?? null)
            ? $runtimeContext['active_dispatch']
            : null;

        if ($currentMembership !== null && self::isLiveRuntimeItem($currentMembership, $dispatch)) {
            return self::stripLazyBulkFlag($currentMembership);
        }

        return $latestExecution !== null ? self::stripLazyBulkFlag($latestExecution) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $activeDispatch
     */
    public static function isLiveRuntimeItem(array $item, ?array $activeDispatch): bool
    {
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if ($status === 'processing') {
            return true;
        }

        if (self::matchesActiveDispatch($item, $activeDispatch)) {
            return true;
        }

        // Claimed attempt that started (timestamps) even if dispatch was cleared mid-poll.
        if ($status === 'pending' && self::hasExecutionTimestamps($item)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $activeDispatch
     */
    public static function matchesActiveDispatch(array $item, ?array $activeDispatch): bool
    {
        if ($activeDispatch === null) {
            return false;
        }

        $itemId = (int) ($item['id'] ?? 0);
        $taskId = (int) ($item['task_id'] ?? 0);
        if ($itemId > 0 && (int) ($activeDispatch['run_item_id'] ?? 0) === $itemId) {
            return true;
        }

        return $taskId > 0 && (int) ($activeDispatch['task_id'] ?? 0) === $taskId;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function hasExecutionTimestamps(array $item): bool
    {
        foreach (['started_at', 'started_at_iso', 'finished_at', 'finished_at_iso'] as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function stripLazyBulkFlag(array $item): array
    {
        unset($item['lazy_bulk']);

        return $item;
    }
}
