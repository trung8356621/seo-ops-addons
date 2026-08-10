<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detect + safely repair legacy stale claim/lease markers on non-active rows.
 * Does not touch active non-expired processing. Preserves attempt counters and
 * valid future next_publish_retry_at.
 */
final class PublishingStaleStateRepairer
{
    public function __construct(
        private readonly PublishingActiveProcessing $activeProcessing = new PublishingActiveProcessing,
        private readonly PublishingProcessingMarkerClearer $markerClearer = new PublishingProcessingMarkerClearer,
    ) {}

    /**
     * @return list<array{
     *     item_id: int,
     *     reason: string,
     *     publish_queue_status: string,
     *     repairable: bool,
     *     next_publish_retry_at: ?string,
     *     publish_attempt_count: int,
     *     publish_lease_expires_at: ?string
     * }>
     */
    public function inspect(?int $projectId = null, int $limit = 100): array
    {
        $rows = [];
        foreach ($this->candidateTasks($projectId, $limit) as $task) {
            $reason = $this->activeProcessing->classifyStaleReason($task);
            if ($reason === null) {
                continue;
            }

            $repairable = ! in_array($reason, ['active_real_publisher', 'active_non_expired_processing'], true);
            $rows[] = [
                'item_id' => (int) $task->getKey(),
                'reason' => $reason,
                'publish_queue_status' => (string) ($task->publish_queue_status ?? ''),
                'repairable' => $repairable,
                'next_publish_retry_at' => $task->next_publish_retry_at?->utc()->toIso8601String(),
                'publish_attempt_count' => (int) ($task->publish_attempt_count ?? 0),
                'publish_lease_expires_at' => $task->publish_lease_expires_at?->utc()->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{repaired: int, skipped_active: int, rows: list<array<string, mixed>>}
     */
    public function repair(?int $projectId = null, int $limit = 100, bool $dryRun = true): array
    {
        $inspected = $this->inspect($projectId, $limit);
        $repaired = 0;
        $skippedActive = 0;
        $rows = [];

        foreach ($inspected as $row) {
            if (! $row['repairable']) {
                $skippedActive++;
                $rows[] = array_merge($row, ['action' => 'skip_active']);
                continue;
            }

            $rows[] = array_merge($row, ['action' => $dryRun ? 'would_repair' : 'repaired']);

            if ($dryRun) {
                $repaired++;
                continue;
            }

            $task = SeoProjectTask::query()->with(['project'])->find($row['item_id']);
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $this->repairOne($task, $row['reason']);
            $repaired++;
        }

        return [
            'repaired' => $repaired,
            'skipped_active' => $skippedActive,
            'rows' => $rows,
        ];
    }

    public function repairOne(SeoProjectTask $task, string $reason): void
    {
        $status = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
            ?? ContentProjectPublishQueueStatus::None;

        $payload = $this->markerClearer->clearedAttributes(clearPublishingStartedAt: true);

        // Expired processing → demote to none so due scanner / retry can reclaim.
        // Preserve attempt counters and next_publish_retry_at for retry_wait.
        if ($status === ContentProjectPublishQueueStatus::Processing) {
            $payload['publish_queue_status'] = ContentProjectPublishQueueStatus::None->value;
            // Keep scheduled_publish_at if present so item stays due-selectable.
        }

        if ($payload !== []) {
            SeoProjectTask::query()->whereKey((int) $task->getKey())->update($payload);
            $task->forceFill($payload);
        }

        $this->markerClearer->applySideEffects($task, $reason);

        RuntimeLogger::info('publishing.stale_state_repaired', [
            'task_id' => (int) $task->getKey(),
            'reason' => $reason,
            'publish_queue_status' => (string) ($task->publish_queue_status ?? ''),
            'publish_attempt_count' => (int) ($task->publish_attempt_count ?? 0),
            'next_publish_retry_at' => $task->next_publish_retry_at?->utc()->toIso8601String(),
        ]);
    }

    /**
     * @return Collection<int, SeoProjectTask>
     */
    private function candidateTasks(?int $projectId, int $limit): Collection
    {
        $query = SeoProjectTask::query()
            ->active()
            ->whereHas('project', static function ($q): void {
                $q->whereNull('archived_at');
            })
            ->with(['project'])
            ->orderBy('id')
            ->limit(max(1, $limit));

        if ($projectId !== null && $projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $query->where(function ($q): void {
            $q->where('publish_queue_status', ContentProjectPublishQueueStatus::Processing->value);

            if ($this->hasColumn('publish_lease_expires_at')) {
                $q->orWhere(function ($stale): void {
                    $stale->whereIn('publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                        ContentProjectPublishQueueStatus::None->value,
                    ])->whereNotNull('publish_lease_expires_at');
                });
            }
            if ($this->hasColumn('publish_claim_token')) {
                $q->orWhere(function ($claim): void {
                    $claim->whereIn('publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                        ContentProjectPublishQueueStatus::None->value,
                    ])->whereNotNull('publish_claim_token')
                        ->where('publish_claim_token', '!=', '');
                });
            }
            if ($this->hasColumn('publishing_started_at')) {
                $q->orWhere(function ($started): void {
                    $started->whereIn('publish_queue_status', [
                        ContentProjectPublishQueueStatus::Waiting->value,
                        ContentProjectPublishQueueStatus::Retrying->value,
                        ContentProjectPublishQueueStatus::None->value,
                    ])->whereNotNull('publishing_started_at');
                });
            }
        });

        try {
            return $query->get();
        } catch (Throwable) {
            return collect();
        }
    }

    private function hasColumn(string $column): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', $column);
        } catch (Throwable) {
            return false;
        }
    }
}
