<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical due-item selection for Publishing Queue scanner.
 * Compare timestamps in UTC only. Retry uses next_publish_retry_at, never scheduled_publish_at.
 */
final class PublishingDueItemSelector
{
    public const DEFAULT_LIMIT = 50;

    /**
     * @return Collection<int, SeoProjectTask>
     */
    public function dueTasks(string $connectionName = 'omi_seo_ai', int $limit = self::DEFAULT_LIMIT): Collection
    {
        $query = $this->baseQuery($connectionName)
            ->where(function (Builder $q) use ($connectionName): void {
                $q->where(fn (Builder $due): Builder => $this->applyScheduledDue($due, $connectionName))
                    ->orWhere(fn (Builder $retry): Builder => $this->applyRetryDue($retry, $connectionName));
            });

        if (Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $query->orderByRaw('COALESCE(next_publish_retry_at, scheduled_publish_at) ASC');
        } else {
            $query->orderBy('scheduled_publish_at');
        }

        return $query
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return array{
     *     due_scheduled_count: int,
     *     due_retry_count: int,
     *     overdue_scheduled_count: int,
     *     overdue_retry_count: int,
     *     now_utc: string
     * }
     */
    public function counts(string $connectionName = 'omi_seo_ai'): array
    {
        $now = $this->nowUtc();
        $scheduled = 0;
        $retry = 0;

        if (Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $scheduled = (int) $this->baseQuery($connectionName)
                ->where(fn (Builder $due): Builder => $this->applyScheduledDue($due, $connectionName))
                ->count();
            $retry = (int) $this->baseQuery($connectionName)
                ->where(fn (Builder $r): Builder => $this->applyRetryDue($r, $connectionName))
                ->count();
        }

        return [
            'due_scheduled_count' => $scheduled,
            'due_retry_count' => $retry,
            'overdue_scheduled_count' => $scheduled,
            'overdue_retry_count' => $retry,
            'now_utc' => $now->toIso8601String(),
        ];
    }

    public function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * @return Builder<SeoProjectTask>
     */
    private function baseQuery(string $connectionName): Builder
    {
        return SeoProjectTask::query()
            ->active()
            ->where('article_id', '>', 0)
            ->whereHas('project', static function ($query): void {
                $query->whereNull('archived_at');
            })
            ->with(['article', 'project']);
    }

    /**
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    private function applyScheduledDue(Builder $query, string $connectionName): Builder
    {
        $now = $this->nowUtc()->toDateTimeString();

        $query->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', $now);

        if (! Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return $query;
        }

        return $query->where(static function (Builder $status): void {
            $status->whereIn('publish_queue_status', [
                ContentProjectPublishQueueStatus::Waiting->value,
                ContentProjectPublishQueueStatus::None->value,
            ])->orWhereNull('publish_queue_status');
        });
    }

    /**
     * @param  Builder<SeoProjectTask>  $query
     * @return Builder<SeoProjectTask>
     */
    private function applyRetryDue(Builder $query, string $connectionName): Builder
    {
        $now = $this->nowUtc()->toDateTimeString();

        if (! Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('publish_queue_status', ContentProjectPublishQueueStatus::Retrying->value);

        if (Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
            $query->whereNotNull('next_publish_retry_at')
                ->where('next_publish_retry_at', '<=', $now);
        } else {
            // Legacy fallback only when retry clock column missing.
            $query->whereNotNull('scheduled_publish_at')
                ->where('scheduled_publish_at', '<=', $now);
        }

        if (Schema::connection($connectionName)->hasColumn('seo_project_tasks', 'publish_lease_expires_at')) {
            $query->where(static function (Builder $lease) use ($now): void {
                $lease->whereNull('publish_lease_expires_at')
                    ->orWhere('publish_lease_expires_at', '<=', $now);
            });
        }

        return $query;
    }
}
