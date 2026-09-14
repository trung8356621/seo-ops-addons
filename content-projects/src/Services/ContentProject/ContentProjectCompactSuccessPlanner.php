<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;

/**
 * Pure planner — gom success items vào existing projects (không tạo project mới).
 *
 * Input/output là array snapshots để unit-test không cần DB.
 */
final class ContentProjectCompactSuccessPlanner
{
    public function capacity(): int
    {
        return ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
    }

    /**
     * @param  array{
     *     site_id: int,
     *     month: string,
     *     projects: list<array{
     *         project_id: int,
     *         name: string,
     *         writer_id: int,
     *         writer_name: string,
     *         archived: bool,
     *         items: list<array{
     *             task_id: int,
     *             site_id: int,
     *             success: bool,
     *             movable: bool,
     *             skip_reason: string|null
     *         }>
     *     }>
     * }  $scope
     * @return array<string, mixed>
     */
    public function plan(array $scope): array
    {
        $capacity = $this->capacity();
        $siteId = (int) ($scope['site_id'] ?? 0);
        $month = (string) ($scope['month'] ?? '');
        $projectsIn = is_array($scope['projects'] ?? null) ? $scope['projects'] : [];

        $projects = [];
        foreach ($projectsIn as $row) {
            if (! is_array($row)) {
                continue;
            }
            $projectId = (int) ($row['project_id'] ?? 0);
            if ($projectId <= 0 || ! empty($row['archived'])) {
                continue;
            }
            $items = [];
            foreach (($row['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $taskId = (int) ($item['task_id'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }
                $items[] = [
                    'task_id' => $taskId,
                    'site_id' => (int) ($item['site_id'] ?? 0),
                    'success' => (bool) ($item['success'] ?? false),
                    'movable' => (bool) ($item['movable'] ?? false),
                    'skip_reason' => isset($item['skip_reason']) ? (string) $item['skip_reason'] : null,
                ];
            }
            $projects[$projectId] = [
                'project_id' => $projectId,
                'name' => (string) ($row['name'] ?? ('#'.$projectId)),
                'writer_id' => (int) ($row['writer_id'] ?? 0),
                'writer_name' => (string) ($row['writer_name'] ?? ''),
                'items' => $items,
            ];
        }

        $before = $this->summarizeProjects($projects, $siteId);
        $skipped = $this->collectSkipped($projects, $siteId);

        // Phase 1: compact riêng từng writer group (không cross-writer).
        $byWriter = [];
        foreach ($projects as $projectId => $project) {
            $writerId = (int) ($project['writer_id'] ?? 0);
            $byWriter[$writerId][$projectId] = $project;
        }

        $allMoves = [];
        $afterProjects = $projects;
        $strictPossible = true;
        $warnings = [];

        foreach ($byWriter as $writerId => $writerProjects) {
            $result = $this->planWriterGroup($writerProjects, $siteId, $capacity);
            foreach ($result['moves'] as $move) {
                $allMoves[] = $move;
            }
            $afterProjects = $this->applyMoves($afterProjects, $result['moves']);
            if (! $result['strict_possible']) {
                $strictPossible = false;
                $warnings = array_merge($warnings, $result['warnings']);
            }
        }

        $after = $this->summarizeProjects($afterProjects, $siteId);
        $successCount = array_sum(array_column($before, 'success_count'));
        $nonSuccessCount = array_sum(array_column($before, 'non_success_count'));
        $totalItems = $successCount + $nonSuccessCount;
        $lockedSkipped = count($skipped);

        $noop = $allMoves === [];
        $canExecute = $strictPossible && ! $noop;

        if ($noop && $successCount > 0) {
            $warnings[] = 'already_compacted';
        }

        return [
            'site_id' => $siteId,
            'month' => $month,
            'capacity' => $capacity,
            'preserve_writer' => true,
            'can_execute' => $canExecute,
            'strict_audit_ready_possible' => $strictPossible,
            'already_compacted' => $noop && $successCount > 0,
            'warnings' => array_values(array_unique($warnings)),
            'totals' => [
                'projects' => count($before),
                'items' => $totalItems,
                'success' => $successCount,
                'non_success' => $nonSuccessCount,
                'locked_skipped' => $lockedSkipped,
                'moves' => count($allMoves),
            ],
            'projects_before' => $before,
            'projects_after' => $after,
            'moves' => $allMoves,
            'skipped' => $skipped,
            'creates_project' => false,
            'archives_project' => false,
        ];
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return array{moves: list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>, strict_possible: bool, warnings: list<string>}
     */
    private function planWriterGroup(array $projects, int $siteId, int $capacity): array
    {
        $moves = [];
        $warnings = [];
        $strictPossible = true;

        if (count($projects) <= 1) {
            // Vẫn có thể clean non-success khỏi target duy nhất nếu có overflow… nhưng không có nơi nhận.
            $only = reset($projects);
            if (! is_array($only)) {
                return ['moves' => [], 'strict_possible' => true, 'warnings' => []];
            }
            $success = $this->scopedSuccessCount($only, $siteId);
            $nonSuccessMovable = $this->scopedMovableNonSuccess($only, $siteId);
            if ($success > 0 && $nonSuccessMovable !== [] && count($projects) === 1) {
                $strictPossible = false;
                $warnings[] = 'no_overflow_for_non_success';
            }

            return ['moves' => [], 'strict_possible' => $strictPossible, 'warnings' => $warnings];
        }

        $ranked = $this->rankTargets($projects, $siteId);
        $successTotal = 0;
        foreach ($projects as $project) {
            // Mọi success (movable + locked) cần chỗ trong target.
            $successTotal += $this->scopedSuccessCount($project, $siteId);
        }

        if ($successTotal <= 0) {
            return ['moves' => [], 'strict_possible' => true, 'warnings' => ['no_success_items']];
        }

        $targetIds = [];
        $slotsNeeded = $successTotal;
        foreach ($ranked as $projectId) {
            $targetIds[] = $projectId;
            $slotsNeeded -= $capacity;
            if ($slotsNeeded <= 0) {
                break;
            }
        }

        $targetSet = array_fill_keys($targetIds, true);
        $working = $projects;

        // 1) Move non-success out of targets (strict audit-ready).
        $overflowIds = array_values(array_filter(
            array_keys($working),
            static fn (int $id): bool => ! isset($targetSet[$id]),
        ));
        // Prefer overflow: most non-success first, then id.
        usort(
            $overflowIds,
            function (int $a, int $b) use ($working, $siteId): int {
                $na = $this->scopedNonSuccessCount($working[$a], $siteId);
                $nb = $this->scopedNonSuccessCount($working[$b], $siteId);

                return ($nb <=> $na) ?: ($a <=> $b);
            },
        );

        foreach ($targetIds as $targetId) {
            $nonSuccess = $this->scopedMovableNonSuccess($working[$targetId], $siteId);
            foreach ($nonSuccess as $taskId) {
                $dest = $this->findOverflowDestination($working, $overflowIds, $targetId, $capacity, $siteId);
                if ($dest === null) {
                    $strictPossible = false;
                    $warnings[] = 'cannot_clean_target_'.$targetId;

                    continue;
                }
                $moves[] = [
                    'task_id' => $taskId,
                    'from_project_id' => $targetId,
                    'to_project_id' => $dest,
                    'reason' => 'clean_nonsuccess',
                ];
                $working = $this->applyMoves($working, [end($moves)]);
            }
        }

        // 2) Pack success into targets (highest first).
        $successQueue = $this->buildSuccessQueue($working, $ranked, $siteId);

        foreach ($targetIds as $targetId) {
            $free = $this->freeSlots($working[$targetId], $capacity);
            // Success đã nằm trong target: giữ nguyên, chỉ loại khỏi hàng đợi import.
            foreach ($working[$targetId]['items'] as $item) {
                if ((int) $item['site_id'] !== $siteId || ! $item['success']) {
                    continue;
                }
                $successQueue = array_values(array_filter(
                    $successQueue,
                    static fn (array $row): bool => (int) $row['task_id'] !== (int) $item['task_id'],
                ));
            }

            while ($free > 0 && $successQueue !== []) {
                $next = array_shift($successQueue);
                $fromId = (int) $next['project_id'];
                $taskId = (int) $next['task_id'];
                if ($fromId === $targetId) {
                    continue;
                }
                if (! $next['movable']) {
                    continue;
                }
                $moves[] = [
                    'task_id' => $taskId,
                    'from_project_id' => $fromId,
                    'to_project_id' => $targetId,
                    'reason' => 'pack_success',
                ];
                $working = $this->applyMoves($working, [end($moves)]);
                $free--;
            }
        }

        // Idempotency: drop no-op / cycles that net to same assignment.
        $moves = $this->netMoves($moves);

        return [
            'moves' => $moves,
            'strict_possible' => $strictPossible,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return list<int>
     */
    private function rankTargets(array $projects, int $siteId): array
    {
        $ids = array_keys($projects);
        usort(
            $ids,
            function (int $a, int $b) use ($projects, $siteId): int {
                $sa = $this->scopedSuccessCount($projects[$a], $siteId);
                $sb = $this->scopedSuccessCount($projects[$b], $siteId);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }
                $la = $this->lockedOrRunningCount($projects[$a], $siteId);
                $lb = $this->lockedOrRunningCount($projects[$b], $siteId);
                if ($la !== $lb) {
                    return $la <=> $lb;
                }

                return $a <=> $b;
            },
        );

        return $ids;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}  $project
     */
    private function scopedSuccessCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ((int) $item['site_id'] === $siteId && $item['success']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}  $project
     */
    private function scopedNonSuccessCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ((int) $item['site_id'] === $siteId && ! $item['success']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}  $project
     * @return list<int>
     */
    private function scopedMovableNonSuccess(array $project, int $siteId): array
    {
        $ids = [];
        foreach ($project['items'] as $item) {
            if ((int) $item['site_id'] === $siteId && ! $item['success'] && $item['movable']) {
                $ids[] = (int) $item['task_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}  $project
     */
    private function lockedOrRunningCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ((int) $item['site_id'] !== $siteId) {
                continue;
            }
            if (! $item['movable']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}  $project
     */
    private function freeSlots(array $project, int $capacity): int
    {
        return max(0, $capacity - count($project['items']));
    }

    /**
     * @param  array<int, array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @param  list<int>  $overflowIds
     */
    private function findOverflowDestination(
        array $projects,
        array $overflowIds,
        int $fromProjectId,
        int $capacity,
        int $siteId,
    ): ?int {
        unset($siteId);
        foreach ($overflowIds as $destId) {
            if ($destId === $fromProjectId) {
                continue;
            }
            if ($this->freeSlots($projects[$destId], $capacity) > 0) {
                return $destId;
            }
        }

        // Fallback: any non-source project with free slot (may dilute strict cleanliness of lower targets).
        foreach ($projects as $destId => $project) {
            if ($destId === $fromProjectId) {
                continue;
            }
            if ($this->freeSlots($project, $capacity) > 0) {
                return $destId;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{project_id: int, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @param  list<int>  $ranked
     * @return list<array{task_id: int, project_id: int, movable: bool}>
     */
    private function buildSuccessQueue(array $projects, array $ranked, int $siteId): array
    {
        $rankIndex = array_flip($ranked);
        $rows = [];
        foreach ($projects as $projectId => $project) {
            foreach ($project['items'] as $item) {
                if ((int) $item['site_id'] !== $siteId || ! $item['success']) {
                    continue;
                }
                $rows[] = [
                    'task_id' => (int) $item['task_id'],
                    'project_id' => (int) $projectId,
                    'movable' => (bool) $item['movable'],
                    'rank' => (int) ($rankIndex[$projectId] ?? 9999),
                ];
            }
        }

        // Prefer items already in high-rank targets (minimize moves): process low rank first for keep,
        // but when importing, take from worst rank first.
        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['rank'] <=> $a['rank'])
                ?: ($a['task_id'] <=> $b['task_id']),
        );

        return $rows;
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @param  list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>  $moves
     * @return array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>
     */
    private function applyMoves(array $projects, array $moves): array
    {
        foreach ($moves as $move) {
            $taskId = (int) $move['task_id'];
            $from = (int) $move['from_project_id'];
            $to = (int) $move['to_project_id'];
            if ($from === $to || ! isset($projects[$from], $projects[$to])) {
                continue;
            }
            $moved = null;
            $remaining = [];
            foreach ($projects[$from]['items'] as $item) {
                if ((int) $item['task_id'] === $taskId) {
                    $moved = $item;
                } else {
                    $remaining[] = $item;
                }
            }
            if ($moved === null) {
                continue;
            }
            $projects[$from]['items'] = $remaining;
            $projects[$to]['items'][] = $moved;
        }

        return $projects;
    }

    /**
     * @param  list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>  $moves
     * @return list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>
     */
    private function netMoves(array $moves): array
    {
        /** @var array<int, array{from: int, to: int, reason: string}> $final */
        $final = [];
        foreach ($moves as $move) {
            $taskId = (int) $move['task_id'];
            $from = (int) $move['from_project_id'];
            $to = (int) $move['to_project_id'];
            $reason = (string) $move['reason'];
            if ($from === $to) {
                continue;
            }
            if (! isset($final[$taskId])) {
                $final[$taskId] = ['from' => $from, 'to' => $to, 'reason' => $reason];

                continue;
            }
            // Chain: A→B then B→C becomes A→C
            $origin = $final[$taskId]['from'];
            $final[$taskId] = [
                'from' => $origin,
                'to' => $to,
                'reason' => $reason === 'pack_success' || $final[$taskId]['reason'] === 'pack_success'
                    ? 'pack_success'
                    : $reason,
            ];
            if ($final[$taskId]['from'] === $final[$taskId]['to']) {
                unset($final[$taskId]);
            }
        }

        $out = [];
        foreach ($final as $taskId => $row) {
            $out[] = [
                'task_id' => (int) $taskId,
                'from_project_id' => (int) $row['from'],
                'to_project_id' => (int) $row['to'],
                'reason' => (string) $row['reason'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return list<array<string, mixed>>
     */
    private function summarizeProjects(array $projects, int $siteId): array
    {
        $rows = [];
        foreach ($projects as $project) {
            $success = 0;
            $nonSuccess = 0;
            $foreign = 0;
            foreach ($project['items'] as $item) {
                if ((int) $item['site_id'] !== $siteId) {
                    $foreign++;

                    continue;
                }
                if ($item['success']) {
                    $success++;
                } else {
                    $nonSuccess++;
                }
            }
            $totalScoped = $success + $nonSuccess;
            $auditReady = $totalScoped > 0 && $nonSuccess === 0 && $foreign === 0
                && count($project['items']) === $success;

            $rows[] = [
                'project_id' => (int) $project['project_id'],
                'name' => (string) $project['name'],
                'writer_id' => (int) $project['writer_id'],
                'writer_name' => (string) $project['writer_name'],
                'success_count' => $success,
                'non_success_count' => $nonSuccess,
                'foreign_count' => $foreign,
                'total' => count($project['items']),
                'audit_ready' => $auditReady,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['success_count'] <=> $a['success_count'])
                ?: ((int) $a['project_id'] <=> (int) $b['project_id']),
        );

        return $rows;
    }

    /**
     * @param  array<int, array{items: list<array{task_id: int, site_id: int, success: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return list<array{task_id: int, reason: string}>
     */
    private function collectSkipped(array $projects, int $siteId): array
    {
        $out = [];
        foreach ($projects as $project) {
            foreach ($project['items'] as $item) {
                if ((int) $item['site_id'] !== $siteId) {
                    continue;
                }
                if ($item['movable']) {
                    continue;
                }
                $out[] = [
                    'task_id' => (int) $item['task_id'],
                    'reason' => (string) ($item['skip_reason'] ?? 'locked'),
                ];
            }
        }

        return $out;
    }
}
