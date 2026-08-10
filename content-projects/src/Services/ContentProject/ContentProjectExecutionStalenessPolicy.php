<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Canonical stale-generation detection — one timeout policy for ops UI + recovery.
 */
final class ContentProjectExecutionStalenessPolicy
{
    public const REASON_STALE_RUNTIME = 'stale_runtime_abandoned';

    public function __construct(
        private readonly SeoProjectRunItemService $runItemService,
    ) {}

    public function staleTimeoutMinutes(): int
    {
        $configured = (int) (function_exists('config')
            ? config('seo-content-ai.content_project.generation_task_stale_minutes', 0)
            : 0);
        if ($configured > 0) {
            return max(1, $configured);
        }

        $runItem = max(1, (int) (function_exists('config')
            ? config('seo-content-ai.content_project.run_item_stale_minutes', 30)
            : 30));
        $heartbeat = max(1, (int) (function_exists('config')
            ? config('seo-content-ai.content_project.heartbeat_stale_minutes', 20)
            : 20));

        return max($runItem, $heartbeat);
    }

    /**
     * Pure snapshot evaluator (unit-test friendly).
     *
     * Writing: orphan runtime with no fresh worker/lock.
     * Non-writing: only stale when abandoned active run-items exist (pending/processing dead).
     *
     * @param  array{
     *     task_status: string,
     *     article_id?: int|null,
     *     article_has_body?: bool,
     *     task_updated_at?: CarbonInterface|string|null,
     *     task_created_at?: CarbonInterface|string|null,
     *     has_fresh_active_execution?: bool,
     *     has_valid_owned_lock?: bool,
     *     last_progress_at?: CarbonInterface|string|null,
     *     stale_active_run_item_count?: int,
     * }  $snapshot
     */
    public function isStaleSnapshot(array $snapshot, ?CarbonInterface $now = null, ?int $timeoutMinutes = null): bool
    {
        if (! empty($snapshot['has_fresh_active_execution'])) {
            return false;
        }

        if (! empty($snapshot['has_valid_owned_lock'])) {
            return false;
        }

        $status = strtolower(trim((string) ($snapshot['task_status'] ?? '')));
        $staleRunCount = (int) ($snapshot['stale_active_run_item_count'] ?? 0);

        // Dead pending/processing run-item while task still Pending/Failed — recoverable.
        if ($status !== SeoProjectTask::STATUS_WRITING) {
            return $staleRunCount > 0;
        }

        $articleId = (int) ($snapshot['article_id'] ?? 0);
        $hasBody = (bool) ($snapshot['article_has_body'] ?? false);
        if ($articleId > 0 && $hasBody) {
            // Output exists — not an orphaned empty generation; leave to other flows.
            return false;
        }

        if ($staleRunCount > 0) {
            return true;
        }

        $now = $now ?? Carbon::now();
        $timeout = max(1, $timeoutMinutes ?? $this->staleTimeoutMinutes());
        $cutoff = $now->copy()->subMinutes($timeout);
        $progress = $this->toCarbon($snapshot['last_progress_at'] ?? null)
            ?? $this->toCarbon($snapshot['task_updated_at'] ?? null)
            ?? $this->toCarbon($snapshot['task_created_at'] ?? null);

        if ($progress === null) {
            return true;
        }

        return $progress->lte($cutoff);
    }

    public function isStaleWritingTask(SeoProjectTask $task): bool
    {
        return $this->evaluateTask($task)['stale'];
    }

