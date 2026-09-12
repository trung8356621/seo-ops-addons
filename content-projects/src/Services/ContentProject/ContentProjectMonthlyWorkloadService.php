<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use App\Models\User;
use App\Services\Users\SeoOpsSystemUser;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical monthly execution workload.
 *
 * MONTHLY PRODUCTION / CAPACITY = ACTIVE execution + ARCHIVED execution.
 * SCOPE_ALL intentionally includes both active and archived.
 * Shared Planning Draft is always excluded.
 * Archive is lifecycle only — does not remove production/capacity ownership.
 *
 * Active cardinality = seo_project_tasks.
 * Archived cardinality = seo_project_archive_items (1 item / article), not live tasks.
 */
final class ContentProjectMonthlyWorkloadService
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_ACTIVE = 'active';

    public const SCOPE_ARCHIVED = 'archived';

    public function __construct(
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
        private readonly ContentProjectWriterCapacitySettingsService $capacitySettings,
        private readonly ContentProjectStaffAvailabilityService $staff,
    ) {}

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     scope: string,
     *     default_capacity: int,
     *     team_capacity: int,
     *     by_domain: list<array{site_id: int, domain: string, active_count: int, archived_count: int, total_count: int}>,
     *     by_writer: list<array{user_id: int, name: string, active_count: int, archived_count: int, total_count: int, capacity: int, remaining: int}>,
     *     domain_empty: bool,
     *     writer_empty: bool,
     *     domain_max: int,
     *     writer_max: int
     * }
     */
    public function forMonth(
        CarbonImmutable|Carbon|string|null $month = null,
        string $scope = self::SCOPE_ALL,
    ): array {
        $scope = $this->normalizeScope($scope);
        $normalized = ContentProjectMonthContext::normalize($month);
        $monthDate = ContentProjectMonthContext::toDateString($normalized);
        $defaultCapacity = $this->capacitySettings->defaultMonthlyCapacity();

        $byDomain = $this->aggregateByDomain($monthDate, $scope);
        $byWriter = $this->aggregateByWriter($monthDate, $scope);
        $teamCapacity = $this->teamCapacityForEligibleWriters();

        $domainMax = 0;
        foreach ($byDomain as $row) {
            $domainMax = max($domainMax, (int) $row['total_count']);
        }

        $writerMax = max(1, $defaultCapacity);
        foreach ($byWriter as $row) {
            $writerMax = max($writerMax, (int) $row['total_count'], (int) $row['capacity']);
        }

        return [
            'month' => $monthDate,
            'month_label' => ContentProjectMonthContext::display($normalized),
            'scope' => $scope,
            'default_capacity' => $defaultCapacity,
            'team_capacity' => $teamCapacity,
            'by_domain' => $byDomain,
            'by_writer' => $byWriter,
            'domain_empty' => $byDomain === [],
            'writer_empty' => $byWriter === [],
            'domain_max' => max(1, $domainMax),
            'writer_max' => max(1, $writerMax),
        ];
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     rows: list<array{site_id: int, domain: string, active_count: int, archived_count: int, total_count: int, count: int}>,
     *     max: int,
     *     empty: bool
     * }
     */
    public function articlesByDomain(
        CarbonImmutable|Carbon|string|null $month = null,
        string $scope = self::SCOPE_ALL,
    ): array {
        $payload = $this->forMonth($month, $scope);
        $rows = [];
        foreach ($payload['by_domain'] as $row) {
            $rows[] = [
                'site_id' => $row['site_id'],
                'domain' => $row['domain'],
                'active_count' => $row['active_count'],
                'archived_count' => $row['archived_count'],
                'total_count' => $row['total_count'],
                'count' => $row['total_count'],
            ];
        }

        return [
            'month' => $payload['month'],
            'month_label' => $payload['month_label'],
            'rows' => $rows,
            'max' => $payload['domain_max'],
            'empty' => $payload['domain_empty'],
        ];
    }

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     default_capacity: int,
     *     team_capacity: int,
     *     rows: list<array{user_id: int, name: string, active_count: int, archived_count: int, total_count: int, count: int, capacity: int, remaining: int}>,
     *     max: int,
     *     empty: bool
     * }
     */
    public function articlesByWriter(
        CarbonImmutable|Carbon|string|null $month = null,
        string $scope = self::SCOPE_ALL,
    ): array {
        $payload = $this->forMonth($month, $scope);
        $rows = [];
        foreach ($payload['by_writer'] as $row) {
            $rows[] = [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'active_count' => $row['active_count'],
                'archived_count' => $row['archived_count'],
                'total_count' => $row['total_count'],
                'count' => $row['total_count'],
                'capacity' => $row['capacity'],
                'remaining' => $row['remaining'],
            ];
        }

        return [
            'month' => $payload['month'],
            'month_label' => $payload['month_label'],
            'default_capacity' => $payload['default_capacity'],
            'team_capacity' => $payload['team_capacity'],
            'rows' => $rows,
            'max' => $payload['writer_max'],
            'empty' => $payload['writer_empty'],
        ];
    }

    /**
     * @return list<array{site_id: int, domain: string, active_count: int, archived_count: int, total_count: int}>
     */
    private function aggregateByDomain(string $monthDate, string $scope): array
    {
        /** @var array<int, array{active_count: int, archived_count: int}> $countsBySiteId */
        $countsBySiteId = [];

        if ($scope === self::SCOPE_ACTIVE || $scope === self::SCOPE_ALL) {
            $this->accumulateDomainTaskCounts(
                $countsBySiteId,
                $this->baseItemQuery($monthDate, self::SCOPE_ACTIVE),
                active: true,
            );
        }

        if ($scope === self::SCOPE_ARCHIVED || $scope === self::SCOPE_ALL) {
            $this->accumulateDomainArchiveItemCounts(
                $countsBySiteId,
                $this->archivedCanonicalItemQuery($monthDate),
            );
        }

        $sites = SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain']);

        if ($sites->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            $counts = $countsBySiteId[$siteId] ?? [
                'active_count' => 0,
                'archived_count' => 0,
            ];
            $domain = trim((string) ($site->domain ?? ''));
            $active = max(0, (int) $counts['active_count']);
            $archived = max(0, (int) $counts['archived_count']);

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : '#'.$siteId,
                'active_count' => $active,
                'archived_count' => $archived,
                'total_count' => $active + $archived,
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $totalCompare = ($right['total_count'] ?? 0) <=> ($left['total_count'] ?? 0);
                if ($totalCompare !== 0) {
                    return $totalCompare;
                }

                return strcmp((string) ($left['domain'] ?? ''), (string) ($right['domain'] ?? ''));
            },
        );

        return $rows;
    }

    /**
     * @return list<array{user_id: int, name: string, active_count: int, archived_count: int, total_count: int, capacity: int, remaining: int}>
     */
    private function aggregateByWriter(string $monthDate, string $scope): array
    {
        /** @var array<int, array{active_count: int, archived_count: int}> $countsByUserId */
        $countsByUserId = [];

        if ($scope === self::SCOPE_ACTIVE || $scope === self::SCOPE_ALL) {
            $this->accumulateWriterTaskCounts(
                $countsByUserId,
                $this->baseItemQuery($monthDate, self::SCOPE_ACTIVE),
                active: true,
            );
        }

        if ($scope === self::SCOPE_ARCHIVED || $scope === self::SCOPE_ALL) {
            $this->accumulateWriterArchiveItemCounts(
                $countsByUserId,
                $this->archivedCanonicalItemQuery($monthDate),
            );
        }

        $userIds = [];
        foreach ($countsByUserId as $userId => $counts) {
            $total = (int) $counts['active_count'] + (int) $counts['archived_count'];
            if ($userId > 0 && $total > 0 && ! SeoOpsSystemUser::isSystemUserId($userId)) {
                $userIds[] = $userId;
            }
        }

        $names = $this->writerCapacity->displayNamesByUserId($userIds);
        $capacities = $this->writerCapacity->capacityByUserId($userIds);
        $defaultCapacity = $this->capacitySettings->defaultMonthlyCapacity();
        $rows = [];
        foreach ($countsByUserId as $userId => $counts) {
            $active = max(0, (int) $counts['active_count']);
            $archived = max(0, (int) $counts['archived_count']);
            $total = $active + $archived;
            if ($userId <= 0 || $total <= 0 || SeoOpsSystemUser::isSystemUserId($userId)) {
                continue;
            }
            $capacity = (int) ($capacities[$userId] ?? $defaultCapacity);
            $rows[] = [
                'user_id' => $userId,
                'name' => $names[$userId] ?? ('#'.$userId),
                'active_count' => $active,
                'archived_count' => $archived,
                'total_count' => $total,
                'capacity' => $capacity,
                'remaining' => $capacity - $total,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => ($right['total_count'] ?? 0) <=> ($left['total_count'] ?? 0),
        );

        return $rows;
    }

    /**
     * @param  array<int, array{active_count: int, archived_count: int}>  $countsBySiteId
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function accumulateDomainTaskCounts(array &$countsBySiteId, $query, bool $active): void
    {
        $raw = (clone $query)
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->groupBy('t.site_id')
            ->selectRaw('t.site_id as site_id, COUNT(t.id) as item_count')
            ->get();

        foreach ($raw as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $count = max(0, (int) ($row->item_count ?? 0));
            if ($siteId <= 0 || $count <= 0) {
                continue;
            }
            if (! isset($countsBySiteId[$siteId])) {
                $countsBySiteId[$siteId] = ['active_count' => 0, 'archived_count' => 0];
            }
            if ($active) {
                $countsBySiteId[$siteId]['active_count'] += $count;
            } else {
                $countsBySiteId[$siteId]['archived_count'] += $count;
            }
        }
    }

    /**
     * @param  array<int, array{active_count: int, archived_count: int}>  $countsBySiteId
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function accumulateDomainArchiveItemCounts(array &$countsBySiteId, $query): void
    {
        $raw = (clone $query)
            ->whereRaw('COALESCE(t.site_id, art.site_id) IS NOT NULL')
            ->whereRaw('COALESCE(t.site_id, art.site_id) > 0')
            ->groupByRaw('COALESCE(t.site_id, art.site_id)')
            ->selectRaw('COALESCE(t.site_id, art.site_id) as site_id, COUNT(ai.id) as item_count')
            ->get();

        foreach ($raw as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $count = max(0, (int) ($row->item_count ?? 0));
            if ($siteId <= 0 || $count <= 0) {
                continue;
            }
            if (! isset($countsBySiteId[$siteId])) {
                $countsBySiteId[$siteId] = ['active_count' => 0, 'archived_count' => 0];
            }
            $countsBySiteId[$siteId]['archived_count'] += $count;
        }
    }

    /**
     * @param  array<int, array{active_count: int, archived_count: int}>  $countsByUserId
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function accumulateWriterTaskCounts(array &$countsByUserId, $query, bool $active): void
    {
        $raw = (clone $query)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0)
            ->groupBy('p.user_id')
            ->selectRaw('p.user_id as user_id, COUNT(t.id) as item_count')
            ->get();

        foreach ($raw as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $count = max(0, (int) ($row->item_count ?? 0));
            if ($userId <= 0 || $count <= 0) {
                continue;
            }
            if (! isset($countsByUserId[$userId])) {
                $countsByUserId[$userId] = ['active_count' => 0, 'archived_count' => 0];
            }
            if ($active) {
                $countsByUserId[$userId]['active_count'] += $count;
            } else {
                $countsByUserId[$userId]['archived_count'] += $count;
            }
        }
    }

    /**
     * @param  array<int, array{active_count: int, archived_count: int}>  $countsByUserId
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function accumulateWriterArchiveItemCounts(array &$countsByUserId, $query): void
    {
        $raw = (clone $query)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0)
            ->groupBy('p.user_id')
            ->selectRaw('p.user_id as user_id, COUNT(ai.id) as item_count')
            ->get();

        foreach ($raw as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $count = max(0, (int) ($row->item_count ?? 0));
            if ($userId <= 0 || $count <= 0) {
                continue;
            }
            if (! isset($countsByUserId[$userId])) {
                $countsByUserId[$userId] = ['active_count' => 0, 'archived_count' => 0];
            }
            $countsByUserId[$userId]['archived_count'] += $count;
        }
    }

    /**
     * SUM of effective monthly capacities for assignable real writers (System User excluded).
     */
    private function teamCapacityForEligibleWriters(): int
    {
        try {
            $ids = $this->staff->baseAssignableStaffQuery()
                ->get(['id'])
                ->map(static fn (User $user): int => (int) $user->getKey())
                ->filter(static fn (int $id): bool => $id > 0 && ! SeoOpsSystemUser::isSystemUserId($id))
                ->values()
                ->all();
        } catch (\Throwable) {
            return 0;
        }

        if ($ids === []) {
            return 0;
        }

        return (int) array_sum($this->capacitySettings->capacitiesForUsers($ids));
    }

    /**
     * Canonical archived monthly items: one row per SeoProjectArchiveItem (article identity).
     * Tasks are metadata only (site_id / plan / post_type) — not cardinality.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function archivedCanonicalItemQuery(CarbonImmutable|Carbon|string|null $month = null)
    {
        $monthDate = ContentProjectMonthContext::toDateString($month);

        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_archive_items as ai')
            ->join('seo_project_archives as a', 'a.id', '=', 'ai.seo_project_archive_id')
            ->join('seo_projects as p', 'p.id', '=', 'a.project_id')
            ->leftJoin('seo_project_tasks as t', 't.id', '=', 'ai.task_id')
            ->leftJoin('articles as art', 'art.id', '=', 'ai.article_id')
            ->whereNull('a.restored_at')
            ->whereNotNull('p.archived_at')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            // Execution month — never archived_at month.
            ->whereDate('p.month', $monthDate)
            ->whereNotNull('ai.article_id')
            ->where('ai.article_id', '>', 0);

        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');

        return $query;
    }

    /**
     * Same filter as SCOPE_ARCHIVED charts / Excel: canonical archive items for the month.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function archivedExecutionItemQuery(CarbonImmutable|Carbon|string|null $month = null)
    {
        return $this->archivedCanonicalItemQuery($month);
    }

    /**
     * Active execution items (tasks on non-archived monthly projects).
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function baseItemQuery(string $monthDate, string $scope)
    {
        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            // Execution month — never archived_at month.
            ->whereDate('p.month', $monthDate)
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        if ($scope === self::SCOPE_ACTIVE) {
            $query->whereNull('p.archived_at');
        } elseif ($scope === self::SCOPE_ARCHIVED) {
            // Kept for callers that still request archived via task query — prefer archivedCanonicalItemQuery.
            $query->whereNotNull('p.archived_at');
        }
        // SCOPE_ALL on the task query is unused for aggregates (merged active + archive-item paths).

        // Global Legacy import is pinned UI only — never monthly execution workload.
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');

        return $query;
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return in_array($scope, [self::SCOPE_ALL, self::SCOPE_ACTIVE, self::SCOPE_ARCHIVED], true)
            ? $scope
            : self::SCOPE_ALL;
    }
}
