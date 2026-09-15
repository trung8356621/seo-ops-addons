<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;

/**
 * Pure auto-partition planner — tách generator_done khỏi not_done (không chọn project đích thủ công).
 *
 * DONE BUCKET: chỉ chứa generator_done (sau khi dọn not_done ra).
 * WORK BUCKET: chứa not_done / SEO Audit / Planner / failed / pending.
 */
final class ContentProjectCompactSuccessPlanner
{
    public const KIND_GENERATOR_DONE = 'generator_done';

    public const KIND_NOT_DONE = 'not_done';

    public const KIND_UNSAFE_LOCKED = 'unsafe_locked';

    public const SKIP_ACTIVE_RUNNING = 'active_running';

    public const SKIP_FAILED = 'failed';

    public const SKIP_PENDING = 'pending';

    public const SKIP_MISSING_CONTENT = 'missing_generated_content';

    public const SKIP_SCHEDULED_PUBLISHED = 'scheduled_published_unsafe';

    public const SKIP_WRONG_DOMAIN_MONTH = 'wrong_domain_month';

    public const SKIP_CAPACITY = 'capacity_limit';

    public const SKIP_LOCKED = 'locked';

    public const REASON_PACK_GENERATOR_DONE = 'pack_generator_done';

    public const REASON_CLEAN_NOT_DONE = 'clean_not_done_from_done_bucket';

    public function capacity(): int
    {
        return ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
    }

