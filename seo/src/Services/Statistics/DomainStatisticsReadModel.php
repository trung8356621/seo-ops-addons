<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Statistics;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;

/**
 * Analytical domain Statistics — inventory SEO metrics + month time-series.
 *
 * Period semantics (explicit):
 * - Inventory KPIs / distribution / urgent list: current SEO-eligible snapshot
 *   (countsTowardSeoScore), not filtered by month.
 * - Performance chart + period comparison deltas: articles.created_at within month.
 * - Sync/index issues: persisted Site Sync run status + index health rows (no live WP).
 */
final class DomainStatisticsReadModel
{
    public const URGENT_LIMIT = 8;

    /**
     * @return array<string, mixed>
     */
    public function build(?int $siteId, string $month, bool $compare): array
    {
        $monthKey = ContentProjectMonthContext::normalize($month);
        $siteIds = $this->resolveSiteIds($siteId);
        $sites = $this->sitesById($siteIds);

        if ($siteIds === []) {
            return $this->emptyPayload($monthKey, $compare, 'no_sites');
        }

        $inventory = $this->inventoryBySite($siteIds);
        $kpis = $this->aggregateKpis($inventory, $siteIds);
        $distribution = $this->distributionFromInventory($inventory);
        $chart = $this->dailyPerformanceSeries($siteIds, $monthKey);
        $domains = $this->domainRows($sites, $inventory, $compare ? $monthKey : null);
        $urgent = $this->urgentArticles($siteIds);
        $insights = $this->insights($kpis, $domains, $distribution);

        $previousChart = null;
        if ($compare) {
            $previousMonth = CarbonImmutable::createFromFormat('Y-m', $monthKey)
                ->startOfMonth()
                ->subMonth()
                ->format('Y-m');
            $previousChart = $this->dailyPerformanceSeries($siteIds, $previousMonth);
        }

        return [
            'empty' => false,
            'empty_reason' => null,
            'month' => $monthKey,
            'month_label' => ContentProjectMonthContext::display($monthKey),
            'compare' => $compare,
            'site_id' => $siteId !== null && $siteId > 0 ? $siteId : null,
            'semantics' => [
                'total_articles' => 'seo_eligible_inventory_snapshot',
                'avg_seo_score' => 'avg_scored_seo_eligible_inventory',
                'needs_optimize' => 'seo_score_lt_'.SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD,
                'sync_index_issues' => 'persisted_latest_sync_failed_or_needs_attention_plus_not_indexed_health',
                'chart_volume' => 'articles.created_at_day_in_month',
                'chart_avg_score' => 'avg_seo_score_of_articles_created_that_day',
                'distribution' => 'inventory_bands_poor_fair_good_excellent',
                'comparison' => 'created_at_avg_score_current_month_vs_previous_month',
            ],
            'kpis' => $kpis,
            'distribution' => $distribution,
            'chart' => $chart,
            'previous_chart' => $previousChart,
            'domains' => $domains,
            'urgent' => $urgent,
            'insights' => $insights,
        ];
    }

