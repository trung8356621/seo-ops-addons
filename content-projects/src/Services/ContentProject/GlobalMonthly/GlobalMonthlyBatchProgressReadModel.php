<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use App\Models\User;
use Carbon\Carbon;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPresentation;

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
        // Base columns only — Auto Cost extras are optional until migration is applied.
        $itemColumns = [
            'id', 'position', 'project_id', 'task_id', 'item_type', 'status',
            'skip_reason', 'project_run_id', 'run_item_id', 'started_at', 'finished_at', 'error_message',
        ];
        foreach ([
            'current_strategy', 'retry_count', 'next_retry_at', 'succeeded_via', 'last_error_category',
        ] as $optionalCol) {
            try {
                if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')
                    ->hasColumn('seo_content_global_batch_items', $optionalCol)
                ) {
                    $itemColumns[] = $optionalCol;
                }
            } catch (\Throwable) {
                // Schema probe failed — keep base columns so Progress remains readable.
            }
        }

        $items = SeoContentGlobalBatchItem::query()
            ->where('batch_id', (int) $batch->id)
            ->orderBy('position')
            ->get($itemColumns);

        $projects = SeoProject::query()
            ->whereIn('id', $items->pluck('project_id')->unique()->filter()->all())
            ->get(['id', 'name', 'user_id']);

        $projectNames = $projects->pluck('name', 'id')->all();
        $userIds = $projects->pluck('user_id')->filter()->unique()->all();
        $userNames = [];
        if ($userIds !== []) {
            $userNames = User::query()
                ->whereIn('id', $userIds)
                ->pluck('name', 'id')
                ->all();
        }

        $projectOwners = [];
        foreach ($projects as $proj) {
            $owner = trim((string) ($userNames[(int) $proj->user_id] ?? ''));
            if ($owner !== '') {
                $projectOwners[(int) $proj->id] = $owner;
            }
        }

        $taskTitles = SeoProjectTask::query()
            ->whereIn('id', $items->pluck('task_id')->unique()->filter()->all())
            ->get(['id', 'title', 'keyword'])
            ->mapWithKeys(static function (SeoProjectTask $task): array {
                $title = trim((string) ($task->title ?? ''));
                if ($title === '') {
                    $title = trim((string) ($task->keyword ?? ''));
                }

                return [(int) $task->id => $title !== '' ? $title : 'Task #'.$task->id];
            })
            ->all();

        // Join child run items without N+1 queries
        $runIds = $items->pluck('project_run_id')->filter()->unique()->all();
        if (! empty($batch->current_project_run_id)) {
            $runIds[] = (int) $batch->current_project_run_id;
        }
        $runIds = array_values(array_unique(array_filter($runIds)));

        $runItemsByKey = [];
        if ($runIds !== []) {
            $runItems = SeoProjectRunItem::query()
                ->whereIn('run_id', $runIds)
                ->orderBy('id')
                ->get(['id', 'run_id', 'task_id', 'status', 'started_at', 'finished_at', 'error_message']);

            foreach ($runItems as $ri) {
                $key = ((int) $ri->run_id).':'.((int) $ri->task_id);
                $runItemsByKey[$key] = $ri;
            }
        }

        $playlist = [];
        $nowPlaying = null;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $processing = 0;
        $pending = 0;
        $waitingRetry = 0;
        $freeSucceeded = 0;
        $paidFallbackSucceeded = 0;
        $nextRetryAt = null;
        $lastCompletedSeconds = null;

        foreach ($items as $item) {
            $runId = (int) ($item->project_run_id ?? 0);
            $childRunItem = $runId > 0 ? ($runItemsByKey[$runId.':'.((int) $item->task_id)] ?? null) : null;

            $status = (string) $item->status;
            $startedAt = $item->started_at;
            $finishedAt = $item->finished_at;

            // Overlay active/recent child run item state if batch item is not yet finalized in batch_items
            if ($childRunItem !== null && ! $item->isTerminal()) {
                $childStatus = SeoProjectRunItemStatus::fromLegacy(
                    $childRunItem->status !== null ? (string) $childRunItem->status : null
                );
                $status = match ($childStatus) {
                    SeoProjectRunItemStatus::Success => SeoContentGlobalBatchItem::STATUS_SUCCEEDED,
                    SeoProjectRunItemStatus::Failed => SeoContentGlobalBatchItem::STATUS_FAILED,
                    SeoProjectRunItemStatus::Skipped => SeoContentGlobalBatchItem::STATUS_SKIPPED,
                    SeoProjectRunItemStatus::Processing => SeoContentGlobalBatchItem::STATUS_RUNNING,
                    default => SeoContentGlobalBatchItem::STATUS_PENDING,
                };
                if ($childRunItem->started_at !== null) {
                    $startedAt = $childRunItem->started_at;
                }
                if ($childRunItem->finished_at !== null) {
                    $finishedAt = $childRunItem->finished_at;
                }
            }

            $bucket = $this->bucket($status);
            if ($bucket === 'completed') {
                $succeeded++;
                $via = (string) ($item->succeeded_via ?? '');
                if ($via === 'free') {
                    $freeSucceeded++;
                } elseif ($via === 'paid') {
                    $paidFallbackSucceeded++;
                }
            } elseif ($bucket === 'processing') {
                $processing++;
            } elseif ($bucket === 'failed') {
                $failed++;
            } elseif ($bucket === 'waiting_retry') {
                $waitingRetry++;
                if ($item->next_retry_at !== null) {
                    $ts = Carbon::parse($item->next_retry_at)->getTimestamp();
                    if ($nextRetryAt === null || $ts < $nextRetryAt) {
                        $nextRetryAt = $ts;
                    }
                }
            } elseif ($bucket === 'skipped' || $bucket === 'cancelled') {
                $skipped++;
            } else {
                $pending++;
            }

            $isCurrent = $bucket === 'processing';
            $durationSeconds = null;
            $durationLabel = '—';
            $startedAtTs = null;

            if ($startedAt !== null) {
                try {
                    $startedAtTs = Carbon::parse($startedAt)->getTimestamp();
                } catch (\Throwable) {
                    $startedAtTs = null;
                }
            }

            if ($bucket === 'completed' || $bucket === 'failed') {
                $durationSeconds = $this->durationSeconds($startedAt, $finishedAt);
                if ($durationSeconds !== null && $durationSeconds > 0) {
                    $durationLabel = $this->formatDuration($durationSeconds);
                    if ($bucket === 'completed') {
                        $lastCompletedSeconds = $durationSeconds;
                    }
                } else {
                    $durationSeconds = null;
                    $durationLabel = '—';
                }
            } elseif ($isCurrent && $startedAtTs !== null) {
                $elapsed = max(0, now()->getTimestamp() - $startedAtTs);
                $durationLabel = $this->formatDuration($elapsed);
            }

            $row = [
                'position' => (int) $item->position,
                'project_id' => (int) $item->project_id,
                'project_name' => (string) ($projectNames[(int) $item->project_id] ?? ('Project #'.$item->project_id)),
                'owner_name' => $projectOwners[(int) $item->project_id] ?? null,
                'task_id' => (int) $item->task_id,
                'title' => (string) ($taskTitles[(int) $item->task_id] ?? ('Task #'.$item->task_id)),
                'status' => $status,
                'status_bucket' => $bucket,
                'status_label' => $this->statusLabel($bucket, $status),
                'skip_reason' => $item->skip_reason,
                'strategy' => $item->current_strategy !== null ? (string) $item->current_strategy : null,
                'succeeded_via' => $item->succeeded_via !== null ? (string) $item->succeeded_via : null,
                'retry_count' => (int) ($item->retry_count ?? 0),
                'next_retry_at' => $item->next_retry_at?->toIso8601String(),
                'last_error_category' => $item->last_error_category !== null ? (string) $item->last_error_category : null,
                'started_at_ts' => $startedAtTs,
                'duration_seconds' => $durationSeconds,
                'duration_label' => $durationLabel,
                'is_current' => $isCurrent,
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
            if ($isCurrent && $nowPlaying === null) {
                $elapsed = $startedAtTs !== null ? max(0, now()->getTimestamp() - $startedAtTs) : 0;
                $nowPlaying = array_merge($row, [
                    'started_at_ts' => $startedAtTs,
                    'elapsed_seconds' => $elapsed,
                    'elapsed_label' => $this->formatDuration($elapsed),
                    'step_label' => $this->stageLabel($batch),
                    'total' => max(0, (int) $batch->total_items),
                ]);
            }
        }

        $snapshotTotal = max(count($items), (int) $batch->total_items);
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

        $presentation = ContentProjectProgressPresentation::present(
            hasRun: true,
            runStatus: (string) $batch->status,
            snapshotTotal: $snapshotTotal,
            succeeded: $succeeded,
            failed: $failed,
            skipped: $skipped,
            pending: $pending,
            processing: $processing,
            cancelled: 0,
            isTerminal: $isTerminal,
        );

        $currentProjectId = (int) ($batch->current_project_id ?? 0);
        if ($nowPlaying !== null && ! empty($nowPlaying['project_id'])) {
            $currentProjectId = (int) $nowPlaying['project_id'];
        }

        $settings = is_array($batch->settings) ? $batch->settings : [];
        $isAutoCost = GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy);
        $policyToken = GlobalMonthlyAiPolicy::normalizeBatchPolicy($batch->ai_cost_policy);
        $aiModeLabel = $isAutoCost
            ? 'Auto Cost'
            : (AiCostPolicy::tryFromMixed($policyToken)->isFreeOnly() ? 'Free-Split' : 'Paid');

        $currentStrategy = (string) ($settings['current_strategy'] ?? '');
        if ($currentStrategy === '' && $nowPlaying !== null) {
            $currentStrategy = (string) ($nowPlaying['strategy'] ?? '');
        }

        // Prefer persisted counters when present (survives playlist overlay quirks).
        if ($isAutoCost) {
            $freeSucceeded = max($freeSucceeded, (int) ($settings['free_succeeded'] ?? 0));
            $paidFallbackSucceeded = max($paidFallbackSucceeded, (int) ($settings['paid_fallback_succeeded'] ?? 0));
        }

        $stopReason = null;
        if ((string) $batch->status === SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE) {
            $stopReason = 'AI unavailable: Free exhausted và Paid exhausted. Resume khi AI khả dụng — bài succeeded không bị generate lại.';
        } elseif (! empty($settings['failure_message'])) {
            $stopReason = (string) $settings['failure_message'];
        } elseif (! empty($settings['stop_reason'])) {
            $stopReason = (string) $settings['stop_reason'];
        }

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'status' => (string) $batch->status,
                'status_label' => $this->batchStatusLabel((string) $batch->status),
                'planning_month' => ContentProjectMonthContext::normalize($batch->planning_month),
                'planning_month_label' => ContentProjectMonthContext::display($batch->planning_month),
                'ai_cost_policy' => $policyToken,
                'ai_mode_label' => $aiModeLabel,
                'is_auto_cost' => $isAutoCost,
                'started_at' => $batch->started_at?->toIso8601String(),
                'finished_at' => $batch->finished_at?->toIso8601String(),
                'is_active' => $batch->isActive(),
                'should_poll' => $batch->isActive() || $waitingRetry > 0,
                'stop_requested' => $batch->stopRequested(),
                'is_terminal' => $isTerminal,
                'elapsed_label' => $this->batchElapsedLabel($batch),
                'stop_reason' => $stopReason,
            ],
            'overview' => [
                'total' => $snapshotTotal,
                'snapshot_total' => $progressMath['snapshot_total'],
                'succeeded' => $succeeded,
                'completed' => $succeeded,
                'failed' => $failed,
                'skipped' => $skipped,
                'pending' => $pending,
                'processing' => $processing,
                'waiting_retry' => $waitingRetry,
                'free_succeeded' => $freeSucceeded,
                'paid_fallback_succeeded' => $paidFallbackSucceeded,
                'failed_attempt_count' => (int) ($settings['failed_attempt_count'] ?? 0),
                'current_strategy' => $currentStrategy !== '' ? $currentStrategy : null,
                'current_strategy_label' => match ($currentStrategy) {
                    GlobalAutoGenerationPolicy::STRATEGY_FREE => 'Free',
                    GlobalAutoGenerationPolicy::STRATEGY_PAID => 'Paid',
                    default => $currentStrategy !== '' ? $currentStrategy : null,
                },
                'retry_round' => (int) ($settings['retry_round'] ?? 0),
                'next_retry_at' => $nextRetryAt !== null
                    ? Carbon::createFromTimestamp($nextRetryAt)->toIso8601String()
                    : null,
                'next_retry_label' => $nextRetryAt !== null
                    ? Carbon::createFromTimestamp($nextRetryAt)->diffForHumans()
                    : null,
                'free_exhausted' => (bool) ($settings['free_exhausted'] ?? false),
                'paid_exhausted' => (bool) ($settings['paid_exhausted'] ?? false),
                'paid_cost' => $settings['paid_cost'] ?? null,
                'eligible_total' => $progressMath['eligible_total'],
                'processed' => $progressMath['processed'],
                'percent' => $progressMath['percent'],
                'raw_percent' => $progressMath['raw_percent'],
                'no_eligible_work' => $progressMath['no_eligible_work'],
                'current_project_id' => $currentProjectId > 0 ? $currentProjectId : null,
                'current_project_name' => $currentProjectId > 0
                    ? (string) ($projectNames[$currentProjectId] ?? ('Project #'.$currentProjectId))
                    : null,
                'current_project_owner' => $currentProjectId > 0
                    ? ($projectOwners[$currentProjectId] ?? null)
                    : null,
                'current_stage' => $this->stageLabel($batch, $currentStrategy),
                'last_completed_duration_seconds' => $lastCompletedSeconds,
                'last_completed_duration_label' => $this->formatDuration($lastCompletedSeconds),
                'presentation' => $presentation,
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
            SeoContentGlobalBatchItem::STATUS_WAITING_RETRY => 'waiting_retry',
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
            'failed' => 'Lỗi vĩnh viễn',
            'waiting_retry' => 'Chờ Auto Rerun',
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
            SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE => 'Dừng — AI unavailable',
            default => 'Chờ',
        };
    }

    private function stageLabel(SeoContentGlobalBatch $batch, string $currentStrategy = ''): string
    {
        if ($currentStrategy === GlobalAutoGenerationPolicy::STRATEGY_FREE) {
            return 'Auto Cost · Free';
        }
        if ($currentStrategy === GlobalAutoGenerationPolicy::STRATEGY_PAID) {
            return 'Auto Cost · Paid';
        }

        $runId = (int) ($batch->current_project_run_id ?? 0);
        if ($runId <= 0) {
            $waiting = SeoContentGlobalBatchItem::query()
                ->where('batch_id', (int) $batch->id)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->exists();

            return $waiting ? 'Waiting Auto Rerun' : '—';
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

    private function durationSeconds(mixed $startedAt, mixed $finishedAt): ?int
    {
        if ($startedAt === null || $finishedAt === null) {
            return null;
        }

        try {
            $start = Carbon::parse($startedAt)->getTimestamp();
            $end = Carbon::parse($finishedAt)->getTimestamp();
            $diff = abs($end - $start);

            return $diff > 0 ? $diff : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        if ($seconds === 0) {
            return '0 giây';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return $hours.' giờ '.$minutes.' phút '.$secs.' giây';
        }

        if ($minutes > 0) {
            return $minutes.' phút '.$secs.' giây';
        }

        return $secs.' giây';
    }

    private function batchElapsedLabel(SeoContentGlobalBatch $batch): ?string
    {
        if ($batch->started_at === null) {
            return null;
        }
        $end = $batch->finished_at ?? now();

        try {
            $start = Carbon::parse($batch->started_at)->getTimestamp();
            $finish = Carbon::parse($end)->getTimestamp();
            $diff = max(0, abs($finish - $start));

            return self::formatDuration($diff);
        } catch (\Throwable) {
            return null;
        }
    }
}
