<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;

/**
 * Doctor/repair cho false-active / finished_at lệch.
 * Dry-run mặc định; --apply chỉ sửa case chắc chắn.
 */
final class ContentProjectActiveExecutionRepairService
{
    public function __construct(
        private readonly ContentProjectExecutionFinalizer $finalizer,
        private readonly SeoProjectRunItemService $runItemService,
    ) {}

    /**
     * @return array{
     *     false_active_terminal: list<array<string, mixed>>,
     *     orphan_running: list<array<string, mixed>>,
     *     inconsistent_finished_at: list<array<string, mixed>>,
     *     inconsistent_active_flag: list<array<string, mixed>>,
     *     lock_without_active: list<array<string, mixed>>,
     *     active_without_lock: list<array<string, mixed>>,
     *     repaired: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public function inspect(
        ?int $runId = null,
        ?int $articleId = null,
        bool $apply = false,
    ): array {
        $falseActiveTerminal = [];
        $orphanRunning = [];
        $inconsistentFinishedAt = [];
        $inconsistentActiveFlag = [];
        $lockWithoutActive = [];
        $activeWithoutLock = [];
        $repaired = [];

        $query = SeoProjectRunItem::query()->where('action', 'like', 'step:%');
        if ($runId !== null && $runId > 0) {
            $query->where('run_id', $runId);
        }
        if ($articleId !== null && $articleId > 0) {
            $query->where('article_id', $articleId);
        }

        foreach ($query->orderBy('id')->cursor() as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }

            $status = (string) $item->status;
            $row = $this->rowSnapshot($item);

            // Safe: terminal + finished_at null.
            if (ContentProjectExecutionStatus::isTerminal($status) && $item->finished_at === null) {
                $inconsistentFinishedAt[] = $row;
                if ($apply) {
                    $before = $row;
                    $this->finalizer->finalize(
                        $item,
                        $status,
                        'Repair: terminal status missing finished_at',
                        syncMirror: true,
                    );
                    $item->refresh();
                    $after = $this->rowSnapshot($item);
                    $repaired[] = [
                        'reason' => 'terminal_missing_finished_at',
                        'before' => $before,
                        'after' => $after,
                    ];
                    RuntimeLogger::info('content_project.execution_repaired', [
                        'reason' => 'terminal_missing_finished_at',
                        'before' => $before,
                        'after' => $after,
                    ]);
                }
            }

            // Safe: status vẫn active nhưng finished_at đã set → không còn active thật.
            if (ContentProjectExecutionStatus::isActive($status) && $item->finished_at !== null) {
                $falseActiveTerminal[] = $row;
                if ($apply) {
                    $before = $row;
                    SeoProjectRunItem::query()->whereKey((int) $item->id)->update([
                        'status' => SeoProjectRunItemStatus::Failed->value,
                        'message' => 'Repair: cleared false-active (finished_at was set).',
                        'error_message' => 'false_active_with_finished_at',
                    ]);
                    $item->refresh();
                    $after = $this->rowSnapshot($item);
                    $repaired[] = [
                        'reason' => 'active_with_finished_at',
                        'before' => $before,
                        'after' => $after,
                    ];
                    RuntimeLogger::info('content_project.execution_repaired', [
                        'reason' => 'active_with_finished_at',
                        'before' => $before,
                        'after' => $after,
                    ]);
                }
            } elseif (
                ContentProjectExecutionStatus::isActive($status)
                && $item->finished_at === null
            ) {
                $stale = $this->isOfficiallyStale($item);
                $upstreamBlocked = $this->isLeftoverAfterUpstreamTerminal($item);
                $orphanRunning[] = array_merge($row, [
                    'officially_stale' => $stale,
                    'upstream_blocked_leftover' => $upstreamBlocked,
                ]);

                // Apply: staleMinutes chính thức HOẶC leftover pending sau sibling terminal (bug multi-node).
                if ($apply && ($stale || $upstreamBlocked)) {
                    $before = $row;
                    $reason = $upstreamBlocked
                        ? 'Repair: leftover pending after upstream terminal (false active).'
                        : 'Repair: stale active execution abandoned (official staleMinutes).';
                    $this->finalizer->finalize(
                        $item,
                        $upstreamBlocked
                            ? SeoProjectRunItemStatus::Skipped->value
                            : SeoProjectRunItemStatus::Failed->value,
                        $reason,
                        syncMirror: true,
                    );
                    $item->refresh();
                    $after = $this->rowSnapshot($item);
                    $repaired[] = [
                        'reason' => $upstreamBlocked ? 'upstream_blocked_leftover' : 'stale_active_abandoned',
                        'before' => $before,
                        'after' => $after,
                    ];
                    RuntimeLogger::info('content_project.execution_repaired', [
                        'reason' => $upstreamBlocked ? 'upstream_blocked_leftover' : 'stale_active_abandoned',
                        'before' => $before,
                        'after' => $after,
                    ]);
                }
            }
        }

        $runQuery = SeoProjectRun::query();
        if ($runId !== null && $runId > 0) {
            $runQuery->whereKey($runId);
        }

        foreach ($runQuery->orderBy('id')->cursor() as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }

            $settings = is_array($run->settings ?? null) ? $run->settings : [];
            $engine = is_array($settings['engine'] ?? null) ? $settings['engine'] : [];
            $activeDispatch = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            $hasLock = $activeDispatch !== null;

            $activeCount = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->where('action', 'like', 'step:%')
                ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
                ->whereNull('finished_at')
                ->count();

            if ($hasLock && $activeCount === 0) {
                $lockWithoutActive[] = [
                    'run_id' => (int) $run->id,
                    'run_item_id' => (int) ($activeDispatch['run_item_id'] ?? 0),
                    'token' => (string) ($activeDispatch['token'] ?? ''),
                ];
            }

            if (! $hasLock && $activeCount > 0) {
                $activeWithoutLock[] = [
                    'run_id' => (int) $run->id,
                    'active_count' => $activeCount,
                ];
            }
        }

        return [
            'false_active_terminal' => $falseActiveTerminal,
            'orphan_running' => $orphanRunning,
            'inconsistent_finished_at' => $inconsistentFinishedAt,
            'inconsistent_active_flag' => $inconsistentActiveFlag,
            'lock_without_active' => $lockWithoutActive,
            'active_without_lock' => $activeWithoutLock,
            'repaired' => $repaired,
            'counts' => [
                'false_active_terminal' => count($falseActiveTerminal),
                'orphan_running' => count($orphanRunning),
                'inconsistent_finished_at' => count($inconsistentFinishedAt),
                'inconsistent_active_flag' => count($inconsistentActiveFlag),
                'lock_without_active' => count($lockWithoutActive),
                'active_without_lock' => count($activeWithoutLock),
                'repaired' => count($repaired),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowSnapshot(SeoProjectRunItem $item): array
    {
        $snapshot = is_array($item->input_snapshot) ? $item->input_snapshot : [];

        return [
            'table' => 'seo_project_run_items',
            'id' => (int) $item->id,
            'run_id' => (int) $item->run_id,
            'task_id' => (int) ($item->task_id ?? 0) ?: null,
            'article_id' => (int) ($item->article_id ?? 0) ?: null,
            'action' => (string) $item->action,
            'node_id' => isset($snapshot['node_id']) ? (string) $snapshot['node_id'] : null,
            'status' => (string) $item->status,
            'active_flag' => null,
            'started_at' => $item->started_at?->toDateTimeString(),
            'finished_at' => $item->finished_at?->toDateTimeString(),
            'created_at' => $item->created_at?->toDateTimeString(),
            'updated_at' => $item->updated_at?->toDateTimeString(),
        ];
    }

    private function isOfficiallyStale(SeoProjectRunItem $item): bool
    {
        $status = (string) $item->status;
        if ($status === SeoProjectRunItemStatus::Processing->value) {
            return $this->runItemService->isStale($item);
        }

        if ($status === SeoProjectRunItemStatus::Pending->value) {
            $cutoff = now()->subMinutes($this->runItemService->staleMinutes());
            $reference = $item->updated_at ?? $item->created_at;

            return $reference === null || $reference->lte($cutoff);
        }

        return false;
    }

    /**
     * Leftover pending (chưa claim) trong khi cùng task đã có step terminal khác —
     * pattern multi-node rerun break trước khi finalize leftover.
     */
    private function isLeftoverAfterUpstreamTerminal(SeoProjectRunItem $item): bool
    {
        if ((string) $item->status !== SeoProjectRunItemStatus::Pending->value) {
            return false;
        }
        if ($item->started_at !== null || $item->finished_at !== null) {
            return false;
        }
        if ((int) ($item->task_id ?? 0) <= 0 || (int) $item->run_id <= 0) {
            return false;
        }

        return SeoProjectRunItem::query()
            ->where('run_id', (int) $item->run_id)
            ->where('task_id', (int) $item->task_id)
            ->where('action', 'like', 'step:%')
            ->where('id', '!=', (int) $item->id)
            ->whereNotNull('finished_at')
            ->whereIn('status', [
                SeoProjectRunItemStatus::Failed->value,
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Skipped->value,
            ])
            ->exists();
    }
}
