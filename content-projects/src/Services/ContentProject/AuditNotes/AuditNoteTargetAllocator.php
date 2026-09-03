<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

/**
 * Deterministic MCP-normalized target_dna_count allocation across selected Topics.
 * System planning logic — not Prompt Management content.
 *
 * Manual target_mode topics keep their target; AUTO topics share the remaining quantity
 * by relative MCP share (largest-remainder). Specified DNA slots floor every target.
 */
final class AuditNoteTargetAllocator
{
    public const TARGET_MODE_AUTO = 'auto';

    public const TARGET_MODE_MANUAL = 'manual';

    public const CODE_OK = 'ok';

    public const CODE_TOO_MANY_TOPICS = 'too_many_topics';

    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return array{
     *   code: string,
     *   items: list<array<string, mixed>>,
     *   requested_quantity: int,
     *   total_target: int,
     *   topic_count: int,
     *   message: string|null
     * }
     */
    public static function apply(array $noteItems, int $requestedQuantity): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $quantity = max(1, min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $requestedQuantity));
        $topicCount = count($items);

        if ($topicCount === 0) {
            return [
                'code' => self::CODE_OK,
                'items' => [],
                'requested_quantity' => $quantity,
                'total_target' => 0,
                'topic_count' => 0,
                'message' => null,
            ];
        }

        if ($topicCount > $quantity) {
            return [
                'code' => self::CODE_TOO_MANY_TOPICS,
                'items' => $items,
                'requested_quantity' => $quantity,
                'total_target' => AuditNoteDnaNormalizer::totalTargetDnaCount($items),
                'topic_count' => $topicCount,
                'message' => 'selected_topics_exceed_quantity',
            ];
        }

        $manualReserved = 0;
        $autoIndexes = [];
        foreach ($items as $index => $item) {
            // Planning Seeds never use MCP auto allocation.
            if (AuditNoteDnaNormalizer::isManualSeed($item)) {
                $items[$index]['source_type'] = AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED;
                $items[$index]['target_mode'] = self::TARGET_MODE_MANUAL;
                $floor = AuditNoteDnaNormalizer::specifiedSlotCount($item['dna']);
                $target = max((int) $item['target_dna_count'], $floor, 1);
                $target = min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $target);
                $items[$index]['target_dna_count'] = $target;
                $manualReserved += $target;

                continue;
            }

            $mode = self::normalizeTargetMode($item['target_mode'] ?? null);
            $items[$index]['target_mode'] = $mode;
            if ($mode === self::TARGET_MODE_MANUAL) {
                $floor = AuditNoteDnaNormalizer::specifiedSlotCount($item['dna']);
                $target = max((int) $item['target_dna_count'], $floor, 1);
                $target = min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $target);
                $items[$index]['target_dna_count'] = $target;
                $manualReserved += $target;
            } else {
                $autoIndexes[] = $index;
            }
        }

        $available = $quantity - $manualReserved;
        if ($autoIndexes === []) {
            return self::finalize($items, $quantity);
        }

        if ($available < count($autoIndexes)) {
            // Not enough remaining slots for every AUTO topic to get ≥1 after manuals.
            // Still assign floors from specified DNA, then best-effort min-1 within available.
            $allocated = self::allocateAmongAuto($items, $autoIndexes, max(0, $available));
            foreach ($autoIndexes as $i => $index) {
                $items[$index]['target_dna_count'] = $allocated[$i];
                $items[$index]['target_mode'] = self::TARGET_MODE_AUTO;
            }

            return self::finalize($items, $quantity);
        }

        $allocated = self::allocateAmongAuto($items, $autoIndexes, $available);
        foreach ($autoIndexes as $i => $index) {
            $items[$index]['target_dna_count'] = $allocated[$i];
            $items[$index]['target_mode'] = self::TARGET_MODE_AUTO;
        }

        return self::finalize($items, $quantity);
    }

    public static function normalizeTargetMode(mixed $raw): string
    {
        $mode = strtolower(trim((string) ($raw ?? '')));
        if ($mode === self::TARGET_MODE_MANUAL) {
            return self::TARGET_MODE_MANUAL;
        }

        return self::TARGET_MODE_AUTO;
    }

    /**
     * Largest-remainder MCP allocation for AUTO indexes. Guarantees ≥1 when available ≥ count.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $autoIndexes
     * @return list<int> targets aligned with $autoIndexes
     */
    public static function allocateAmongAuto(array $items, array $autoIndexes, int $available): array
    {
        $n = count($autoIndexes);
        if ($n === 0) {
            return [];
        }
        if ($available <= 0) {
            return array_fill(0, $n, 0);
        }

        $weights = [];
        foreach ($autoIndexes as $index) {
            $share = (float) ($items[$index]['mcp_share_snapshot'] ?? 0);
            $weights[] = $share > 0 ? $share : 0.0;
        }
        $weightSum = array_sum($weights);

        if ($weightSum <= 0.0) {
            $assigned = array_fill(0, $n, 0);
            for ($k = 0; $k < $available; $k++) {
                $assigned[$k % $n]++;
            }

            return self::applySpecifiedFloors($items, $autoIndexes, $assigned);
        }

        /** @var list<array{index: int, floor: int, frac: float, ref: string}> $rows */
        $rows = [];
        $floorSum = 0;
        for ($i = 0; $i < $n; $i++) {
            $ideal = $available * ($weights[$i] / $weightSum);
            $floor = (int) floor($ideal);
            $floorSum += $floor;
            $rows[] = [
                'index' => $i,
                'floor' => $floor,
                'frac' => $ideal - $floor,
                'ref' => (string) ($items[$autoIndexes[$i]]['cluster_ref'] ?? ''),
            ];
        }
        $remainder = $available - $floorSum;
        usort(
            $rows,
            static function (array $a, array $b): int {
                $byFrac = $b['frac'] <=> $a['frac'];
                if ($byFrac !== 0) {
                    return $byFrac;
                }
                $byRef = strcmp($a['ref'], $b['ref']);
                if ($byRef !== 0) {
                    return $byRef;
                }

                return $a['index'] <=> $b['index'];
            },
        );
        $assigned = array_fill(0, $n, 0);
        foreach ($rows as $rank => $row) {
            $extra = $rank < $remainder ? 1 : 0;
            $assigned[$row['index']] = $row['floor'] + $extra;
        }

        if ($available >= $n) {
            $assigned = self::enforceMinOne($assigned, $available);
        }

        return self::applySpecifiedFloors($items, $autoIndexes, $assigned);
    }

    /**
     * @param  list<int>  $assigned
     * @return list<int>
     */
    private static function enforceMinOne(array $assigned, int $available): array
    {
        $n = count($assigned);
        $zeros = [];
        for ($i = 0; $i < $n; $i++) {
            if ($assigned[$i] < 1) {
                $zeros[] = $i;
            }
        }
        foreach ($zeros as $zeroIndex) {
            $donor = null;
            $donorValue = -1;
            for ($i = 0; $i < $n; $i++) {
                if ($assigned[$i] > 1 && $assigned[$i] > $donorValue) {
                    $donor = $i;
                    $donorValue = $assigned[$i];
                }
            }
            if ($donor === null) {
                break;
            }
            $assigned[$donor]--;
            $assigned[$zeroIndex] = 1;
        }
        unset($available);

        return $assigned;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $autoIndexes
     * @param  list<int>  $assigned
     * @return list<int>
     */
    private static function applySpecifiedFloors(array $items, array $autoIndexes, array $assigned): array
    {
        foreach ($autoIndexes as $i => $index) {
            $floor = AuditNoteDnaNormalizer::specifiedSlotCount(
                is_array($items[$index]['dna'] ?? null) ? $items[$index]['dna'] : [],
            );
            $assigned[$i] = max($assigned[$i], $floor);
            if ($assigned[$i] < 1 && $floor < 1) {
                // leave as-is (may be 0 when available exhausted)
            } elseif ($assigned[$i] < 1) {
                $assigned[$i] = max(1, $floor);
            }
            $assigned[$i] = min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $assigned[$i]);
        }

        return $assigned;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   code: string,
     *   items: list<array<string, mixed>>,
     *   requested_quantity: int,
     *   total_target: int,
     *   topic_count: int,
     *   message: string|null
     * }
     */
    private static function finalize(array $items, int $quantity): array
    {
        foreach ($items as $index => $item) {
            $floor = AuditNoteDnaNormalizer::specifiedSlotCount($item['dna']);
            $target = max((int) $item['target_dna_count'], $floor);
            if ($target < 1) {
                $target = 1;
            }
            $items[$index]['target_dna_count'] = min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $target);
            $items[$index]['target_mode'] = self::normalizeTargetMode($item['target_mode'] ?? null);
        }

        return [
            'code' => self::CODE_OK,
            'items' => array_values($items),
            'requested_quantity' => $quantity,
            'total_target' => AuditNoteDnaNormalizer::totalTargetDnaCount($items),
            'topic_count' => count($items),
            'message' => null,
        ];
    }
}
