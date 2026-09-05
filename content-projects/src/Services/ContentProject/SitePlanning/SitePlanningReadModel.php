<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningSignalService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site Planning overview: 2 past months + current + 1 future.
 */
final class SitePlanningReadModel
{
    public function __construct(
        private readonly ContentProjectMonthlyWorkloadService $workload,
        private readonly SiteMonthlyContentTargetService $targets,
        private readonly McpPlanningSignalService $mcpPlanning,
    ) {}

    /**
     * @return array{
     *     months: list<array{key: string, label: string, is_current: bool}>,
     *     rows: list<array<string, mixed>>,
     *     selected_site_id: int|null,
     *     detail: array<string, mixed>|null
     * }
     */
    public function overview(?int $selectedSiteId = null, CarbonImmutable|Carbon|string|null $now = null): array
    {
        $anchor = CarbonImmutable::parse($now ?? now())->startOfMonth();
        $months = $this->monthWindow($anchor);
        $sites = SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $plannedBySiteMonth = [];
        foreach ($months as $month) {
            $payload = $this->workload->articlesByDomain($month['key'], ContentProjectMonthlyWorkloadService::SCOPE_ACTIVE);
            foreach ($payload['rows'] as $row) {
                $siteId = (int) ($row['site_id'] ?? 0);
                if ($siteId <= 0) {
                    continue;
                }
                $plannedBySiteMonth[$siteId][$month['key']] = (int) ($row['count'] ?? 0);
            }
        }

        $mcpBySite = $this->mcpPlanning->counts()['by_site'];

        $rows = [];
        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            $domain = trim((string) ($site->domain ?? ''));
            $target = $this->targets->forSite($site);
            $ideaStats = $this->ideaStats($siteId);
            $monthCells = [];
            foreach ($months as $month) {
                $planned = (int) ($plannedBySiteMonth[$siteId][$month['key']] ?? 0);
                $monthCells[] = [
                    'key' => $month['key'],
                    'label' => $month['label'],
                    'is_current' => $month['is_current'],
                    'planned' => $planned,
                    'target' => $target,
                    'over_target' => $planned > $target,
                    'delta' => $planned - $target,
                ];
            }

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : '#'.$siteId,
                'ideas_total' => $ideaStats['total'],
                'ideas_new' => $ideaStats['new'],
                'mcp_score' => null,
                'mcp_planning_count' => (int) ($mcpBySite[$siteId] ?? 0),
                'monthly_target' => $target,
                'months' => $monthCells,
            ];
        }

        $selected = $selectedSiteId !== null && $selectedSiteId > 0
            ? $selectedSiteId
            : (int) (($rows[0]['site_id'] ?? 0));

        $detail = null;
        foreach ($rows as $row) {
            if ((int) $row['site_id'] === $selected) {
                $detail = $this->detailForRow($row);
                break;
            }
        }

        return [
            'months' => $months,
            'rows' => $rows,
            'selected_site_id' => $selected > 0 ? $selected : null,
            'detail' => $detail,
        ];
    }

    /**
     * @return list<array{key: string, label: string, is_current: bool}>
     */
    public function monthWindow(CarbonImmutable|Carbon|string|null $now = null): array
    {
        $current = CarbonImmutable::parse($now ?? now())->startOfMonth();
        $window = [];
        for ($offset = -2; $offset <= 1; $offset++) {
            $month = $current->addMonthsNoOverflow($offset)->startOfMonth();
            $window[] = [
                'key' => $month->format('Y-m-d'),
                'label' => $month->format('m/Y'),
                'is_current' => $offset === 0,
            ];
        }

        return $window;
    }

    /**
     * @return array{total: int, new: int}
     */
    public function ideaStats(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['total' => 0, 'new' => 0];
        }

        $total = (int) VocabularySuggestStagingQuery::forSite($siteId)->count();
        $new = $this->latestGenerationAcceptedCount($siteId);

        return [
            'total' => $total,
            'new' => min($new, $total),
        ];
    }

    /**
     * Canonical "Mới": accepted/valid count from latest completed AI new-content planner run for site.
     */
    private function latestGenerationAcceptedCount(int $siteId): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_planner_runs')) {
            return 0;
        }

        // Column is source_type; status lives in result_summary JSON (see ContentProjectPlannerRunService).
        $runs = SeoContentProjectPlannerRun::query()
            ->where('site_id', $siteId)
            ->where('source_type', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'result_summary', 'requested_quantity']);

        $run = null;
        foreach ($runs as $candidate) {
            if (! $candidate instanceof SeoContentProjectPlannerRun) {
                continue;
            }
            $summary = is_array($candidate->result_summary) ? $candidate->result_summary : [];
            $kind = (string) ($summary['kind'] ?? SeoContentProjectPlannerRun::KIND_EXECUTED);
            if ($kind !== SeoContentProjectPlannerRun::KIND_EXECUTED) {
                continue;
            }
            $status = (string) ($summary['status'] ?? '');
            if (in_array($status, [
                SeoContentProjectPlannerRun::STATUS_COMPLETED,
                SeoContentProjectPlannerRun::STATUS_PARTIAL,
            ], true)) {
                $run = $candidate;
                break;
            }
        }

        if (! $run instanceof SeoContentProjectPlannerRun) {
            return 0;
        }

        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        foreach (['valid', 'generated', 'accepted'] as $key) {
            if (isset($summary[$key]) && is_numeric($summary[$key])) {
                return max(0, (int) $summary[$key]);
            }
        }

        return max(0, (int) ($run->requested_quantity ?? 0));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function detailForRow(array $row): array
    {
        $siteId = (int) ($row['site_id'] ?? 0);
        $draftReviewed = 0;
        $executionPending = 0;

        if ($siteId > 0 && Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            $draftQuery = DB::connection('omi_seo_ai')
                ->table('seo_project_tasks as t')
                ->join('seo_projects as p', 'p.id', '=', 't.project_id')
                ->where('p.status', SeoProject::STATUS_DRAFT)
                ->whereNull('p.archived_at')
                ->whereNull('t.archived_at')
                ->whereNotNull('t.planning_reviewed_at')
                ->where('t.site_id', $siteId)
                ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED);
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
                $draftQuery->whereNull('t.deleted_at');
            }
            $draftReviewed = (int) $draftQuery->count();

            $pendingQuery = DB::connection('omi_seo_ai')
                ->table('seo_project_tasks as t')
                ->join('seo_projects as p', 'p.id', '=', 't.project_id')
                ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
                ->whereNull('p.archived_at')
                ->whereNull('t.archived_at')
                ->where('t.site_id', $siteId)
                ->where('t.status', SeoProjectTask::STATUS_PENDING);
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
                $pendingQuery->whereNull('t.deleted_at');
            }
            $executionPending = (int) $pendingQuery->count();
        }

        return [
            'site_id' => $siteId,
            'domain' => $row['domain'],
            'mcp_planning_count' => (int) ($row['mcp_planning_count'] ?? 0),
            'ideas_total' => (int) ($row['ideas_total'] ?? 0),
            'ideas_new' => (int) ($row['ideas_new'] ?? 0),
            'monthly_target' => (int) ($row['monthly_target'] ?? 0),
            'months' => $row['months'] ?? [],
            'draft_reviewed' => $draftReviewed,
            'execution_pending' => $executionPending,
        ];
    }
}
