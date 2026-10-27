<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use Carbon\Carbon;

/**
 * Lightweight Global Batch progress — playlist semantics aligned with Content Project Progress.
 */
final class GlobalMonthlyBatchProgressReadModel
{
    /**
     * @return array<string, mixed>
     */
    public function forBatch(SeoContentGlobalBatch $batch): array
    {
        $items = SeoContentGlobalBatchItem::query()
            ->where('batch_id', (int) $batch->id)
            ->orderBy('position')
            ->get([
                'id', 'position', 'project_id', 'task_id', 'item_type', 'status',
                'skip_reason', 'project_run_id', 'started_at', 'finished_at', 'error_message',
            ]);

        $projectNames = SeoProject::query()
            ->whereIn('id', $items->pluck('project_id')->unique()->filter()->all())
            ->pluck('name', 'id')
            ->all();

        $taskTitles = SeoProjectTask::query()
            ->whereIn('id', $items->pluck('task_id')->unique()->filter()->all())
            ->get(['id', 'post_title', 'keyword'])
            ->mapWithKeys(static function (SeoProjectTask $task): array {
                $title = trim((string) ($task->post_title ?? ''));
                if ($title === '') {
                    $title = trim((string) ($task->keyword ?? ''));
                }

                return [(int) $task->id => $title !== '' ? $title : 'Task #'.$task->id];
            })
            ->all();

        $playlist = [];
        $nowPlaying = null;
        $processing = 0;
        $lastCompletedSeconds = null;

        foreach ($items as $item) {
            $status = (string) $item->status;
            $bucket = $this->bucket($status);
            if ($bucket === 'processing') {
                $processing++;
            }

            $durationSeconds = $this->durationSeconds($item);
            if ($bucket === 'completed' && $durationSeconds !== null) {
                $lastCompletedSeconds = $durationSeconds;
            }

            $row = [
                'position' => (int) $item->position,
                'project_id' => (int) $item->project_id,
                'project_name' => (string) ($projectNames[(int) $item->project_id] ?? ('Project #'.$item->project_id)),
                'task_id' => (int) $item->task_id,
                'title' => (string) ($taskTitles[(int) $item->task_id] ?? ('Task #'.$item->task_id)),
                'status' => $status,
                'status_bucket' => $bucket,
                'status_label' => $this->statusLabel($bucket, $status),
                'skip_reason' => $item->skip_reason,
                'duration_seconds' => $durationSeconds,
                'duration_label' => $this->formatDuration($durationSeconds),
                'is_current' => $bucket === 'processing',
                'project_url' => null,
            ];

            try {
                $row['project_url'] = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl(
                    'view',
                    ['record' => (int) $item->project_id],
                );
            } catch (\Throwable) {
                $row['project_url'] = null;
            }

            $playlist[] = $row;
            if ($row['is_current']) {
                $elapsed = null;
                if ($item->started_at !== null) {
                    $elapsed = max(0, (int) now()->diffInSeconds(Carbon::parse($item->started_at)));
                }
                $nowPlaying = array_merge($row, [
                    'elapsed_seconds' => $elapsed,
                    'elapsed_label' => $this->formatDuration($elapsed),
                    'step_label' => $this->stageLabel($batch),
                    'total' => max(0, (int) $batch->total_items),
                ]);
            }
        }

        $snapshotTotal = max(0, (int) $batch->total_items);
        $succeeded = (int) $batch->succeeded;
        $failed = (int) $batch->failed;
        $skipped = (int) $batch->skipped;
        $isTerminal = ! $batch->isActive();
        $progressMath = ContentProjectProgressPercent::compute(
            $snapshotTotal,
            $succeeded,
            $failed,
            $skipped,
            $isTerminal,
        );

        // Completed batches must not keep a stale Now Playing highlight.
        if ($isTerminal) {
            $nowPlaying = null;
            foreach ($playlist as &$row) {
                $row['is_current'] = false;
            }
            unset($row);
        }

        $currentProjectId = (int) ($batch->current_project_id ?? 0);
        $policy = AiCostPolicy::tryFromMixed($batch->ai_cost_policy);

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'status' => (string) $batch->status,
                'status_label' => $this->batchStatusLabel((string) $batch->status),
                'planning_month' => ContentProjectMonthContext::normalize($batch->planning_month),
                'planning_month_label' => ContentProjectMonthContext::display($batch->planning_month),
                'ai_cost_policy' => $policy->value,
                'ai_mode_label' => $policy->isFreeOnly() ? 'Free-Split' : 'Paid',
                'started_at' => $batch->started_at?->toIso8601String(),
                'finished_at' => $batch->finished_at?->toIso8601String(),
                'is_active' => $batch->isActive(),
                'should_poll' => $batch->isActive(),
                'stop_requested' => $batch->stopRequested(),
                'is_terminal' => $isTerminal,
                'elapsed_label' => $this->batchElapsedLabel($batch),
            ],
            'overview' => [
                'total' => $snapshotTotal,
                'snapshot_total' => $progressMath['snapshot_total'],
                'succeeded' => $succeeded,
                'completed' => $succeeded,
                'failed' => $failed,
                'skipped' => $skipped,
                'pending' => max(0, (int) $batch->pending - $processing),
                'processing' => $processing,
                'eligible_total' => $progressMath['eligible_total'],
                'processed' => $progressMath['processed'],
                'percent' => $progressMath['percent'],
                'no_eligible_work' => $progressMath['no_eligible_work'],
                'current_project_id' => $currentProjectId > 0 ? $currentProjectId : null,
                'current_project_name' => $currentProjectId > 0
                    ? (string) ($projectNames[$currentProjectId] ?? ('Project #'.$currentProjectId))
                    : null,
                'current_stage' => $this->stageLabel($batch),
                'last_completed_duration_seconds' => $lastCompletedSeconds,
                'last_completed_duration_label' => $this->formatDuration($lastCompletedSeconds),
            ],
            'now_playing' => $nowPlaying,
            'playlist' => $playlist,
        ];
    }

    private function bucket(string $status): string
    {
        return match ($status) {
            SeoContentGlobalBatchItem::STATUS_SUCCEEDED => 'completed',
            SeoContentGlobalBatchItem::STATUS_RUNNING => 'processing',
            SeoContentGlobalBatchItem::STATUS_FAILED => 'failed',
            SeoContentGlobalBatchItem::STATUS_SKIPPED => 'skipped',
            SeoContentGlobalBatchItem::STATUS_CANCELLED => 'cancelled',
            default => 'pending',
        };
    }

    private function statusLabel(string $bucket, string $status): string
    {
        return match ($bucket) {
            'completed' => 'Thành công',
            'processing' => 'Đang chạy',
            'failed' => 'Lỗi',
            'skipped' => 'Bỏ qua',
            'cancelled' => 'Đã dừng',
            default => $status !== '' ? $status : 'Chờ',
        };
    }

    private function batchStatusLabel(string $status): string
    {
        return match ($status) {
            SeoContentGlobalBatch::STATUS_RUNNING => 'Đang chạy',
            SeoContentGlobalBatch::STATUS_STOPPING => 'Đang dừng',
            SeoContentGlobalBatch::STATUS_COMPLETED => 'Hoàn tất',
            SeoContentGlobalBatch::STATUS_FAILED => 'Thất bại',
            SeoContentGlobalBatch::STATUS_CANCELLED => 'Đã hủy',
            default => 'Chờ',
        };
    }

    private function stageLabel(SeoContentGlobalBatch $batch): string
    {
        $runId = (int) ($batch->current_project_run_id ?? 0);
        if ($runId <= 0) {
            return '—';
        }

        $run = SeoProjectRun::query()->find($runId);
        if (! $run instanceof SeoProjectRun) {
            return '—';
        }

        return match ((string) $run->status) {
            SeoProjectRun::STATUS_RUNNING => 'Generating',
            SeoProjectRun::STATUS_STOPPING => 'Stopping',
            SeoProjectRun::STATUS_COMPLETED => 'Completed',
            SeoProjectRun::STATUS_FAILED => 'Failed',
            SeoProjectRun::STATUS_CANCELLED => 'Cancelled',
            default => (string) $run->status,
        };
    }

    private function durationSeconds(SeoContentGlobalBatchItem $item): ?int
    {
        if ($item->started_at === null || $item->finished_at === null) {
            return null;
        }

        return max(0, (int) Carbon::parse($item->finished_at)->diffInSeconds(Carbon::parse($item->started_at)));
    }

    private function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d', $m, $s);
    }

    private function batchElapsedLabel(SeoContentGlobalBatch $batch): ?string
    {
        if ($batch->started_at === null) {
            return null;
        }
        $end = $batch->finished_at ?? now();

        return $this->formatDuration(max(0, (int) Carbon::parse($end)->diffInSeconds(Carbon::parse($batch->started_at))));
    }
}
