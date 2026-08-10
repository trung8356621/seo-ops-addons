<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationActionExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationNodeExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\Models\AutomationActionRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ExecutionCleanupService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly AutomationSettingsService $settings,
    ) {}

    /**
     * @return array{deleted: int}
     */
    public function clearCompleted(): array
    {
        return $this->clearByStatus(AutomationExecutionStatus::Completed->value);
    }

    /**
     * @return array{deleted: int}
     */
    public function clearFailed(): array
    {
        return $this->clearByStatus(AutomationExecutionStatus::Failed->value);
    }

    /**
     * @return array{deleted: int}
     */
    public function clearPartial(): array
    {
        return $this->clearByStatus(AutomationExecutionStatus::Partial->value);
    }

    /**
     * @return array{deleted: int}
     */
    public function clearAll(): array
    {
        return $this->deleteMatching(fn (Builder $query): Builder => $query);
    }

    /**
     * @return array{deleted: int, skipped: bool}
     */
    public function cleanupExpiredLogs(): array
    {
        $retention = $this->settings->resolveRetention();
        if ($retention['mode'] === AutomationSettingsService::RETENTION_FOREVER || $retention['days'] === null) {
            return ['deleted' => 0, 'skipped' => true];
        }

        $cutoff = now()->subDays($retention['days']);

        $result = $this->deleteMatching(
            fn (Builder $query): Builder => $query->where('created_at', '<', $cutoff),
        );

        $this->cleanupOrphanActionRuns($cutoff);

        return [
            'deleted' => $result['deleted'],
            'skipped' => false,
        ];
    }

    /**
     * @return array{deleted: int}
     */
    private function clearByStatus(string $status): array
    {
        return $this->deleteMatching(
            fn (Builder $query): Builder => $query->where('status', $status),
        );
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return array{deleted: int}
     */
    private function deleteMatching(callable $scope): array
    {
        $deleted = 0;

        do {
            $query = AutomationExecution::query()->select(['id', 'execution_uuid', 'business_event_id']);
            $query = $scope($query);

            /** @var Collection<int, AutomationExecution> $batch */
            $batch = $query
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += $this->deleteBatch($batch);
        } while ($batch->count() === self::BATCH_SIZE);

        return ['deleted' => $deleted];
    }

    /**
     * @param  Collection<int, AutomationExecution>  $batch
     */
    private function deleteBatch(Collection $batch): int
    {
        $ids = $batch->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $uuids = $batch->pluck('execution_uuid')
            ->filter(static fn ($uuid): bool => is_string($uuid) && $uuid !== '')
            ->values()
            ->all();
        $eventIds = $batch->pluck('business_event_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        $connection = (new AutomationExecution)->getConnectionName();

        DB::connection($connection)->transaction(function () use ($ids, $uuids, $eventIds): void {
            AutomationActionExecution::query()
                ->whereIn('automation_execution_id', $ids)
                ->delete();

            AutomationNodeExecution::query()
                ->whereIn('automation_execution_id', $ids)
                ->delete();

            if ($uuids !== []) {
                AutomationActionRun::query()
                    ->whereIn('execution_id', $uuids)
                    ->delete();
            }

            AutomationExecution::query()
                ->whereIn('id', $ids)
                ->delete();

            if ($eventIds !== []) {
                BusinessEvent::query()
                    ->whereIn('id', $eventIds)
                    ->whereDoesntHave('executions')
                    ->delete();
            }
        });

        return count($ids);
    }

    private function cleanupOrphanActionRuns(\DateTimeInterface $cutoff): void
    {
        $connection = (new AutomationActionRun)->getConnectionName();
        if (! Schema::connection($connection)->hasTable('automation_action_runs')) {
            return;
        }

        do {
            $deleted = AutomationActionRun::query()
                ->where('created_at', '<', $cutoff)
                ->whereNotIn('execution_id', AutomationExecution::query()->select('execution_uuid'))
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->delete();
        } while ($deleted === self::BATCH_SIZE);
    }
}