    /**
     * @param  array{
     *     site_id: int,
     *     month: string,
     *     blocked?: bool,
     *     block_reason?: string|null,
     *     projects: list<array{
     *         project_id: int,
     *         name: string,
     *         writer_id: int,
     *         writer_name: string,
     *         archived: bool,
     *         items: list<array{
     *             task_id: int,
     *             site_id: int,
     *             kind: string,
     *             generator_done: bool,
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
        $siteId = max(0, (int) ($scope['site_id'] ?? 0));
        $month = (string) ($scope['month'] ?? '');
        $blocked = ! empty($scope['blocked']);
        $blockReason = isset($scope['block_reason']) ? (string) $scope['block_reason'] : null;

        $projects = $this->normalizeProjects($scope['projects'] ?? []);
        $before = $this->summarizeProjects($projects, $siteId);

        $allMoves = [];
        $afterProjects = $projects;
        $doneBucketIds = [];
        $workBucketIds = [];
        $warnings = [];
        $strictPossible = true;

        if ($blocked) {
            $warnings[] = 'blocked:'.($blockReason ?: self::SKIP_ACTIVE_RUNNING);
        } else {
            $result = $this->planBucketGroup($projects, $siteId, $capacity);
            foreach ($result['moves'] as $move) {
                $allMoves[] = $move;
            }
            $afterProjects = $this->applyMoves($afterProjects, $result['moves']);
            foreach ($result['done_bucket_ids'] as $id) {
                $doneBucketIds[$id] = true;
            }
            foreach ($result['work_bucket_ids'] as $id) {
                $workBucketIds[$id] = true;
            }
            if (! $result['strict_possible']) {
                $strictPossible = false;
                $warnings = array_merge($warnings, $result['warnings']);
            }
        }

        $after = $this->summarizeProjects($afterProjects, $siteId, $allMoves);
        $skipped = $this->collectSkipped($projects, $siteId);
        $skippedSummary = $this->summarizeSkipReasons($skipped);

        $generatorDone = array_sum(array_column($before, 'generator_done_count'));
        $notDone = array_sum(array_column($before, 'not_done_count'));
        $unsafe = array_sum(array_column($before, 'unsafe_count'));

        $movesDone = 0;
        $movesNotDone = 0;
        foreach ($allMoves as $move) {
            if (($move['reason'] ?? '') === self::REASON_PACK_GENERATOR_DONE) {
                $movesDone++;
            } elseif (($move['reason'] ?? '') === self::REASON_CLEAN_NOT_DONE) {
                $movesNotDone++;
            }
        }

        $noop = $allMoves === [];
        $canExecute = ! $blocked && $strictPossible && ! $noop;

        if ($noop && $generatorDone > 0 && ! $blocked) {
            $warnings[] = 'already_partitioned';
        }

        $doneBucketList = $this->bucketRows($after, array_keys($doneBucketIds));
        $workBucketList = $this->bucketRows($after, array_keys($workBucketIds));

        return [
            'site_id' => $siteId,
            'month' => $month,
            'capacity' => $capacity,
            'preserve_writer' => false,
            'can_execute' => $canExecute,
            'blocked' => $blocked,
            'block_reason' => $blockReason,
            'strict_possible' => $strictPossible,
            'already_compacted' => in_array('already_partitioned', $warnings, true),
            'already_partitioned' => in_array('already_partitioned', $warnings, true),
            'warnings' => array_values(array_unique($warnings)),
            'done_bucket_project_ids' => array_map('intval', array_keys($doneBucketIds)),
            'work_bucket_project_ids' => array_map('intval', array_keys($workBucketIds)),
            'done_buckets' => $doneBucketList,
            'work_buckets' => $workBucketList,
            'totals' => [
                'projects' => count($before),
                'items' => $generatorDone + $notDone + $unsafe,
                'generator_done' => $generatorDone,
                'not_done' => $notDone,
                'unsafe' => $unsafe,
                'moves' => count($allMoves),
                'moves_generator_done' => $movesDone,
                'moves_not_done' => $movesNotDone,
                'skipped' => count($skipped),
                'locked_skipped' => count($skipped),
                // Back-compat aliases used by older UI strings.
                'done' => $generatorDone,
                'unfinished' => $notDone,
                'success' => $generatorDone,
                'non_success' => $notDone,
                'eligible_done' => $generatorDone,
            ],
            'skipped_summary' => $skippedSummary,
            'projects_before' => $before,
            'projects_after' => $after,
            'moves' => $allMoves,
            'skipped' => $skipped,
            'creates_project' => false,
            'archives_project' => false,
        ];
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return array{
     *     moves: list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>,
     *     done_bucket_ids: list<int>,
     *     work_bucket_ids: list<int>,
     *     strict_possible: bool,
     *     warnings: list<string>
     * }
     */
    private function planBucketGroup(array $projects, int $siteId, int $capacity): array
    {
        $moves = [];
        $warnings = [];
        $strictPossible = true;

        if ($projects === []) {
            return [
                'moves' => [],
                'done_bucket_ids' => [],
                'work_bucket_ids' => [],
                'strict_possible' => true,
                'warnings' => [],
            ];
        }

        $generatorDoneTotal = 0;
        $movableGeneratorDone = 0;
        foreach ($projects as $project) {
            foreach ($project['items'] as $item) {
                if (! $this->itemInScope($item, $siteId)) {
                    continue;
                }
                if (! $item['generator_done']) {
                    continue;
                }
                $generatorDoneTotal++;
                if ($item['movable']) {
                    $movableGeneratorDone++;
                }
            }
        }

        if ($generatorDoneTotal <= 0) {
            return [
                'moves' => [],
                'done_bucket_ids' => [],
                'work_bucket_ids' => array_map('intval', array_keys($projects)),
                'strict_possible' => true,
                'warnings' => ['no_generator_done_items'],
            ];
        }

        $doneBucketIds = $this->selectDoneBuckets($projects, $siteId, $capacity, $generatorDoneTotal);
        $doneSet = array_fill_keys($doneBucketIds, true);
        $workBucketIds = array_values(array_filter(
            array_keys($projects),
            static fn (int $id): bool => ! isset($doneSet[$id]),
        ));

        // Prefer overflow work buckets with most free capacity / most not_done already.
        usort(
            $workBucketIds,
            function (int $a, int $b) use ($projects, $siteId): int {
                $na = $this->scopedNotDoneCount($projects[$a], $siteId);
                $nb = $this->scopedNotDoneCount($projects[$b], $siteId);

                return ($nb <=> $na) ?: ($a <=> $b);
            },
        );

        $working = $projects;

        // 1) Clean movable not_done out of done buckets.
        foreach ($doneBucketIds as $doneId) {
            foreach ($this->scopedMovableNotDone($working[$doneId], $siteId) as $taskId) {
                $dest = $this->findWorkDestination($working, $workBucketIds, $doneId, $capacity);
                if ($dest === null) {
                    $strictPossible = false;
                    $warnings[] = 'cannot_clean_done_bucket_'.$doneId;

                    continue;
                }
                $moves[] = [
                    'task_id' => $taskId,
                    'from_project_id' => $doneId,
                    'to_project_id' => $dest,
                    'reason' => self::REASON_CLEAN_NOT_DONE,
                ];
                $working = $this->applyMoves($working, [end($moves)]);
            }
        }

        // 2) Pack movable generator_done into done buckets.
        $queue = $this->buildGeneratorDoneQueue($working, $doneBucketIds, $siteId);
        foreach ($doneBucketIds as $doneId) {
            // Items already in this done bucket stay; remove from import queue.
            foreach ($working[$doneId]['items'] as $item) {
                if (! $this->itemInScope($item, $siteId) || ! $item['generator_done']) {
                    continue;
                }
                $queue = array_values(array_filter(
                    $queue,
                    static fn (array $row): bool => (int) $row['task_id'] !== (int) $item['task_id'],
                ));
            }

            $free = $this->freeSlots($working[$doneId], $capacity);
            while ($free > 0 && $queue !== []) {
                $next = array_shift($queue);
                if (! $next['movable']) {
                    continue;
                }
                $fromId = (int) $next['project_id'];
                if ($fromId === $doneId) {
                    continue;
                }
                $moves[] = [
                    'task_id' => (int) $next['task_id'],
                    'from_project_id' => $fromId,
                    'to_project_id' => $doneId,
                    'reason' => self::REASON_PACK_GENERATOR_DONE,
                ];
                $working = $this->applyMoves($working, [end($moves)]);
                $free--;
            }
        }

        if ($queue !== []) {
            $stillMovable = array_filter($queue, static fn (array $row): bool => (bool) $row['movable']);
            if ($stillMovable !== []) {
                $strictPossible = false;
                $warnings[] = self::SKIP_CAPACITY;
            }
        }

        // If no separate work bucket exists but we needed to clean, flag it.
        if ($workBucketIds === [] && $movableGeneratorDone > 0) {
            $needsClean = false;
            foreach ($doneBucketIds as $doneId) {
                if ($this->scopedMovableNotDone($projects[$doneId], $siteId) !== []) {
                    $needsClean = true;
                    break;
                }
            }
            if ($needsClean) {
                $strictPossible = false;
                $warnings[] = 'no_work_bucket_for_not_done';
            }
        }

        $moves = $this->netMoves($moves);

        return [
            'moves' => $moves,
            'done_bucket_ids' => $doneBucketIds,
            'work_bucket_ids' => $workBucketIds,
            'strict_possible' => $strictPossible,
            'warnings' => $warnings,
        ];
    }

