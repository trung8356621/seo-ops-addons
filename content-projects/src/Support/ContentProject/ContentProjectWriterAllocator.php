<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Fair distribution of Reviewed items across included writers, then
 * chunk each writer allocation into Execution Projects of max 30 items.
 *
 * When remaining capacities are provided, never assigns beyond each writer's
 * free monthly slots (capacity-aware water-filling / round-robin).
 */
final class ContentProjectWriterAllocator
{
    /**
     * Deterministic fair split with optional per-writer remaining capacity.
     *
     * @param  list<int>  $taskIds
     * @param  list<int>  $selectedUserIds  Stable included order
     * @param  array<int, int>|null  $remainingByUserId  max(0, capacity - used); null = unlimited (legacy fair)
     * @return array{
     *     allocations: list<array{
     *         user_id: int,
     *         item_count: int,
     *         task_ids: list<int>,
     *         project_chunks: list<list<int>>,
     *         project_count: int
     *     }>,
     *     unallocated_count: int,
     *     unallocated_task_ids: list<int>,
     *     requested_items: int,
     *     assigned_items: int
     * }
     */
    public static function allocate(
        array $taskIds,
        array $selectedUserIds,
        ?array $remainingByUserId = null,
    ): array {
        $taskIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        ));

        $seen = [];
        $orderedUserIds = [];
        foreach ($selectedUserIds as $rawId) {
            $userId = (int) $rawId;
            if ($userId <= 0 || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            $orderedUserIds[] = $userId;
        }

        $requested = count($taskIds);

        if ($orderedUserIds === [] || $taskIds === []) {
            return [
                'allocations' => [],
                'unallocated_count' => $requested,
                'unallocated_task_ids' => $taskIds,
                'requested_items' => $requested,
                'assigned_items' => 0,
            ];
        }

        $counts = $remainingByUserId === null
            ? self::fairCounts($requested, count($orderedUserIds))
            : self::capacityAwareFairCounts($requested, $orderedUserIds, $remainingByUserId);

        $offset = 0;
        $allocations = [];
        $assignedTotal = 0;

        foreach ($orderedUserIds as $index => $userId) {
            $take = (int) ($counts[$index] ?? 0);
            if ($take < 1) {
                $allocations[] = [
                    'user_id' => $userId,
                    'item_count' => 0,
                    'task_ids' => [],
                    'project_chunks' => [],
                    'project_count' => 0,
                ];

                continue;
            }

            $chunk = array_slice($taskIds, $offset, $take);
            $offset += count($chunk);
            $assignedTotal += count($chunk);
            $projectChunks = self::chunkByMaxItems($chunk);

            $allocations[] = [
                'user_id' => $userId,
                'item_count' => count($chunk),
                'task_ids' => $chunk,
                'project_chunks' => $projectChunks,
                'project_count' => count($projectChunks),
            ];
        }

        $unallocated = array_slice($taskIds, $offset);

        return [
            'allocations' => $allocations,
            'unallocated_count' => count($unallocated),
            'unallocated_task_ids' => array_values($unallocated),
            'requested_items' => $requested,
            'assigned_items' => $assignedTotal,
        ];
    }

    /**
     * Round-robin among writers that still have free remaining capacity.
     * Deterministic: stable writer order, one item per pass.
     *
     * @param  list<int>  $orderedUserIds
     * @param  array<int, int>  $remainingByUserId
     * @return list<int>
     */
    public static function capacityAwareFairCounts(
        int $totalItems,
        array $orderedUserIds,
        array $remainingByUserId,
    ): array {
        $userCount = count($orderedUserIds);
        if ($userCount < 1 || $totalItems < 1) {
            return [];
        }

        $caps = [];
        foreach ($orderedUserIds as $userId) {
            $caps[] = max(0, (int) ($remainingByUserId[$userId] ?? 0));
        }

        $counts = array_fill(0, $userCount, 0);
        $left = $totalItems;

        while ($left > 0) {
            $progress = false;
            for ($i = 0; $i < $userCount; $i++) {
                if ($left < 1) {
                    break;
                }
                if ($counts[$i] >= $caps[$i]) {
                    continue;
                }
                $counts[$i]++;
                $left--;
                $progress = true;
            }
            if (! $progress) {
                break;
            }
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    public static function fairCounts(int $totalItems, int $userCount): array
    {
        if ($userCount < 1 || $totalItems < 1) {
            return [];
        }

        $base = intdiv($totalItems, $userCount);
        $remainder = $totalItems % $userCount;
        $counts = [];

        for ($i = 0; $i < $userCount; $i++) {
            $counts[] = $base + ($i < $remainder ? 1 : 0);
        }

        return $counts;
    }

    /**
     * Sequential chunks of at most MAX_EXECUTION_PROJECT_ITEMS (no empty chunks).
     *
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    public static function chunkByMaxItems(array $ids, ?int $maxItems = null): array
    {
        $ids = array_values($ids);
        $n = count($ids);
        if ($n === 0) {
            return [];
        }

        $max = $maxItems ?? ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
        if ($max < 1) {
            throw new \InvalidArgumentException('max items per project must be at least 1.');
        }

        $chunks = [];
        for ($offset = 0; $offset < $n; $offset += $max) {
            $chunk = array_slice($ids, $offset, $max);
            if ($chunk !== []) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }
}
