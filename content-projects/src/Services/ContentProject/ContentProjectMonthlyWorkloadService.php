<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
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
 * Shared Planning Draft is always excluded.
 * Archive is lifecycle only — does not remove production/capacity ownership.
 */
final class ContentProjectMonthlyWorkloadService
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_ACTIVE = 'active';

    public const SCOPE_ARCHIVED = 'archived';

    public function __construct(
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
    ) {}

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     scope: string,
     *     capacity: int,
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
        $capacity = ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS;

        $byDomain = $this->aggregateByDomain($monthDate, $scope);
        $byWriter = $this->aggregateByWriter($monthDate, $scope, $capacity);

        $domainMax = 0;
        foreach ($byDomain as $row) {
            $domainMax = max($domainMax, (int) $row['total_count']);
        }

        $writerMax = $capacity;
        foreach ($byWriter as $row) {
            $writerMax = max($writerMax, (int) $row['total_count']);
        }

        return [
            'month' => $monthDate,
            'month_label' => ContentProjectMonthContext::display($normalized),
            'scope' => $scope,
            'capacity' => $capacity,
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
     *     capacity: int,
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
            'capacity' => $payload['capacity'],
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
        $raw = $this->baseItemQuery($monthDate, $scope)
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->groupBy('t.site_id')
            ->selectRaw(
                't.site_id as site_id, '
                .'SUM(CASE WHEN p.archived_at IS NULL THEN 1 ELSE 0 END) as active_count, '
                .'SUM(CASE WHEN p.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived_count, '
                .'COUNT(t.id) as total_count'
            )
            ->get();

        $countsBySiteId = [];
        foreach ($raw as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $countsBySiteId[$siteId] = [
                'active_count' => max(0, (int) ($row->active_count ?? 0)),
                'archived_count' => max(0, (int) ($row->archived_count ?? 0)),
                'total_count' => max(0, (int) ($row->total_count ?? 0)),
            ];
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
                'total_count' => 0,
            ];
            $domain = trim((string) ($site->domain ?? ''));

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : '#'.$siteId,
                'active_count' => $counts['active_count'],
                'archived_count' => $counts['archived_count'],
                'total_count' => $counts['total_count'],
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
    private function aggregateByWriter(string $monthDate, string $scope, int $capacity): array
    {
        $query = $this->baseItemQuery($monthDate, $scope)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0)
            ->groupBy('p.user_id')
            ->selectRaw(
                'p.user_id as user_id, '
                .'SUM(CASE WHEN p.archived_at IS NULL THEN 1 ELSE 0 END) as active_count, '
                .'SUM(CASE WHEN p.archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived_count, '
                .'COUNT(t.id) as total_count'
            )
            ->orderByDesc('total_count');

        $raw = $query->get();
        $userIds = [];
        foreach ($raw as $row) {
            $id = (int) ($row->user_id ?? 0);
            if ($id > 0 && ! SeoOpsSystemUser::isSystemUserId($id)) {
                $userIds[] = $id;
            }
        }

        $names = $this->writerCapacity->displayNamesByUserId($userIds);
        $rows = [];
        foreach ($raw as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $active = max(0, (int) ($row->active_count ?? 0));
            $archived = max(0, (int) ($row->archived_count ?? 0));
            $total = max(0, (int) ($row->total_count ?? 0));
            if ($userId <= 0 || $total <= 0 || SeoOpsSystemUser::isSystemUserId($userId)) {
                continue;
            }
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

        return $rows;
    }

    /**
     * Same filter as SCOPE_ARCHIVED charts: execution month, archived projects, live tasks.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function archivedExecutionItemQuery(CarbonImmutable|Carbon|string|null $month = null)
    {
        $monthDate = ContentProjectMonthContext::toDateString($month);

        return $this->baseItemQuery($monthDate, self::SCOPE_ARCHIVED);
    }

    /**
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
            $query->whereNotNull('p.archived_at');
        }
        // SCOPE_ALL: intentionally includes both active and archived projects.

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
