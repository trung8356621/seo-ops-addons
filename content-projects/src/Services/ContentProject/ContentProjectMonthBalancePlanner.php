<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Pure planner for horizontal “Balance months”.
 *
 * Priority:
 * 1) minimize final-load imbalance (max − min)
 * 2) minimize number of moved tasks
 * 3) deterministic ties (higher current mass, then earlier selected month; task id ASC)
 */
final class ContentProjectMonthBalancePlanner
{
    /**
     * @param  list<string>  $months  YYYY-MM
     * @param  array<string, int>  $fixedByMonth
     * @param  list<array{id: int, month: string}>  $movable
     * @return array{
     *     months: list<string>,
     *     fixed_by_month: array<string, int>,
     *     movable_by_month: array<string, int>,
     *     current_by_month: array<string, int>,
     *     target_by_month: array<string, int>,
     *     target_movable_by_month: array<string, int>,
     *     allocation: array<int, string>,
     *     moves: list<array{task_id: int, from: string, to: string}>,
     *     move_count: int,
     *     imbalance_before: int,
     *     imbalance_after: int,
     *     fingerprint: string
     * }
     */
    public function plan(array $months, array $fixedByMonth, array $movable): array
    {
        $months = $this->normalizeMonths($months);
        if ($months === []) {
            return $this->emptyResult();
        }

        $fixed = [];
        $currentMovable = [];
        foreach ($months as $month) {
            $fixed[$month] = max(0, (int) ($fixedByMonth[$month] ?? 0));
            $currentMovable[$month] = 0;
        }

        $movableNormalized = [];
        foreach ($movable as $row) {
            $id = (int) ($row['id'] ?? 0);
            $month = $this->normalizeMonth((string) ($row['month'] ?? ''));
            if ($id <= 0 || $month === null || ! isset($fixed[$month])) {
                continue;
            }
            $movableNormalized[] = ['id' => $id, 'month' => $month];
            $currentMovable[$month]++;
        }

        usort(
            $movableNormalized,
            static fn (array $a, array $b): int => $a['id'] <=> $b['id'],
        );

        $current = [];
        foreach ($months as $month) {
            $current[$month] = $fixed[$month] + $currentMovable[$month];
        }

        $target = $this->resolveTargetLoads($months, $fixed, $currentMovable, count($movableNormalized));
        $targetMovable = [];
        foreach ($months as $month) {
            $targetMovable[$month] = max(0, $target[$month] - $fixed[$month]);
        }

        $allocation = $this->assignTasks($months, $movableNormalized, $targetMovable);
        $moves = [];
        foreach ($movableNormalized as $row) {
            $to = $allocation[$row['id']] ?? $row['month'];
            if ($to !== $row['month']) {
                $moves[] = [
                    'task_id' => $row['id'],
                    'from' => $row['month'],
                    'to' => $to,
                ];
            }
        }

        $fingerprintPayload = [
            'months' => $months,
            'fixed' => $fixed,
            'movable' => array_map(
                static fn (array $row): array => ['id' => $row['id'], 'month' => $row['month']],
                $movableNormalized,
            ),
            'allocation' => $allocation,
        ];

        return [
            'months' => $months,
            'fixed_by_month' => $fixed,
            'movable_by_month' => $currentMovable,
            'current_by_month' => $current,
            'target_by_month' => $target,
            'target_movable_by_month' => $targetMovable,
            'allocation' => $allocation,
            'moves' => $moves,
            'move_count' => count($moves),
            'imbalance_before' => $this->imbalance($current),
            'imbalance_after' => $this->imbalance($target),
            'fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Water-fill from fixed floors (mass-preferring ties), then locally improve
     * for minimal movement while keeping optimal imbalance.
     *
     * @param  list<string>  $months
     * @param  array<string, int>  $fixed
     * @param  array<string, int>  $currentMovable
     * @return array<string, int>
     */
    private function resolveTargetLoads(array $months, array $fixed, array $currentMovable, int $movableCount): array
    {
        $loads = $this->waterFillWithMass($months, $fixed, $movableCount, $currentMovable);
        $bestImbalance = $this->imbalance($loads);
        $bestMovement = $this->movableMovement($months, $fixed, $currentMovable, $loads);

        $improved = true;
        while ($improved) {
            $improved = false;
            foreach ($months as $from) {
                if ($loads[$from] <= $fixed[$from]) {
                    continue;
                }
                foreach ($months as $to) {
                    if ($from === $to) {
                        continue;
                    }
                    $candidate = $loads;
                    $candidate[$from]--;
                    $candidate[$to]++;
                    if ($this->imbalance($candidate) > $bestImbalance) {
                        continue;
                    }
                    $movement = $this->movableMovement($months, $fixed, $currentMovable, $candidate);
                    if ($movement < $bestMovement) {
                        $loads = $candidate;
                        $bestMovement = $movement;
                        $bestImbalance = $this->imbalance($candidate);
                        $improved = true;
                    }
                }
            }
        }

        return $loads;
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, int>  $fixed
     * @param  array<string, int>  $massPreference
     * @return array<string, int>
     */
    private function waterFillWithMass(array $months, array $fixed, int $movableCount, array $massPreference): array
    {
        $loads = [];
        foreach ($months as $month) {
            $loads[$month] = $fixed[$month];
        }

        $mass = $massPreference;
        for ($i = 0; $i < $movableCount; $i++) {
            $bestMonth = $months[0];
            $bestLoad = $loads[$bestMonth];
            $bestMass = (int) ($mass[$bestMonth] ?? 0);
            $bestIndex = 0;

            foreach ($months as $index => $month) {
                $load = $loads[$month];
                $monthMass = (int) ($mass[$month] ?? 0);
                $better = false;
                if ($load < $bestLoad) {
                    $better = true;
                } elseif ($load === $bestLoad) {
                    if ($monthMass > $bestMass) {
                        $better = true;
                    } elseif ($monthMass === $bestMass && $index < $bestIndex) {
                        $better = true;
                    }
                }
                if ($better) {
                    $bestMonth = $month;
                    $bestLoad = $load;
                    $bestMass = $monthMass;
                    $bestIndex = $index;
                }
            }

            $loads[$bestMonth]++;
            if (($mass[$bestMonth] ?? 0) > 0) {
                $mass[$bestMonth]--;
            }
        }

        return $loads;
    }

    /**
     * @param  list<string>  $months
     * @param  list<array{id: int, month: string}>  $movable
     * @param  array<string, int>  $targetMovable
     * @return array<int, string>
     */
    private function assignTasks(array $months, array $movable, array $targetMovable): array
    {
        $need = $targetMovable;
        $byMonth = [];
        foreach ($months as $month) {
            $byMonth[$month] = [];
        }
        foreach ($movable as $row) {
            $byMonth[$row['month']][] = $row['id'];
        }

        $allocation = [];
        $pool = [];

        foreach ($months as $month) {
            $keep = min($need[$month], count($byMonth[$month]));
            for ($i = 0; $i < $keep; $i++) {
                $allocation[$byMonth[$month][$i]] = $month;
            }
            $need[$month] -= $keep;
            for ($i = $keep, $n = count($byMonth[$month]); $i < $n; $i++) {
                $pool[] = $byMonth[$month][$i];
            }
        }

        sort($pool);

        foreach ($pool as $taskId) {
            $dest = null;
            $bestNeed = -1;
            $bestIndex = PHP_INT_MAX;
            foreach ($months as $index => $month) {
                if ($need[$month] <= 0) {
                    continue;
                }
                if ($need[$month] > $bestNeed
                    || ($need[$month] === $bestNeed && $index < $bestIndex)
                ) {
                    $dest = $month;
                    $bestNeed = $need[$month];
                    $bestIndex = $index;
                }
            }
            if ($dest === null) {
                continue;
            }
            $allocation[$taskId] = $dest;
            $need[$dest]--;
        }

        return $allocation;
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, int>  $fixed
     * @param  array<string, int>  $currentMovable
     * @param  array<string, int>  $targetLoads
     */
    private function movableMovement(array $months, array $fixed, array $currentMovable, array $targetLoads): int
    {
        $distance = 0;
        foreach ($months as $month) {
            $targetMovable = max(0, $targetLoads[$month] - $fixed[$month]);
            $distance += abs($targetMovable - (int) ($currentMovable[$month] ?? 0));
        }

        return intdiv($distance, 2);
    }

    /**
     * @param  array<string, int>  $loads
     */
    private function imbalance(array $loads): int
    {
        if ($loads === []) {
            return 0;
        }

        return max($loads) - min($loads);
    }

    /**
     * @param  list<string>  $months
     * @return list<string>
     */
    private function normalizeMonths(array $months): array
    {
        $out = [];
        foreach ($months as $month) {
            $normalized = $this->normalizeMonth((string) $month);
            if ($normalized !== null) {
                $out[$normalized] = $normalized;
            }
        }
        $list = array_values($out);
        sort($list);

        return $list;
    }

    private function normalizeMonth(string $month): ?string
    {
        $month = trim($month);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) === 1) {
            $month = substr($month, 0, 7);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }
        [$y, $m] = array_map('intval', explode('-', $month));
        if ($m < 1 || $m > 12) {
            return null;
        }

        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'months' => [],
            'fixed_by_month' => [],
            'movable_by_month' => [],
            'current_by_month' => [],
            'target_by_month' => [],
            'target_movable_by_month' => [],
            'allocation' => [],
            'moves' => [],
            'move_count' => 0,
            'imbalance_before' => 0,
            'imbalance_after' => 0,
            'fingerprint' => hash('sha256', 'empty'),
        ];
    }
}
