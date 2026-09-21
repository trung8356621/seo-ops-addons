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
 * Site Planning overview: window anchored to activeMonth (−2…+1).
 * Matrix = article distribution count / site / planning_month (includes completed/published/archived).
 * Month Detail = Topic History only (no project reconstruction).
 *
 * Projects-list Monthly Planning uses {@see overviewForProjectsList()} which additionally
 * excludes Global Legacy import shells — same helper as Articles-by-domain workload.
 */
final class SitePlanningReadModel
{
    public function __construct(
        private readonly SiteMonthlyContentTargetService $targets,
        private readonly McpPlanningSignalService $mcpPlanning,
        private readonly SitePlanningActiveUnitAggregator $units = new SitePlanningActiveUnitAggregator,
        private readonly TopicHistoryReadModel $topicHistory = new TopicHistoryReadModel,
    ) {}

    /**
     * Historical Site Planning matrix (Planner / reporting).
     * Keeps Global Legacy import shells in distribution counts.
     *
     * @return array{
     *     months: list<array{key: string, label: string, year: int, month: string, is_current: bool}>,
     *     year_groups: list<array{year: int, span: int}>,
     *     rows: list<array<string, mixed>>,
     *     active_month: string
     * }
     */
    public function overview(?int $selectedSiteId = null, CarbonImmutable|Carbon|string|null $activeMonth = null): array
    {
        return $this->buildOverview($selectedSiteId, $activeMonth, excludeGlobalLegacy: false);
    }

    /**
     * Projects-list Monthly Planning matrix (adjacent to workload charts / Balance Months).
     * Same window + archive/history semantics as overview(), except Global Legacy import
     * shells (`import_source = seo_content_archive_items`) are excluded.
     *
     * @return array{
     *     months: list<array{key: string, label: string, year: int, month: string, is_current: bool}>,
     *     year_groups: list<array{year: int, span: int}>,
     *     rows: list<array<string, mixed>>,
     *     active_month: string
     * }
     */
    public function overviewForProjectsList(
        ?int $selectedSiteId = null,
        CarbonImmutable|Carbon|string|null $activeMonth = null,
    ): array {
        return $this->buildOverview($selectedSiteId, $activeMonth, excludeGlobalLegacy: true);
    }

    /**
     * @return array{
     *     months: list<array{key: string, label: string, year: int, month: string, is_current: bool}>,
     *     year_groups: list<array{year: int, span: int}>,
     *     rows: list<array<string, mixed>>,
     *     active_month: string
     * }
     */
    private function buildOverview(
        ?int $selectedSiteId,
        CarbonImmutable|Carbon|string|null $activeMonth,
        bool $excludeGlobalLegacy,
    ): array {
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
            foreach ($this->units->plannedCountsBySite($monthKeyYm, $excludeGlobalLegacy) as $siteId => $count) {
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
     * Month Detail = Topic History for site + planning_month.
     * Empty when no history rows (legacy projects are NOT reconstructed).
     *
     * @return array{
     *   site_id: int,
     *   domain: string,
     *   planning_month: string,
     *   month_label: string,
     *   topics: list<array{topic_ref: string|null, topic_name: string, planned_article_count: int}>
     * }
     */
    public function cellDetail(int $siteId, string $planningMonth): array
    {
        return $this->topicHistory->forSiteMonth($siteId, $planningMonth);
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
