<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use App\Services\Users\SeoOpsSystemUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Smart Publish selector for SEO Audit Shared Draft:
 * computes monthly remaining capacity for a concrete domain + month
 * and selects up to `remaining` approved Draft tasks in stable order.
 */
final class SmartDraftPublishPlanner
{
    public function __construct(
        private readonly ?SplitDraftContentProjectService $splitter = null,
        private readonly ?ContentProjectWriterMonthlyCapacityService $writerCapacity = null,
    ) {}

    /**
     * Resolve dynamic monthly site limit from canonical Writers × default capacity ÷ active Sites.
     *
     * @return array{
     *     valid: bool,
     *     limit: ?int,
     *     writer_count: int,
     *     per_writer_capacity: int,
     *     site_count: int,
     *     formula_text: string
     * }
     */
    public function resolveAutoMonthlyLimit(?string $planningMonth = null): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $monthDate = Carbon::parse(ContentProjectMonthContext::toDateString($month))->startOfMonth();

        $capacitySvc = $this->writerCapacity ?? app(ContentProjectWriterMonthlyCapacityService::class);
        $payload = $capacitySvc->writerSelectorPayload($monthDate);
        $perWriter = max(1, (int) ($payload['default_capacity'] ?? 30));

        $writerCount = 0;
        foreach (($payload['writers'] ?? []) as $writer) {
            if (! is_array($writer)) {
                continue;
            }
            $id = (int) ($writer['id'] ?? 0);
            if ($id <= 0 || SeoOpsSystemUser::isSystemUserId($id)) {
                continue;
            }
            $writerCount++;
        }

        $siteCount = (int) SeoAccessControl::accessibleSitesQuery()->count();

