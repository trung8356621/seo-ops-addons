<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;

/**
 * Idempotent terminal finalizer cho seo_project_run_items (step executions).
 */
final class ContentProjectExecutionFinalizer
{
    public function __construct(
        private readonly SeoProjectRunItemService $runItemService,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function finalize(
        SeoProjectRunItem|int $item,
        string $terminalStatus,
        string $reason,
        array $extra = [],
        bool $syncMirror = true,
    ): SeoProjectRunItem {
        $runItem = $item instanceof SeoProjectRunItem
            ? $item
            : SeoProjectRunItem::query()->find((int) $item);

        if (! $runItem instanceof SeoProjectRunItem) {
            throw new \InvalidArgumentException('Run item not found for finalize.');
        }

        $canonical = $this->canonicalTerminalStatus($terminalStatus);
        $before = [
            'id' => (int) $runItem->id,
            'status' => (string) $runItem->status,
            'finished_at' => $runItem->finished_at?->toDateTimeString(),
            'message' => (string) ($runItem->message ?? ''),
            'error_message' => (string) ($runItem->error_message ?? ''),
        ];

        // Idempotent: đã terminal đúng + finished_at → no-op (trừ fill thiếu finished_at).
        if (
            ContentProjectExecutionStatus::isTerminal((string) $runItem->status)
            && $runItem->finished_at !== null
            && ContentProjectExecutionStatus::normalize((string) $runItem->status)
                === ContentProjectExecutionStatus::normalize($canonical)
        ) {
            return $runItem;
        }

        $payload = array_merge([
            'status' => $canonical,
            'finished_at' => $runItem->finished_at ?? Carbon::now(),
        ], $extra);

        if (! array_key_exists('message', $payload) && $reason !== '') {
            $payload['message'] = $reason;
        }
        if (
            ! array_key_exists('error_message', $payload)
            && in_array($canonical, [
                SeoProjectRunItemStatus::Failed->value,
                SeoProjectRunItemStatus::Skipped->value,
            ], true)
            && $reason !== ''
        ) {
            $payload['error_message'] = $reason;
        }

        // Chỉ đè từ active hoặc terminal thiếu finished_at; không đè Success bằng Failed.
        $query = SeoProjectRunItem::query()->whereKey((int) $runItem->id);
        if (ContentProjectExecutionStatus::isActive((string) $runItem->status)) {
            $query->whereIn('status', ContentProjectExecutionStatus::activeStatuses());
        } elseif ($runItem->finished_at === null) {
            $query->whereNull('finished_at');
        } else {
            // Đã terminal + finished — giữ nguyên.
            return $runItem;
        }

        $affected = $query->update($payload);
        $runItem->refresh();

        if ($affected > 0) {
            RuntimeLogger::info('content_project.execution_repaired', [
                'reason' => $reason,
                'before' => $before,
                'after' => [
                    'id' => (int) $runItem->id,
                    'status' => (string) $runItem->status,
                    'finished_at' => $runItem->finished_at?->toDateTimeString(),
                    'message' => (string) ($runItem->message ?? ''),
                    'error_message' => (string) ($runItem->error_message ?? ''),
                ],
            ]);

            if ($syncMirror) {
                $run = SeoProjectRun::query()->find((int) $runItem->run_id);
                if ($run instanceof SeoProjectRun) {
                    $this->runItemService->syncMirrorAndCounters($run, false);
                }
            }
        }

        return $runItem;
    }

    /**
     * Finalize nhiều item (vd. leftover pending sau upstream fail).
     *
     * @param  list<int|SeoProjectRunItem>  $items
     * @return int số item touched
     */
    public function finalizeMany(
        array $items,
        string $terminalStatus,
        string $reason,
        bool $syncMirror = true,
    ): int {
        $count = 0;
        $touchedRuns = [];

        foreach ($items as $item) {
            $runItem = $this->finalize($item, $terminalStatus, $reason, syncMirror: false);
            if (ContentProjectExecutionStatus::isTerminal((string) $runItem->status)
                && $runItem->finished_at !== null
            ) {
                $count++;
                $touchedRuns[(int) $runItem->run_id] = true;
            }
        }

        if ($syncMirror) {
            foreach (array_keys($touchedRuns) as $runId) {
                $run = SeoProjectRun::query()->find((int) $runId);
                if ($run instanceof SeoProjectRun) {
                    $this->runItemService->syncMirrorAndCounters($run, false);
                }
            }
        }

        return $count;
    }

    private function canonicalTerminalStatus(string $status): string
    {
        $normalized = ContentProjectExecutionStatus::normalize($status);

        return match ($normalized) {
            SeoProjectRunItemStatus::Success->value => SeoProjectRunItemStatus::Success->value,
            SeoProjectRunItemStatus::Skipped->value, 'blocked' => SeoProjectRunItemStatus::Skipped->value,
            'cancelled', 'timeout', 'ignored_stale' => SeoProjectRunItemStatus::Failed->value,
            default => SeoProjectRunItemStatus::Failed->value,
        };
    }
}
