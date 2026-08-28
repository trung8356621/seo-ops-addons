<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Deterministic fill: walk selected writers in given order, never exceed remaining capacity.
 */
final class ContentProjectWriterAllocator
{
    /**
     * @param  list<int>  $taskIds
     * @param  list<int>  $selectedUserIds  UI / selection order
     * @param  array<int, int>  $remainingByUser  user_id => remaining slots this month
     * @return array{
     *     allocations: list<array{user_id: int, item_count: int, task_ids: list<int>}>,
     *     unallocated_count: int,
     *     unallocated_task_ids: list<int>
     * }
     */
    public static function allocate(array $taskIds, array $selectedUserIds, array $remainingByUser): array
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

        $offset = 0;
        $total = count($taskIds);
        $allocations = [];

        foreach ($orderedUserIds as $userId) {
            if ($offset >= $total) {
                break;
            }

            $remaining = max(0, (int) ($remainingByUser[$userId] ?? 0));
            if ($remaining < 1) {
                continue;
            }

            $take = min($remaining, $total - $offset);
            if ($take < 1) {
                continue;
            }

            $chunk = array_slice($taskIds, $offset, $take);
            $allocations[] = [
                'user_id' => $userId,
                'item_count' => count($chunk),
                'task_ids' => $chunk,
            ];
            $offset += count($chunk);
        }

        $unallocated = array_slice($taskIds, $offset);

        return [
            'allocations' => $allocations,
            'unallocated_count' => count($unallocated),
            'unallocated_task_ids' => $unallocated,
        ];
    }
}