    /**
     * @return array{
     *     stale: bool,
     *     reason: string|null,
     *     timeout_minutes: int,
     *     has_fresh_active_execution: bool,
     *     has_valid_owned_lock: bool,
     *     last_progress_at: string|null,
     *     active_run_item_ids: list<int>,
     *     stale_run_item_ids: list<int>,
     * }
     */
    public function evaluateTask(SeoProjectTask $task): array
    {
        $timeout = $this->staleTimeoutMinutes();
        $taskId = (int) $task->getKey();
        $activeItems = SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->where(function ($q): void {
                $q->where('action', 'like', 'step:%')
                    ->orWhereNull('action')
                    ->orWhere('action', '');
            })
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->get();

        $freshIds = [];
        $staleIds = [];
        $lastProgress = $task->updated_at ?? $task->created_at;

        foreach ($activeItems as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }
            $ref = $item->updated_at ?? $item->started_at ?? $item->created_at;
            if ($ref !== null && ($lastProgress === null || $ref->gt($lastProgress))) {
                $lastProgress = $ref;
            }

            if ($this->isFreshActiveRunItem($item)) {
                $freshIds[] = (int) $item->id;
            } else {
                $staleIds[] = (int) $item->id;
            }
        }

        $hasFresh = $freshIds !== [];
        $hasValidLock = $this->hasValidOwnedLockForTask($taskId, $freshIds);

        $article = $task->relationLoaded('article') ? $task->article : null;
        $articleId = (int) ($task->article_id ?? 0);
        $hasBody = false;
        if ($article instanceof SeoArticle) {
            $hasBody = trim((string) ($article->body ?? $article->content ?? '')) !== '';
        }

        $stale = $this->isStaleSnapshot([
            'task_status' => (string) ($task->status ?? ''),
            'article_id' => $articleId,
            'article_has_body' => $hasBody,
            'task_updated_at' => $task->updated_at,
            'task_created_at' => $task->created_at,
            'has_fresh_active_execution' => $hasFresh,
            'has_valid_owned_lock' => $hasValidLock,
            'last_progress_at' => $lastProgress,
            'stale_active_run_item_count' => count($staleIds),
        ]);

        return [
            'stale' => $stale,
            'reason' => $stale ? self::REASON_STALE_RUNTIME : null,
            'timeout_minutes' => $timeout,
            'has_fresh_active_execution' => $hasFresh,
            'has_valid_owned_lock' => $hasValidLock,
            'last_progress_at' => $lastProgress?->toIso8601String(),
            'active_run_item_ids' => array_values(array_merge($freshIds, $staleIds)),
            'stale_run_item_ids' => $staleIds,
        ];
    }

    public function isFreshActiveRunItem(SeoProjectRunItem $item): bool
    {
        if (! ContentProjectExecutionStatus::isActive((string) $item->status) || $item->finished_at !== null) {
            return false;
        }

        $status = ContentProjectExecutionStatus::normalize((string) $item->status);
        if ($status === \Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus::Processing->value) {
            return ! $this->runItemService->isStale($item);
        }

        // Pending: fresh when updated/created within timeout.
        $cutoff = now()->subMinutes($this->staleTimeoutMinutes());
        $reference = $item->updated_at ?? $item->created_at;

        return $reference !== null && $reference->gt($cutoff);
    }

    /**
     * @param  list<int>  $freshRunItemIds
     */
    private function hasValidOwnedLockForTask(int $taskId, array $freshRunItemIds): bool
    {
        if ($freshRunItemIds === [] || $taskId <= 0) {
            return false;
        }

        $freshFlip = array_flip($freshRunItemIds);
        $runs = \Omnichannel\Addons\ContentProjects\Models\SeoProjectRun::query()
            ->whereIn('id', SeoProjectRunItem::query()
                ->whereIn('id', $freshRunItemIds)
                ->select('run_id'))
            ->get();

        foreach ($runs as $run) {
            $settings = is_array($run->settings ?? null) ? $run->settings : [];
            $engine = is_array($settings['engine'] ?? null) ? $settings['engine'] : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            if ($active === null) {
                continue;
            }
            $ownedItemId = (int) ($active['run_item_id'] ?? 0);
            if ($ownedItemId > 0 && isset($freshFlip[$ownedItemId])) {
                return true;
            }
        }

        return false;
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
