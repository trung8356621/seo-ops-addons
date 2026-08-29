<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Fair distribution of Reviewed items across included writers, then
 * chunk each writer allocation into Execution Projects of max 30 items.
 */
final class ContentProjectWriterAllocator
{
    /**
     * Deterministic fair split: base = floor(n/users), first remainder users get +1.
     *
     * @param  list<int>  $taskIds
     * @param  list<int>  $selectedUserIds  Stable included order
     * @return array{
     *     allocations: list<array{
     *         user_id: int,
     *         item_count: int,
     *         task_ids: list<int>,
     *         project_chunks: list<list<int>>,
     *         project_count: int
     *     }>,
     *     unallocated_count: int,
     *     unallocated_task_ids: list<int>
     * }
     */
    public static function allocate(array $taskIds, array $selectedUserIds): array
    {
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

        if ($orderedUserIds === [] || $taskIds === []) {
            return [
                'allocations' => [],
                'unallocated_count' => count($taskIds),
                'unallocated_task_ids' => $taskIds,
            ];
        }

        $counts = self::fairCounts(count($taskIds), count($orderedUserIds));
        $offset = 0;
        $allocations = [];

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
            $projectChunks = self::chunkByMaxItems($chunk);

            $allocations[] = [
                'user_id' => $userId,
                'item_count' => count($chunk),
                'task_ids' => $chunk,
                'project_chunks' => $projectChunks,
                'project_count' => count($projectChunks),
            ];
        }

        return [
            'allocations' => $allocations,
            'unallocated_count' => 0,
            'unallocated_task_ids' => [],
        ];
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