    /**
     * Prefer projects already dense with generator_done / locked generator_done anchors.
     *
     * @param  array<int, array{items: list<array{site_id: int, generator_done: bool, movable: bool}>}>  $projects
     * @return list<int>
     */
    private function selectDoneBuckets(array $projects, int $siteId, int $capacity, int $generatorDoneTotal): array
    {
        $needed = max(1, (int) ceil($generatorDoneTotal / max(1, $capacity)));
        $needed = min($needed, count($projects));

        $ids = array_keys($projects);
        usort(
            $ids,
            function (int $a, int $b) use ($projects, $siteId): int {
                $ga = $this->scopedGeneratorDoneCount($projects[$a], $siteId);
                $gb = $this->scopedGeneratorDoneCount($projects[$b], $siteId);
                if ($ga !== $gb) {
                    return $gb <=> $ga;
                }
                $lockedA = $this->scopedLockedGeneratorDoneCount($projects[$a], $siteId);
                $lockedB = $this->scopedLockedGeneratorDoneCount($projects[$b], $siteId);
                if ($lockedA !== $lockedB) {
                    return $lockedB <=> $lockedA;
                }
                $na = $this->scopedNotDoneCount($projects[$a], $siteId);
                $nb = $this->scopedNotDoneCount($projects[$b], $siteId);
                if ($na !== $nb) {
                    return $na <=> $nb;
                }

                return $a <=> $b;
            },
        );

        // Locked generator_done must live in a done bucket when possible.
        $selected = [];
        foreach ($ids as $id) {
            if ($this->scopedLockedGeneratorDoneCount($projects[$id], $siteId) > 0) {
                $selected[$id] = true;
            }
        }
        foreach ($ids as $id) {
            if (count($selected) >= $needed) {
                break;
            }
            $selected[$id] = true;
        }

        // Ensure enough slots for all generator_done after selection.
        while (count($selected) < count($projects)
            && (count($selected) * $capacity) < $generatorDoneTotal
        ) {
            foreach ($ids as $id) {
                if (! isset($selected[$id])) {
                    $selected[$id] = true;
                    break;
                }
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($selected[$id])) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * @param  mixed  $projectsIn
     * @return array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>}>
     */
    private function normalizeProjects(mixed $projectsIn): array
    {
        $projects = [];
        if (! is_array($projectsIn)) {
            return $projects;
        }
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
                $generatorDone = (bool) ($item['generator_done'] ?? $item['done'] ?? false);
                $kind = (string) ($item['kind'] ?? (
                    $generatorDone ? self::KIND_GENERATOR_DONE : self::KIND_NOT_DONE
                ));
                $items[] = [
                    'task_id' => $taskId,
                    'site_id' => (int) ($item['site_id'] ?? 0),
                    'kind' => $kind,
                    'generator_done' => $generatorDone,
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

        return $projects;
    }

    /**
     * @param  array{items: list<array{site_id: int, generator_done: bool}>}  $project
     */
    private function scopedGeneratorDoneCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ($this->itemInScope($item, $siteId) && $item['generator_done']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{site_id: int, generator_done: bool, movable: bool}>}  $project
     */
    private function scopedLockedGeneratorDoneCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ($this->itemInScope($item, $siteId) && $item['generator_done'] && ! $item['movable']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{site_id: int, generator_done: bool}>}  $project
     */
    private function scopedNotDoneCount(array $project, int $siteId): int
    {
        $n = 0;
        foreach ($project['items'] as $item) {
            if ($this->itemInScope($item, $siteId) && ! $item['generator_done']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, generator_done: bool, movable: bool}>}  $project
     * @return list<int>
     */
    private function scopedMovableNotDone(array $project, int $siteId): array
    {
        $ids = [];
        foreach ($project['items'] as $item) {
            if ($this->itemInScope($item, $siteId) && ! $item['generator_done'] && $item['movable']) {
                $ids[] = (int) $item['task_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array{items: list<array{task_id: int, site_id: int, generator_done: bool, movable: bool}>}  $project
     */
    private function freeSlots(array $project, int $capacity): int
    {
        return max(0, $capacity - count($project['items']));
    }

    /**
     * @param  array<int, array{items: list<array{task_id: int}>}>  $projects
     * @param  list<int>  $workBucketIds
     */
    private function findWorkDestination(
        array $projects,
        array $workBucketIds,
        int $fromProjectId,
        int $capacity,
    ): ?int {
        foreach ($workBucketIds as $destId) {
            if ($destId === $fromProjectId) {
                continue;
            }
            if ($this->freeSlots($projects[$destId], $capacity) > 0) {
                return $destId;
            }
        }

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
     * @param  array<int, array{project_id: int, items: list<array{task_id: int, site_id: int, generator_done: bool, movable: bool}>}>  $projects
     * @param  list<int>  $doneBucketIds
     * @return list<array{task_id: int, project_id: int, movable: bool}>
     */
    private function buildGeneratorDoneQueue(array $projects, array $doneBucketIds, int $siteId): array
    {
        $rank = array_flip($doneBucketIds);
        $rows = [];
        foreach ($projects as $projectId => $project) {
            foreach ($project['items'] as $item) {
                if (! $this->itemInScope($item, $siteId) || ! $item['generator_done']) {
                    continue;
                }
                $rows[] = [
                    'task_id' => (int) $item['task_id'],
                    'project_id' => (int) $projectId,
                    'movable' => (bool) $item['movable'],
                    'rank' => (int) ($rank[$projectId] ?? 9999),
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['rank'] <=> $a['rank'])
                ?: ($a['task_id'] <=> $b['task_id']),
        );

        return $rows;
    }

    /**
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @param  list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>  $moves
     * @return array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>}>
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
            $origin = $final[$taskId]['from'];
            $final[$taskId] = [
                'from' => $origin,
                'to' => $to,
                'reason' => $reason === self::REASON_PACK_GENERATOR_DONE
                    || $final[$taskId]['reason'] === self::REASON_PACK_GENERATOR_DONE
                    ? self::REASON_PACK_GENERATOR_DONE
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
     * @param  array<int, array{project_id: int, name: string, writer_id: int, writer_name: string, items: list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @param  list<array{task_id: int, from_project_id: int, to_project_id: int, reason: string}>  $moves
     * @return list<array<string, mixed>>
     */
    private function summarizeProjects(array $projects, int $siteId, array $moves = []): array
    {
        $movesIn = [];
        $movesOut = [];
        foreach ($moves as $move) {
            $to = (int) $move['to_project_id'];
            $from = (int) $move['from_project_id'];
            $movesIn[$to] = ($movesIn[$to] ?? 0) + 1;
            $movesOut[$from] = ($movesOut[$from] ?? 0) + 1;
        }

        $rows = [];
        foreach ($projects as $project) {
            $generatorDone = 0;
            $notDone = 0;
            $unsafe = 0;
            $foreign = 0;
            foreach ($project['items'] as $item) {
                if (! $this->itemInScope($item, $siteId)) {
                    $foreign++;

                    continue;
                }
                if (($item['kind'] ?? '') === self::KIND_UNSAFE_LOCKED || (! $item['movable'] && $item['skip_reason'])) {
                    $unsafe++;
                }
                if ($item['generator_done']) {
                    $generatorDone++;
                } else {
                    $notDone++;
                }
            }
            $projectId = (int) $project['project_id'];
            $rows[] = [
                'project_id' => $projectId,
                'name' => (string) $project['name'],
                'writer_id' => (int) $project['writer_id'],
                'writer_name' => (string) $project['writer_name'],
                'generator_done_count' => $generatorDone,
                'not_done_count' => $notDone,
                'unsafe_count' => $unsafe,
                'done_count' => $generatorDone,
                'unfinished_count' => $notDone,
                'success_count' => $generatorDone,
                'non_success_count' => $notDone,
                'foreign_count' => $foreign,
                'total' => $generatorDone + $notDone,
                'moves_in' => (int) ($movesIn[$projectId] ?? 0),
                'moves_out' => (int) ($movesOut[$projectId] ?? 0),
                'is_done_bucket' => $generatorDone > 0 && $notDone === 0,
                'is_work_bucket' => $notDone > 0 && $generatorDone === 0,
                'audit_ready' => $generatorDone > 0 && $notDone === 0 && $foreign === 0,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['generator_done_count'] <=> $a['generator_done_count'])
                ?: ((int) $a['project_id'] <=> (int) $b['project_id']),
        );

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function bucketRows(array $rows, array $ids): array
    {
        $set = array_fill_keys(array_map('intval', $ids), true);
        $out = [];
        foreach ($rows as $row) {
            if (isset($set[(int) ($row['project_id'] ?? 0)])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{project_id: int, items: list<array{task_id: int, site_id: int, generator_done: bool, movable: bool, skip_reason: string|null}>}>  $projects
     * @return list<array{task_id: int, reason: string}>
     */
    private function collectSkipped(array $projects, int $siteId): array
    {
        $out = [];
        foreach ($projects as $project) {
            foreach ($project['items'] as $item) {
                if (! $this->itemInScope($item, $siteId)) {
                    $out[] = [
                        'task_id' => (int) $item['task_id'],
                        'reason' => self::SKIP_WRONG_DOMAIN_MONTH,
                    ];

                    continue;
                }
                if ($item['movable']) {
                    continue;
                }
                $out[] = [
                    'task_id' => (int) $item['task_id'],
                    'reason' => $this->normalizeSkipReason((string) ($item['skip_reason'] ?? self::SKIP_LOCKED)),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{task_id: int, reason: string}>  $skipped
     * @return array<string, int>
     */
    private function summarizeSkipReasons(array $skipped): array
    {
        $summary = [
            self::SKIP_ACTIVE_RUNNING => 0,
            self::SKIP_FAILED => 0,
            self::SKIP_PENDING => 0,
            self::SKIP_MISSING_CONTENT => 0,
            self::SKIP_SCHEDULED_PUBLISHED => 0,
            self::SKIP_WRONG_DOMAIN_MONTH => 0,
            self::SKIP_CAPACITY => 0,
            self::SKIP_LOCKED => 0,
            'other' => 0,
        ];

        foreach ($skipped as $row) {
            $reason = $this->normalizeSkipReason((string) ($row['reason'] ?? ''));
            if (isset($summary[$reason]) && $reason !== 'other') {
                $summary[$reason]++;
            } else {
                $summary['other']++;
            }
        }

        return $summary;
    }

    private function normalizeSkipReason(string $reason): string
    {
        $reason = strtolower(trim($reason));

        return match (true) {
            in_array($reason, [
                self::SKIP_ACTIVE_RUNNING,
                'ai_running',
                'active_dispatch',
                'bulk_generation_active',
                'project_ai_running',
                'publish_queue_processing',
                'active_editor_session',
                'processing',
                'queued',
                'waiting_retry',
            ], true) => self::SKIP_ACTIVE_RUNNING,
            in_array($reason, [self::SKIP_FAILED, 'failed'], true) => self::SKIP_FAILED,
            in_array($reason, [self::SKIP_PENDING, 'pending', 'manual_pending', 'planner_import', 'seo_audit_import'], true) => self::SKIP_PENDING,
            in_array($reason, [self::SKIP_MISSING_CONTENT, 'missing_content', 'false_success', 'missing_generated_content'], true) => self::SKIP_MISSING_CONTENT,
            in_array($reason, [
                self::SKIP_SCHEDULED_PUBLISHED,
                'scheduled',
                'published',
                'scheduled_published_excluded',
                'scheduled_published_unsafe',
            ], true) => self::SKIP_SCHEDULED_PUBLISHED,
            in_array($reason, [self::SKIP_WRONG_DOMAIN_MONTH, 'wrong_domain', 'wrong_month', 'cross_domain', 'cross_month'], true) => self::SKIP_WRONG_DOMAIN_MONTH,
            in_array($reason, [self::SKIP_CAPACITY, 'capacity', 'capacity_limit'], true) => self::SKIP_CAPACITY,
            $reason === '' => self::SKIP_LOCKED,
            default => self::SKIP_LOCKED,
        };
    }

    /**
     * site_id=0 means month-wide compaction across all item domains.
     *
     * @param  array{site_id?: int}  $item
     */
    private function itemInScope(array $item, int $siteId): bool
    {
        return $siteId <= 0 || (int) ($item['site_id'] ?? 0) === $siteId;
    }
}
