<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningSignalService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Site Planning overview: window anchored to activeMonth (−2…+1), not always wall-clock now.
 * Counts Draft + active execution units deduped by task id.
 */
final class SitePlanningReadModel
{
    public function __construct(
        private readonly SiteMonthlyContentTargetService $targets,
        private readonly McpPlanningSignalService $mcpPlanning,
        private readonly SitePlanningActiveUnitAggregator $units = new SitePlanningActiveUnitAggregator,
    ) {}

    /**
     * @return array{
     *     months: list<array{key: string, label: string, year: int, month: string, is_current: bool}>,
     *     year_groups: list<array{year: int, span: int}>,
     *     rows: list<array<string, mixed>>,
     *     active_month: string
     * }
     */
    public function overview(?int $selectedSiteId = null, CarbonImmutable|Carbon|string|null $activeMonth = null): array
    {
        unset($selectedSiteId);

        $anchorMonth = ContentProjectMonthContext::normalize($activeMonth);
        $anchor = CarbonImmutable::createFromFormat('Y-m', $anchorMonth)?->startOfMonth()
            ?? CarbonImmutable::now()->startOfMonth();
        $months = $this->monthWindow($anchor);
        $sites = SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $plannedBySiteMonth = [];
        foreach ($months as $month) {
            $monthKeyYm = ContentProjectMonthContext::normalize($month['key']);
            foreach ($this->units->plannedCountsBySite($monthKeyYm) as $siteId => $count) {
                $plannedBySiteMonth[(int) $siteId][$month['key']] = (int) $count;
            }
        }

        $mcpBySite = $this->mcpPlanning->counts()['by_site'];

        $rows = [];
        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            $domain = trim((string) ($site->domain ?? ''));
            $target = $this->targets->forSite($site);
            $monthCells = [];
            foreach ($months as $month) {
                $planned = (int) ($plannedBySiteMonth[$siteId][$month['key']] ?? 0);
                $monthCells[] = [
                    'key' => $month['key'],
                    'label' => $month['label'],
                    'year' => $month['year'],
                    'month' => $month['month'],
                    'is_current' => $month['is_current'],
                    'planned' => $planned,
                    'target' => $target,
                    'over_target' => $planned > $target,
                    'delta' => $planned - $target,
                    'planning_month' => ContentProjectMonthContext::normalize($month['key']),
                ];
            }

            $rows[] = [
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : '#'.$siteId,
                'mcp_planning_count' => (int) ($mcpBySite[$siteId] ?? 0),
                'monthly_target' => $target,
                'months' => $monthCells,
            ];
        }

        return [
            'months' => $months,
            'year_groups' => $this->yearGroups($months),
            'rows' => $rows,
            'active_month' => $anchorMonth,
        ];
    }

    /**
     * Detail panel payload for one domain × month cell.
     *
     * @return array<string, mixed>
     */
    public function cellDetail(int $siteId, string $planningMonth): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $agg = $this->units->forSiteMonth($siteId, $month);
        $domain = '';
        if ($siteId > 0) {
            $site = \App\Models\Site::query()->find($siteId, ['id', 'domain']);
            $domain = trim((string) ($site?->domain ?? ''));
        }

        return [
            'site_id' => $siteId,
            'domain' => $domain !== '' ? $domain : '#'.$siteId,
            'planning_month' => $month,
            'month_label' => ContentProjectMonthContext::display($month),
            'totals' => [
                'planned' => $agg['planned'],
                'draft' => $agg['draft'],
                'execution' => $agg['execution'],
                'by_status' => $agg['by_status'],
            ],
            'clusters' => $agg['clusters'],
            'unattributed' => $agg['unattributed'],
            'tasks' => $agg['tasks'],
            /**
             * planning_mcp_share = (# distinct active attributed tasks in cluster)
             *   / (# distinct active attributed tasks for site+month) × 100
             * actual_mcp_share = Site MCP topical profile weight (published articles), unchanged.
             */
            'mcp_formula' => 'planning_mcp_share = attributed_cluster_tasks / attributed_site_month_tasks × 100',
        ];
    }

    /**
     * @return list<array{key: string, label: string, year: int, month: string, is_current: bool}>
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
                'year' => (int) $month->year,
                'month' => $month->format('m'),
                'is_current' => $offset === 0,
            ];
        }

        return $window;
    }

    /**
     * Consecutive months sharing a calendar year → header colspan.
     *
     * @param  list<array{year: int}>  $months
     * @return list<array{year: int, span: int}>
     */
    public function yearGroups(array $months): array
    {
        $groups = [];
        foreach ($months as $month) {
            $year = (int) ($month['year'] ?? 0);
            if ($year <= 0) {
                continue;
            }
            $last = $groups === [] ? null : array_key_last($groups);
            if ($last !== null && (int) $groups[$last]['year'] === $year) {
                $groups[$last]['span']++;
                continue;
            }
            $groups[] = ['year' => $year, 'span' => 1];
        }

        return $groups;
    }

    /**
     * @return array{total: int, new: int}
     */
    public function ideaStats(int $siteId): array
    {
        if ($siteId <= 0) {
            return ['total' => 0, 'new' => 0];
        }

        $query = VocabularySuggestStagingQuery::forSite($siteId);
        // Available Ideas SSOT: exclude tombstoned vocabulary candidates.
        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_consumed_ideas')) {
            $query->whereNotIn('id', function ($sub) use ($siteId): void {
                $sub->select('source_keyword_id')
                    ->from('seo_content_project_consumed_ideas')
                    ->where('site_id', $siteId)
                    ->where('source_type', 'vocabulary_suggest')
                    ->whereNotNull('source_keyword_id');
            });
        }
        $total = (int) $query->count();
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
}