        return $this->computeMonthlySiteLimit($writerCount, $perWriter, $siteCount);
    }

    /**
     * Pure formula: floor((writer_count * per_writer_capacity) / site_count).
     *
     * @return array{
     *     valid: bool,
     *     limit: ?int,
     *     writer_count: int,
     *     per_writer_capacity: int,
     *     site_count: int,
     *     formula_text: string
     * }
     */
    public function computeMonthlySiteLimit(int $writerCount, int $perWriterCapacity, int $siteCount): array
    {
        $writerCount = max(0, $writerCount);
        $perWriterCapacity = max(1, $perWriterCapacity);
        $siteCount = max(0, $siteCount);

        if ($writerCount <= 0 || $siteCount <= 0) {
            return [
                'valid' => false,
                'limit' => null,
                'writer_count' => $writerCount,
                'per_writer_capacity' => $perWriterCapacity,
                'site_count' => $siteCount,
                'formula_text' => 'Chưa xác định (thiếu Writer hoặc Site)',
            ];
        }

        $limit = intdiv($writerCount * $perWriterCapacity, $siteCount);

        return [
            'valid' => true,
            'limit' => $limit,
            'writer_count' => $writerCount,
            'per_writer_capacity' => $perWriterCapacity,
            'site_count' => $siteCount,
            'formula_text' => sprintf(
                '%d Writer × %d bài ÷ %d Site = %d bài/site/tháng',
                $writerCount,
                $perWriterCapacity,
                $siteCount,
                $limit,
            ),
        ];
    }

    /**
     * Canonical Monthly Planning count for a site + month, excluding unpublished Draft
     * tasks, Global Legacy import shells, cancelled, deleted, and archived tasks.
     */
    public function existingMonthlyCount(int $siteId, string $planningMonth): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $counts = $this->existingMonthlyCountsBySite([$siteId], $planningMonth);

        return (int) ($counts[$siteId] ?? 0);
    }

    /**
     * Batch canonical allocated occupancy by site_id for one planning month.
     *
     * @param  list<int|string>  $siteIds
     * @return array<int, int>
     */
    public function existingMonthlyCountsBySite(array $siteIds, string $planningMonth): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $siteIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_projects')) {
            return [];
        }

        $monthYm = ContentProjectMonthContext::normalize($planningMonth);
        $monthDate = ContentProjectMonthContext::toDateString($monthYm);

        $query = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereIn('t.site_id', $ids)
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->where('t.status', '!=', SeoProjectTask::STATUS_ARCHIVED)
            ->select([
                't.id',
                't.site_id',
                't.planning_month',
                't.created_at',
                't.target_date',
                'p.month as project_month',
            ]);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }

        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $query->where(function ($q) use ($monthDate): void {
                $q->whereDate('t.planning_month', $monthDate)
                    ->orWhere(function ($fallback) use ($monthDate): void {
                        $fallback->whereNull('t.planning_month')
                            ->where(function ($inner) use ($monthDate): void {
                                $inner->whereDate('p.month', $monthDate)
                                    ->orWhereNull('p.month');
                            });
                    });
            });
        } else {
            $query->whereDate('p.month', $monthDate);
        }

        $seenTaskIds = [];
        $counts = [];
        foreach ($query->get() as $row) {
            $taskId = (int) ($row->id ?? 0);
            $siteId = (int) ($row->site_id ?? 0);
            if ($taskId <= 0 || $siteId <= 0 || isset($seenTaskIds[$taskId])) {
                continue;
            }
            $resolved = PlanningMonthBackfill::resolve([
                'planning_month' => $row->planning_month ?? null,
                'created_at' => $row->created_at ?? null,
                'target_date' => $row->target_date ?? null,
                'project_month' => $row->project_month ?? null,
                'project_is_draft' => false,
            ]);
            if ($resolved === $monthYm) {
                $seenTaskIds[$taskId] = true;
                $counts[$siteId] = ($counts[$siteId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Total missing allocated articles across all sites for a month:
     * SUM(max(0, limit - allocated_occupancy(site, month))).
     *
     * @param  list<int|string>  $siteIds
     */
    public function totalMissingForSites(array $siteIds, string $planningMonth, int $limit): int
    {
        $limit = max(1, $limit);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $siteIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return 0;
        }

        $existingBySite = $this->existingMonthlyCountsBySite($ids, $planningMonth);
        $totalMissing = 0;
        foreach ($ids as $siteId) {
            $existing = (int) ($existingBySite[$siteId] ?? 0);
            $totalMissing += max(0, $limit - $existing);
        }

        return $totalMissing;
    }

    /**
     * Multi-site Smart Publish plan across all accessible sites, independent of Domain filter.
     *
     * @param  array<int|string, string>  $sitesById  site_id => domain
     * @return array{
     *     month: string,
     *     month_label: string,
     *     limit: int,
     *     rows: list<array{
     *         site_id: int,
     *         domain: string,
     *         existing: int,
     *         limit: int,
     *         remaining: int,
     *         approved_count: int,
     *         publish_count: int,
     *         kept_in_draft: int,
     *         task_ids: list<int>
     *     }>,
     *     task_ids: list<int>,
     *     total_missing: int,
     *     total_publish: int,
     *     total_approved: int,
     *     total_kept: int,
     *     receiving_domains: list<string>,
     *     can_publish: bool
     * }
     */
    public function planAllSites(SeoProject $draftProject, array $sitesById, string $planningMonth, int $limit): array
    {
        $limit = max(1, $limit);
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $splitter = $this->splitter ?? app(SplitDraftContentProjectService::class);

        $normalizedSites = [];
        foreach ($sitesById as $rawId => $domain) {
            $sid = (int) $rawId;
            if ($sid > 0) {
                $normalizedSites[$sid] = (string) $domain;
            }
        }

        $existingBySite = $this->existingMonthlyCountsBySite(array_keys($normalizedSites), $month);

        $rows = [];
        $allTaskIds = [];
        $seenTaskIds = [];
        $totalMissing = 0;
        $totalPublish = 0;
        $totalApproved = 0;
        $totalKept = 0;
        $receivingDomains = [];

        foreach ($normalizedSites as $siteId => $domain) {
            $existing = (int) ($existingBySite[$siteId] ?? 0);
            $reviewedIds = $splitter->orderedReviewedDraftTaskIds($draftProject, $siteId, null);
            $sitePlan = $this->planFromCounts($siteId, $month, $limit, $existing, $reviewedIds);

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain,
                'existing' => $sitePlan['existing'],
                'limit' => $sitePlan['limit'],
                'remaining' => $sitePlan['remaining'],
                'approved_count' => $sitePlan['approved_count'],
                'publish_count' => $sitePlan['publish_count'],
                'kept_in_draft' => $sitePlan['kept_in_draft'],
                'task_ids' => $sitePlan['task_ids'],
            ];

            $totalMissing += $sitePlan['remaining'];
            $totalPublish += $sitePlan['publish_count'];
            $totalApproved += $sitePlan['approved_count'];
            $totalKept += $sitePlan['kept_in_draft'];

            if ($sitePlan['publish_count'] > 0) {
                $receivingDomains[] = $domain;
                foreach ($sitePlan['task_ids'] as $tid) {
                    if (! isset($seenTaskIds[$tid])) {
                        $seenTaskIds[$tid] = true;
                        $allTaskIds[] = $tid;
                    }
                }
            }
        }

        return [
            'month' => $month,
            'month_label' => ContentProjectMonthContext::display($month),
            'limit' => $limit,
            'rows' => $rows,
            'task_ids' => $allTaskIds,
            'total_missing' => $totalMissing,
            'total_publish' => $totalPublish,
            'total_approved' => $totalApproved,
            'total_kept' => $totalKept,
            'receiving_domains' => $receivingDomains,
            'can_publish' => $totalPublish > 0,
        ];
    }

    /**
     * @return array{
     *     site_id: int,
     *     month: string,
     *     month_label: string,
     *     limit: int,
     *     existing: int,
     *     remaining: int,
     *     approved_count: int,
     *     publish_count: int,
     *     kept_in_draft: int,
     *     task_ids: list<int>,
     *     can_publish: bool
     * }
     */
    public function plan(SeoProject $draftProject, int $siteId, string $planningMonth, int $limit): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $existing = $this->existingMonthlyCount($siteId, $month);
        $splitter = $this->splitter ?? app(SplitDraftContentProjectService::class);
        $reviewedIds = $siteId > 0
            ? $splitter->orderedReviewedDraftTaskIds($draftProject, $siteId, null)
            : [];

        return $this->planFromCounts($siteId, $month, $limit, $existing, $reviewedIds);
    }

    /**
     * Pure calculation from resolved existing monthly count and ordered approved Draft IDs.
     *
     * @param  list<int>  $reviewedIds
     * @return array{
     *     site_id: int,
     *     month: string,
     *     month_label: string,
     *     limit: int,
     *     existing: int,
     *     remaining: int,
     *     approved_count: int,
     *     publish_count: int,
     *     kept_in_draft: int,
     *     task_ids: list<int>,
     *     can_publish: bool
     * }
     */
    public function planFromCounts(int $siteId, string $planningMonth, int $limit, int $existing, array $reviewedIds): array
    {
        $limit = max(1, $limit);
        $existing = max(0, $existing);
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $remaining = max(0, $limit - $existing);

        $approvedCount = count($reviewedIds);
        $publishIds = $remaining > 0 ? array_slice($reviewedIds, 0, $remaining) : [];
        $publishCount = count($publishIds);
        $keptInDraft = max(0, $approvedCount - $publishCount);

        return [
            'site_id' => $siteId,
            'month' => $month,
            'month_label' => ContentProjectMonthContext::display($month),
            'limit' => $limit,
            'existing' => $existing,
            'remaining' => $remaining,
            'approved_count' => $approvedCount,
            'publish_count' => $publishCount,
            'kept_in_draft' => $keptInDraft,
            'task_ids' => $publishIds,
            'can_publish' => $publishCount > 0,
        ];
    }
}
