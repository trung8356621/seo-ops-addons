<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

/**
 * Backend-enforced execution budget for Global Monthly batches.
 * Smoke/test batches MUST set smoke_test + max_items ≤ 3 — enforced before claim/dispatch.
 *
 * @phpstan-type BudgetArray array{
 *   enabled?: bool,
 *   smoke_test?: bool,
 *   max_items?: int,
 *   max_attempts?: int,
 *   attempts_started?: int,
 *   items_started?: int,
 *   started_batch_item_ids?: list<int>,
 *   allowed_task_ids?: list<int>,
 *   disable_paid_fallback?: bool,
 *   auto_rerun_disabled?: bool,
 * }
 */
final class GlobalBatchExecutionBudget
{
    /** Absolute ceiling for any explicitly capped / smoke batch. */
    public const ABSOLUTE_MAX_ITEMS = 3;

    public const SETTINGS_KEY = 'execution_budget';

    /**
     * @param  array<string, mixed>  $options
     * @return BudgetArray
     */
    public static function fromStartOptions(array $options): array
    {
        $smoke = ! empty($options['smoke_test']);
        $maxItems = isset($options['max_items']) ? (int) $options['max_items'] : ($smoke ? self::ABSOLUTE_MAX_ITEMS : 0);
        $enabled = $smoke || $maxItems > 0 || ! empty($options['allowed_task_ids']) || ! empty($options['max_attempts']);

        if (! $enabled) {
            return ['enabled' => false];
        }

        if ($smoke || $maxItems > 0) {
            if ($maxItems <= 0) {
                $maxItems = self::ABSOLUTE_MAX_ITEMS;
            }
            $maxItems = min($maxItems, self::ABSOLUTE_MAX_ITEMS);
        }

        $allowed = [];
        if (isset($options['allowed_task_ids']) && is_array($options['allowed_task_ids'])) {
            $allowed = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $options['allowed_task_ids']),
                static fn (int $id): bool => $id > 0,
            )));
        }
        if ($allowed !== [] && $maxItems > 0) {
            $allowed = array_slice($allowed, 0, $maxItems);
            $maxItems = min($maxItems, count($allowed));
        } elseif ($allowed !== [] && $maxItems <= 0) {
            $maxItems = min(count($allowed), self::ABSOLUTE_MAX_ITEMS);
            $allowed = array_slice($allowed, 0, $maxItems);
        }

        $maxAttempts = isset($options['max_attempts']) ? (int) $options['max_attempts'] : 0;
        if ($maxAttempts <= 0) {
            // Default: one attempt per capped item (no Free→Paid expansion).
            $maxAttempts = max(1, $maxItems);
        }
        // Hard ceiling: never more than 2 attempts/item even if misconfigured (Free+Paid),
        // and never more than ABSOLUTE_MAX_ITEMS * 2 for smoke.
        $maxAttempts = min($maxAttempts, max(1, $maxItems) * ($smoke || ! empty($options['disable_paid_fallback']) ? 1 : 2));

        return [
            'enabled' => true,
            'smoke_test' => $smoke,
            'max_items' => max(1, $maxItems),
            'max_attempts' => max(1, $maxAttempts),
            'attempts_started' => 0,
            'items_started' => 0,
            // Durable set — survives worker restart / wake / reconciler; never cleared mid-batch.
            'started_batch_item_ids' => [],
            'allowed_task_ids' => $allowed,
            'disable_paid_fallback' => $smoke || ! empty($options['disable_paid_fallback']),
            'auto_rerun_disabled' => $smoke || ! empty($options['auto_rerun_disabled']) || ! empty($options['disable_auto_rerun']),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return BudgetArray
     */
    public static function read(array $settings): array
    {
        $budget = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];
        if ($budget === [] || empty($budget['enabled'])) {
            return ['enabled' => false];
        }

        return $budget;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  BudgetArray  $budget
     * @return list<array<string, mixed>>
     */
    public static function filterSnapshotItems(array $items, array $budget): array
    {
        if (empty($budget['enabled'])) {
            return $items;
        }

        $allowed = is_array($budget['allowed_task_ids'] ?? null) ? $budget['allowed_task_ids'] : [];
        if ($allowed !== []) {
            $allowSet = array_fill_keys($allowed, true);
            $items = array_values(array_filter(
                $items,
                static fn (array $row): bool => isset($allowSet[(int) ($row['task_id'] ?? 0)]),
            ));
        }

        $max = max(1, min(self::ABSOLUTE_MAX_ITEMS, (int) ($budget['max_items'] ?? self::ABSOLUTE_MAX_ITEMS)));

        return array_slice(array_values($items), 0, $max);
    }

    /**
     * @param  BudgetArray  $budget
     * @return array{ok: bool, reason?: string, budget?: BudgetArray}
     */
    public static function assertCanDispatch(array $budget, ?int $taskId = null): array
    {
        if (empty($budget['enabled'])) {
            return ['ok' => true, 'budget' => $budget];
        }

        $maxItems = max(1, (int) ($budget['max_items'] ?? self::ABSOLUTE_MAX_ITEMS));
        $maxAttempts = max(1, (int) ($budget['max_attempts'] ?? $maxItems));
        $itemsStarted = max(0, (int) ($budget['items_started'] ?? 0));
        $attemptsStarted = max(0, (int) ($budget['attempts_started'] ?? 0));

        if ($attemptsStarted >= $maxAttempts) {
            return ['ok' => false, 'reason' => 'max_attempts_exhausted', 'budget' => $budget];
        }
        if ($itemsStarted >= $maxItems) {
            return ['ok' => false, 'reason' => 'max_items_exhausted', 'budget' => $budget];
        }

        $allowed = is_array($budget['allowed_task_ids'] ?? null) ? $budget['allowed_task_ids'] : [];
        if ($taskId !== null && $taskId > 0 && $allowed !== [] && ! in_array($taskId, $allowed, true)) {
            return ['ok' => false, 'reason' => 'task_not_in_allowed_list', 'budget' => $budget];
        }

        return ['ok' => true, 'budget' => $budget];
    }

    /**
     * Non-atomic helper for unit tests / read-model projection.
     * Production dispatch MUST use {@see claimDispatchSlot()}.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function recordDispatchStarted(array $settings, bool $newItem, ?int $batchItemId = null): array
    {
        $budget = self::read($settings);
        if (empty($budget['enabled'])) {
            return $settings;
        }

        $budget['attempts_started'] = max(0, (int) ($budget['attempts_started'] ?? 0)) + 1;
        $startedIds = is_array($budget['started_batch_item_ids'] ?? null)
            ? array_map('intval', $budget['started_batch_item_ids'])
            : [];
        if ($batchItemId !== null && $batchItemId > 0 && ! in_array($batchItemId, $startedIds, true)) {
            $startedIds[] = $batchItemId;
            $newItem = true;
        }
        if ($newItem) {
            $budget['items_started'] = max(0, (int) ($budget['items_started'] ?? 0)) + 1;
        }
        $budget['started_batch_item_ids'] = array_values(array_unique($startedIds));
        // Keep counters coherent with durable id set after restarts.
        $budget['items_started'] = max((int) $budget['items_started'], count($budget['started_batch_item_ids']));
        $settings[self::SETTINGS_KEY] = $budget;

        return $settings;
    }

    /**
     * Atomic claim under row lock. Persists into batch.settings.execution_budget.
     * Does not reset counters on wake/reconcile — only this method mutates counters.
     *
     * @return array{ok: bool, reason?: string, budget?: array<string, mixed>}
     */
    public static function claimDispatchSlot(int $batchId, int $batchItemId, int $taskId): array
    {
        return \Illuminate\Support\Facades\DB::connection('omi_seo_ai')->transaction(function () use ($batchId, $batchItemId, $taskId): array {
            /** @var \Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch|null $locked */
            $locked = \Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch::query()
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof \Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch) {
                return ['ok' => false, 'reason' => 'batch_missing'];
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $budget = self::read($settings);
            if (empty($budget['enabled'])) {
                return ['ok' => true, 'budget' => $budget];
            }

            // Rehydrate counters from durable id list (worker restart safe).
            $startedIds = is_array($budget['started_batch_item_ids'] ?? null)
                ? array_values(array_unique(array_map('intval', $budget['started_batch_item_ids'])))
                : [];
            $budget['started_batch_item_ids'] = $startedIds;
            $budget['items_started'] = max((int) ($budget['items_started'] ?? 0), count($startedIds));

            $gate = self::assertCanDispatch($budget, $taskId);
            if (! ($gate['ok'] ?? false)) {
                return ['ok' => false, 'reason' => $gate['reason'] ?? 'budget_blocked', 'budget' => $budget];
            }

            $isNewItem = $batchItemId > 0 && ! in_array($batchItemId, $startedIds, true);
            // Already counted this item AND attempt budget full for another concurrent claim?
            // Always consume one attempt slot per claim (duplicate queued jobs count).
            $budget['attempts_started'] = max(0, (int) ($budget['attempts_started'] ?? 0)) + 1;
            if ($isNewItem) {
                $startedIds[] = $batchItemId;
                $budget['started_batch_item_ids'] = array_values(array_unique($startedIds));
                $budget['items_started'] = count($budget['started_batch_item_ids']);
            }

            // Re-check after increment (duplicate jobs / concurrent workers).
            $maxAttempts = max(1, (int) ($budget['max_attempts'] ?? 1));
            $maxItems = max(1, (int) ($budget['max_items'] ?? self::ABSOLUTE_MAX_ITEMS));
            if ((int) $budget['attempts_started'] > $maxAttempts) {
                return ['ok' => false, 'reason' => 'max_attempts_exhausted', 'budget' => $budget];
            }
            if ((int) $budget['items_started'] > $maxItems) {
                return ['ok' => false, 'reason' => 'max_items_exhausted', 'budget' => $budget];
            }

            $settings[self::SETTINGS_KEY] = $budget;
            $locked->update(['settings' => $settings]);

            return ['ok' => true, 'budget' => $budget];
        });
    }

    /**
     * @param  BudgetArray  $budget
     */
    public static function paidFallbackDisabled(array $budget): bool
    {
        return ! empty($budget['enabled']) && ! empty($budget['disable_paid_fallback']);
    }

    /**
     * @param  BudgetArray  $budget
     */
    public static function autoRerunDisabled(array $budget): bool
    {
        return ! empty($budget['enabled']) && ! empty($budget['auto_rerun_disabled']);
    }
}
