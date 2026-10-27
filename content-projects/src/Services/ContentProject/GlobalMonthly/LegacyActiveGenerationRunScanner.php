<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveGenerationRunDetector;

/**
 * Cross-project scan for live Project Runs — Global Batch start fail-closed gate.
 */
final class LegacyActiveGenerationRunScanner
{
    public function __construct(
        private readonly ContentProjectActiveGenerationRunDetector $detector,
    ) {}

    /**
     * @return list<array{run_id: int, project_id: int, status: string, mode: string}>
     */
    public function findActiveRuns(?int $excludeRunId = null): array
    {
        $rows = SeoProjectRun::query()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'project_id', 'status', 'mode', 'total', 'items', 'settings', 'started_at', 'updated_at', 'finished_at']);

        $active = [];
        foreach ($rows as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            if ($excludeRunId !== null && (int) $run->id === $excludeRunId) {
                continue;
            }

            $settings = is_array($run->settings) ? $run->settings : [];
            $taskIds = $settings['task_ids'] ?? [];
            $classified = $this->detector->classifyRunSnapshot([
                'status' => (string) $run->status,
                'mode' => (string) ($run->mode ?? SeoProjectRun::MODE_FULL),
                'total' => (int) ($run->total ?? 0),
                'task_ids' => is_array($taskIds) ? $taskIds : [],
                'items' => $run->items,
                'started_at' => $run->started_at,
                'updated_at' => $run->updated_at,
                'finished_at' => $run->finished_at,
            ]);

            if (! ($classified['is_live'] ?? false)) {
                continue;
            }

            $active[] = [
                'run_id' => (int) $run->id,
                'project_id' => (int) $run->project_id,
                'status' => (string) $run->status,
                'mode' => (string) ($run->mode ?? SeoProjectRun::MODE_FULL),
            ];
        }

        return $active;
    }

    public function hasActiveRuns(?int $excludeRunId = null): bool
    {
        return $this->findActiveRuns($excludeRunId) !== [];
    }
}