    /**
     * @return list<int>
     */
    private function resolveSiteIds(?int $siteId): array
    {
        $accessible = SeoAccessControl::accessibleSiteIds();
        if ($accessible === []) {
            return [];
        }

        if ($siteId !== null && $siteId > 0) {
            return in_array($siteId, $accessible, true) ? [$siteId] : [];
        }

        return array_values(array_map('intval', $accessible));
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, Site>
     */
    private function sitesById(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('domain')
            ->get()
            ->keyBy(static fn (Site $site): int => (int) $site->getKey())
            ->all();
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, array{
     *     site_id: int,
     *     total: int,
     *     scored: int,
     *     avg_score: float|null,
     *     needs_optimize: int,
     *     poor: int,
     *     fair: int,
     *     good: int,
     *     excellent: int
     * }>
     */
    private function inventoryBySite(array $siteIds): array
    {
        $threshold = (int) SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;
        $rows = SeoArticle::query()
            ->from('articles')
            ->whereIn('articles.site_id', $siteIds)
            ->leftJoin('seo_article_profiles as sap_stats', 'sap_stats.article_id', '=', 'articles.id')
            ->where(function ($query): void {
                $query->where('sap_stats.skip_seo_score', false)
                    ->orWhereNull('sap_stats.skip_seo_score');
            })
            ->groupBy('articles.site_id')
            ->selectRaw('articles.site_id as site_id')
            ->selectRaw('COUNT(articles.id) as total')
            ->selectRaw('SUM(CASE WHEN sap_stats.seo_score IS NOT NULL THEN 1 ELSE 0 END) as scored')
            ->selectRaw('AVG(sap_stats.seo_score) as avg_score')
            ->selectRaw("SUM(CASE WHEN sap_stats.seo_score IS NOT NULL AND sap_stats.seo_score < {$threshold} THEN 1 ELSE 0 END) as needs_optimize")
            ->selectRaw('SUM(CASE WHEN sap_stats.seo_score IS NOT NULL AND sap_stats.seo_score < 50 THEN 1 ELSE 0 END) as poor')
            ->selectRaw('SUM(CASE WHEN sap_stats.seo_score IS NOT NULL AND sap_stats.seo_score >= 50 AND sap_stats.seo_score < 70 THEN 1 ELSE 0 END) as fair')
            ->selectRaw('SUM(CASE WHEN sap_stats.seo_score IS NOT NULL AND sap_stats.seo_score >= 70 AND sap_stats.seo_score < 90 THEN 1 ELSE 0 END) as good')
            ->selectRaw('SUM(CASE WHEN sap_stats.seo_score IS NOT NULL AND sap_stats.seo_score >= 90 THEN 1 ELSE 0 END) as excellent')
            ->get();

        $out = [];
        foreach ($siteIds as $id) {
            $out[$id] = [
                'site_id' => $id,
                'total' => 0,
                'scored' => 0,
                'avg_score' => null,
                'needs_optimize' => 0,
                'poor' => 0,
                'fair' => 0,
                'good' => 0,
                'excellent' => 0,
            ];
        }

        foreach ($rows as $row) {
            $id = (int) ($row->site_id ?? 0);
            if ($id <= 0 || ! isset($out[$id])) {
                continue;
            }
            $scored = (int) ($row->scored ?? 0);
            $out[$id] = [
                'site_id' => $id,
                'total' => (int) ($row->total ?? 0),
                'scored' => $scored,
                'avg_score' => $scored > 0 && $row->avg_score !== null
                    ? round((float) $row->avg_score, 1)
                    : null,
                'needs_optimize' => (int) ($row->needs_optimize ?? 0),
                'poor' => (int) ($row->poor ?? 0),
                'fair' => (int) ($row->fair ?? 0),
                'good' => (int) ($row->good ?? 0),
                'excellent' => (int) ($row->excellent ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @param  list<int>  $siteIds
     * @return array{
     *     total_articles: int,
     *     avg_seo_score: float|null,
     *     needs_optimize: int,
     *     sync_index_issues: int,
     *     scored_articles: int
     * }
     */
    private function aggregateKpis(array $inventory, array $siteIds): array
    {
        $total = 0;
        $scored = 0;
        $needs = 0;
        $scoreSum = 0.0;

        foreach ($inventory as $row) {
            $total += (int) ($row['total'] ?? 0);
            $rowScored = (int) ($row['scored'] ?? 0);
            $scored += $rowScored;
            $needs += (int) ($row['needs_optimize'] ?? 0);
            if ($rowScored > 0 && $row['avg_score'] !== null) {
                $scoreSum += ((float) $row['avg_score']) * $rowScored;
            }
        }

        return [
            'total_articles' => $total,
            'avg_seo_score' => $scored > 0 ? round($scoreSum / $scored, 1) : null,
            'needs_optimize' => $needs,
            'sync_index_issues' => $this->syncIndexIssueCount($siteIds),
            'scored_articles' => $scored,
        ];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function syncIndexIssueCount(array $siteIds): int
    {
        $syncFailed = 0;
        if (
            $siteIds !== []
            && class_exists(SeoSiteSyncRun::class)
            && Schema::connection('omi_seo_ai')->hasTable('seo_site_sync_runs')
        ) {
            $latestIds = SeoSiteSyncRun::query()
                ->selectRaw('MAX(id) as id')
                ->whereIn('site_id', $siteIds)
                ->groupBy('site_id')
                ->pluck('id')
                ->filter()
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($latestIds !== []) {
                $syncFailed = (int) SeoSiteSyncRun::query()
                    ->whereIn('id', $latestIds)
                    ->whereIn('status', ['failed', 'needs_attention'])
                    ->count();
            }
        }

        $notIndexed = 0;
        if (
            $siteIds !== []
            && Schema::connection('omi_seo_ai')->hasTable('seo_article_index_health')
        ) {
            $notIndexed = (int) SeoArticleIndexHealth::query()
                ->whereIn('site_id', $siteIds)
                ->whereIn('current_status', ['not_indexed', 'excluded', 'error', 'failed'])
                ->count();
        }

        return $syncFailed + $notIndexed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array{scored: int, segments: list<array{key: string, label: string, count: int, color: string}>}
     */
    private function distributionFromInventory(array $inventory): array
    {
        $bands = [
            'excellent' => ['label' => '90–100', 'color' => '#22c55e', 'count' => 0],
            'good' => ['label' => '70–89', 'color' => '#3b82f6', 'count' => 0],
            'fair' => ['label' => '50–69', 'color' => '#f59e0b', 'count' => 0],
            'poor' => ['label' => '0–49', 'color' => '#ef4444', 'count' => 0],
        ];

        foreach ($inventory as $row) {
            $bands['excellent']['count'] += (int) ($row['excellent'] ?? 0);
            $bands['good']['count'] += (int) ($row['good'] ?? 0);
            $bands['fair']['count'] += (int) ($row['fair'] ?? 0);
            $bands['poor']['count'] += (int) ($row['poor'] ?? 0);
        }

        $scored = array_sum(array_column($bands, 'count'));
        $segments = [];
        foreach ($bands as $key => $band) {
            $segments[] = [
                'key' => $key,
                'label' => $band['label'],
                'count' => $band['count'],
                'color' => $band['color'],
            ];
        }

        return [
            'scored' => $scored,
            'segments' => $segments,
        ];
    }

    /**
     * @param  list<int>  $siteIds
     * @return array{
     *     month: string,
     *     granularity: string,
     *     empty: bool,
     *     max_volume: int,
     *     points: list<array{day: string, label: string, volume: int, avg_score: float|null}>
     * }
     */
    private function dailyPerformanceSeries(array $siteIds, string $monthKey): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $monthKey)->startOfMonth();
        $end = $start->endOfMonth();
        $daysInMonth = (int) $start->daysInMonth;

        $raw = SeoArticle::query()
            ->from('articles')
            ->whereIn('articles.site_id', $siteIds)
            ->leftJoin('seo_article_profiles as sap_chart', 'sap_chart.article_id', '=', 'articles.id')
            ->where(function ($query): void {
                $query->where('sap_chart.skip_seo_score', false)
                    ->orWhereNull('sap_chart.skip_seo_score');
            })
            ->whereBetween('articles.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy(DB::raw('DATE(articles.created_at)'))
            ->orderBy(DB::raw('DATE(articles.created_at)'))
            ->selectRaw('DATE(articles.created_at) as day')
            ->selectRaw('COUNT(articles.id) as volume')
            ->selectRaw('AVG(sap_chart.seo_score) as avg_score')
            ->get()
            ->keyBy(static fn ($row): string => (string) $row->day);

        $points = [];
        $maxVolume = 0;
        $hasData = false;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $day = $start->setDay($d)->format('Y-m-d');
            $row = $raw->get($day);
            $volume = $row !== null ? (int) ($row->volume ?? 0) : 0;
            $avg = $row !== null && $row->avg_score !== null ? round((float) $row->avg_score, 1) : null;
            if ($volume > 0) {
                $hasData = true;
            }
            $maxVolume = max($maxVolume, $volume);
            $points[] = [
                'day' => $day,
                'label' => sprintf('%02d', $d),
                'volume' => $volume,
                'avg_score' => $avg,
            ];
        }

        return [
            'month' => $monthKey,
            'granularity' => 'day',
            'empty' => ! $hasData,
            'max_volume' => max(1, $maxVolume),
            'points' => $points,
        ];
    }

    /**
     * @param  array<int, Site>  $sites
     * @param  array<int, array<string, mixed>>  $inventory
     * @return list<array<string, mixed>>
     */
    private function domainRows(array $sites, array $inventory, ?string $compareMonth): array
    {
        $periodAvgs = $compareMonth !== null
            ? $this->periodAvgScoreBySite(array_keys($sites), $compareMonth)
            : [];
        $prevAvgs = [];
        if ($compareMonth !== null) {
            $prevMonth = CarbonImmutable::createFromFormat('Y-m', $compareMonth)
                ->startOfMonth()
                ->subMonth()
                ->format('Y-m');
            $prevAvgs = $this->periodAvgScoreBySite(array_keys($sites), $prevMonth);
        }

        $rows = [];
        foreach ($sites as $siteId => $site) {
            $inv = $inventory[$siteId] ?? [
                'total' => 0,
                'avg_score' => null,
                'needs_optimize' => 0,
                'scored' => 0,
            ];
            $trend = null;
            if ($compareMonth !== null) {
                $currentPeriodAvg = $periodAvgs[$siteId] ?? null;
                $previousPeriodAvg = $prevAvgs[$siteId] ?? null;
                if ($currentPeriodAvg !== null && $previousPeriodAvg !== null) {
                    $trend = round($currentPeriodAvg - $previousPeriodAvg, 1);
                }
            }

            $rows[] = [
                'site_id' => $siteId,
                'domain' => (string) ($site->domain ?? ('#'.$siteId)),
                'total_articles' => (int) ($inv['total'] ?? 0),
                'avg_seo_score' => $inv['avg_score'] ?? null,
                'needs_optimize' => (int) ($inv['needs_optimize'] ?? 0),
                'trend' => $trend,
                'overview_url' => DomainResource::getUrl('general', ['record' => $siteId]),
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $cmp = ($b['total_articles'] ?? 0) <=> ($a['total_articles'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($a['domain'] ?? ''), (string) ($b['domain'] ?? ''));
            },
        );

        return $rows;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, float>
     */
    private function periodAvgScoreBySite(array $siteIds, string $monthKey): array
    {
        if ($siteIds === []) {
            return [];
        }

        $start = CarbonImmutable::createFromFormat('Y-m', $monthKey)->startOfMonth();
        $end = $start->endOfMonth();

        $rows = SeoArticle::query()
            ->from('articles')
            ->whereIn('articles.site_id', $siteIds)
            ->leftJoin('seo_article_profiles as sap_period', 'sap_period.article_id', '=', 'articles.id')
            ->where(function ($query): void {
                $query->where('sap_period.skip_seo_score', false)
                    ->orWhereNull('sap_period.skip_seo_score');
            })
            ->whereNotNull('sap_period.seo_score')
            ->whereBetween('articles.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy('articles.site_id')
            ->selectRaw('articles.site_id as site_id, AVG(sap_period.seo_score) as avg_score')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row->site_id ?? 0);
            if ($id > 0 && $row->avg_score !== null) {
                $out[$id] = round((float) $row->avg_score, 1);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<array{id: int, title: string, domain: string, score: float, updated_at: string|null, edit_url: string}>
     */
    private function urgentArticles(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $threshold = (int) SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD;
        $articles = SeoArticle::query()
            ->from('articles')
            ->whereIn('articles.site_id', $siteIds)
            ->leftJoin('seo_article_profiles as sap_urgent', 'sap_urgent.article_id', '=', 'articles.id')
            ->where(function ($query): void {
                $query->where('sap_urgent.skip_seo_score', false)
                    ->orWhereNull('sap_urgent.skip_seo_score');
            })
            ->whereNotNull('sap_urgent.seo_score')
            ->where('sap_urgent.seo_score', '<', $threshold)
            ->orderBy('sap_urgent.seo_score')
            ->orderBy('articles.updated_at')
            ->limit(self::URGENT_LIMIT)
            ->get([
                'articles.id',
                'articles.site_id',
                'articles.title',
                'articles.updated_at',
                'sap_urgent.seo_score as seo_score',
            ]);

        $domainMap = Site::query()
            ->whereIn('id', $siteIds)
            ->pluck('domain', 'id')
            ->mapWithKeys(static fn (mixed $domain, mixed $id): array => [(int) $id => (string) $domain])
            ->all();

        $rows = [];
        foreach ($articles as $article) {
            $id = (int) $article->getKey();
            $siteId = (int) ($article->site_id ?? 0);
            $rows[] = [
                'id' => $id,
                'title' => trim((string) ($article->title ?: __('seo-content-ai::filament.dashboard.all_domains_untitled_article'))),
                'domain' => (string) ($domainMap[$siteId] ?? ''),
                'score' => round((float) $article->seo_score, 1),
                'updated_at' => $article->updated_at?->format('d/m/Y H:i'),
                'edit_url' => ArticleResource::getUrl('edit', ['record' => $id]),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @param  list<array<string, mixed>>  $domains
     * @param  array{scored: int, segments: list<array<string, mixed>>}  $distribution
     * @return list<array{tone: string, text: string}>
     */
    private function insights(array $kpis, array $domains, array $distribution): array
    {
        $out = [];
        $needs = (int) ($kpis['needs_optimize'] ?? 0);
        if ($needs > 0) {
            $out[] = [
                'tone' => 'amber',
                'text' => (string) __('seo-content-ai::filament.statistics.insight_needs_optimize', ['count' => $needs]),
            ];
        }

        $syncIssues = (int) ($kpis['sync_index_issues'] ?? 0);
        if ($syncIssues > 0) {
            $out[] = [
                'tone' => 'danger',
                'text' => (string) __('seo-content-ai::filament.statistics.insight_sync_index', ['count' => $syncIssues]),
            ];
        }

        $declining = 0;
        $improving = 0;
        foreach ($domains as $row) {
            $trend = $row['trend'] ?? null;
            if (! is_numeric($trend)) {
                continue;
            }
            if ((float) $trend < 0) {
                $declining++;
            } elseif ((float) $trend > 0) {
                $improving++;
            }
        }
        if ($declining > 0) {
            $out[] = [
                'tone' => 'danger',
                'text' => (string) __('seo-content-ai::filament.statistics.insight_domains_declining', ['count' => $declining]),
            ];
        } elseif ($improving > 0) {
            $out[] = [
                'tone' => 'success',
                'text' => (string) __('seo-content-ai::filament.statistics.insight_domains_improving', ['count' => $improving]),
            ];
        }

        if ((int) ($distribution['scored'] ?? 0) === 0 && (int) ($kpis['total_articles'] ?? 0) > 0) {
            $out[] = [
                'tone' => 'info',
                'text' => (string) __('seo-content-ai::filament.statistics.insight_no_scores'),
            ];
        }

        return array_slice($out, 0, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $monthKey, bool $compare, string $reason): array
    {
        return [
            'empty' => true,
            'empty_reason' => $reason,
            'month' => $monthKey,
            'month_label' => ContentProjectMonthContext::display($monthKey),
            'compare' => $compare,
            'site_id' => null,
            'semantics' => [],
            'kpis' => [
                'total_articles' => 0,
                'avg_seo_score' => null,
                'needs_optimize' => 0,
                'sync_index_issues' => 0,
                'scored_articles' => 0,
            ],
            'distribution' => ['scored' => 0, 'segments' => []],
            'chart' => [
                'month' => $monthKey,
                'granularity' => 'day',
                'empty' => true,
                'max_volume' => 1,
                'points' => [],
            ],
            'previous_chart' => null,
            'domains' => [],
            'urgent' => [],
            'insights' => [],
        ];
    }
}
