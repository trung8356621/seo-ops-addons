<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Distinguishes a live project-level bulk/test run from stale or per-item runs.
 * Reuses ContentProjectExecutionStalenessPolicy timeout — no second TTL.
 */
final class ContentProjectActiveGenerationRunDetector
{
    public function __construct(
        private readonly ?ContentProjectExecutionStalenessPolicy $staleness = null,
    ) {}

    public function hasActiveBulkGeneration(int $projectId): bool
    {
        return $this->firstMatchingRun($projectId, static fn (array $snap): bool => $snap['is_bulk'] && $snap['is_live']) !== null;
    }

    public function hasActiveTestRun(int $projectId): bool
    {
        return $this->firstMatchingRun($projectId, static fn (array $snap): bool => $snap['is_test'] && $snap['is_live']) !== null;
    }

    /**
     * Pure snapshot — unit tests không cần DB.
     *
     * @param  array{
     *     status?: string,
     *     mode?: string,
     *     total?: int,
     *     task_ids?: list<int>,
     *     items?: mixed,
     *     started_at?: CarbonInterface|string|null,
     *     updated_at?: CarbonInterface|string|null,
     *     finished_at?: CarbonInterface|string|null,
     * }  $snapshot
     * @return array{is_live: bool, is_bulk: bool, is_test: bool, item_count: int}
     */
    public function classifyRunSnapshot(array $snapshot, ?CarbonInterface $now = null, ?int $timeoutMinutes = null): array
    {
        $mode = strtolower(trim((string) ($snapshot['mode'] ?? SeoProjectRun::MODE_FULL)));
        $isTest = $mode === SeoProjectRun::MODE_TEST;
        $itemCount = $this->itemCountFromSnapshot($snapshot);
        $isBulk = ! $isTest && $itemCount > 1;

        return [
            'is_live' => $this->isLiveRunSnapshot($snapshot, $now, $timeoutMinutes),
            'is_bulk' => $isBulk,
            'is_test' => $isTest,
            'item_count' => $itemCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function isLiveRunSnapshot(array $snapshot, ?CarbonInterface $now = null, ?int $timeoutMinutes = null): bool
    {
        if (($snapshot['finished_at'] ?? null) !== null && (string) $snapshot['finished_at'] !== '') {
            return false;
        }

        $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
        if (! in_array($status, [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING], true)) {
            return false;
        }

        $now = $now ?? Carbon::now();
        $timeout = max(1, $timeoutMinutes ?? $this->staleness?->staleTimeoutMinutes() ?? 30);
        $cutoff = $now->copy()->subMinutes($timeout);
        $progress = $this->toCarbon($snapshot['updated_at'] ?? null)
            ?? $this->toCarbon($snapshot['started_at'] ?? null);

        if ($progress === null) {
            return false;
        }

        return $progress->gt($cutoff);
    }

    /**
     * @param  callable(array{is_live: bool, is_bulk: bool, is_test: bool, item_count: int}): bool  $predicate
     * @return array<string, mixed>|null
     */
    private function firstMatchingRun(int $projectId, callable $predicate): ?array
    {
        if ($projectId <= 0) {
            return null;
        }

        $timeout = $this->staleness?->staleTimeoutMinutes() ?? 30;
        $now = Carbon::now();
        $runs = SeoProjectRun::query()
            ->where('project_id', $projectId)
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'status', 'mode', 'total', 'items', 'settings', 'started_at', 'updated_at', 'finished_at']);

        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            $settings = is_array($run->settings) ? $run->settings : [];
            $taskIds = $settings['task_ids'] ?? [];
            $classified = $this->classifyRunSnapshot([
                'status' => (string) $run->status,
                'mode' => (string) ($run->mode ?? SeoProjectRun::MODE_FULL),
                'total' => (int) ($run->total ?? 0),
                'task_ids' => is_array($taskIds) ? $taskIds : [],
                'items' => $run->items,
                'started_at' => $run->started_at,
                'updated_at' => $run->updated_at,
                'finished_at' => $run->finished_at,
            ], $now, $timeout);

            if ($predicate($classified)) {
                return $classified;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function itemCountFromSnapshot(array $snapshot): int
    {
        $total = (int) ($snapshot['total'] ?? 0);
        $taskIds = $snapshot['task_ids'] ?? [];
        $fromIds = is_array($taskIds) ? count($taskIds) : 0;
        $items = $snapshot['items'] ?? null;
        $fromItems = is_array($items) ? count($items) : 0;

        return max($total, $fromIds, $fromItems);
    }

    private function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
