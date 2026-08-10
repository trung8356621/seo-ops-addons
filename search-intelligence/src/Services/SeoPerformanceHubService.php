<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;


use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SeoPerformanceHubService
{
    private const GSC_SNAPSHOT_META = 'gsc_query_snapshot';

    public function __construct(
        private readonly KeywordPersistenceService $keywordPersistence,
    ) {}

    /**
     * @return array{
     *     total_clicks: int,
     *     total_impressions: int,
     *     avg_ctr: float,
     *     avg_position: float|null,
     *     has_data: bool,
     * }
     */
    public function getGscKpis(?int $siteId): array
    {
        $snapshot = $this->resolveGscSnapshot($siteId);
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : [];

        if ($kpis !== []) {
            return [
                'total_clicks' => (int) ($kpis['total_clicks'] ?? 0),
                'total_impressions' => (int) ($kpis['total_impressions'] ?? 0),
                'avg_ctr' => round((float) ($kpis['avg_ctr'] ?? 0), 2),
                'avg_position' => isset($kpis['avg_position']) ? round((float) $kpis['avg_position'], 1) : null,
                'has_data' => true,
            ];
        }

        $queries = $this->normalizeQueries($snapshot['queries'] ?? []);
        if ($queries === []) {
            return [
                'total_clicks' => 0,
                'total_impressions' => 0,
                'avg_ctr' => 0.0,
                'avg_position' => null,
                'has_data' => false,
            ];
        }

        return $this->aggregateKpisFromQueries($queries);
    }

    /**
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    public function getGscQueries(?int $siteId, string $sortBy = 'impressions', string $sortDir = 'desc'): array
    {
        $snapshot = $this->resolveGscSnapshot($siteId);
        $queries = $this->normalizeQueries($snapshot['queries'] ?? []);

        $allowedSort = ['query', 'clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'impressions';
        }

        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        usort($queries, static function (array $left, array $right) use ($sortBy, $sortDir): int {
            $leftValue = $left[$sortBy] ?? '';
            $rightValue = $right[$sortBy] ?? '';

            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            } else {
                $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $sortDir === 'asc' ? $comparison : -$comparison;
        });

        return $queries;
    }

    /**
     * @return list<array{query: string, impressions: int, position: float, clicks: int, ctr: float}>
     */
    public function getQuickWinQueries(?int $siteId, int $limit = 50): array
    {
        $queries = $this->getGscQueries($siteId, 'impressions', 'desc');

        $quickWins = collect($queries)
            ->filter(static function (array $row): bool {
                $position = $row['position'] ?? null;

                return $position !== null
                    && (float) $position >= 11
                    && (float) $position <= 20
                    && (int) ($row['impressions'] ?? 0) > 0;
            })
            ->sortByDesc(static fn (array $row): int => (int) ($row['impressions'] ?? 0))
            ->take($limit)
            ->values()
            ->all();

        return $quickWins;
    }

    /**
     * @return list<array{phrase: string, article_count: int, articles: list<array{id: int, title: string, url: string}>}>
     */
    public function detectCannibalization(?int $siteId, int $limit = 100): array
    {
        $query = SeoArticle::query()
            ->with(['articleMetas' => static fn ($relation) => $relation->where('meta_key', 'seo_focus_keyword')])
            ->when($siteId !== null && $siteId > 0, static fn ($builder) => $builder->where('site_id', $siteId));

        SeoAccessControl::applyAccessibleSiteScope($query);

        /** @var Collection<int, SeoArticle> $articles */
        $articles = $query->get();

        $groups = [];

        foreach ($articles as $article) {
            $raw = trim((string) ($article->articleMetas->first()?->meta_value ?? ''));
            $phrase = Keyword::normalizeFocusPhrase($raw);
            if ($phrase === '') {
                continue;
            }

            $key = Str::lower($phrase);
            $groups[$key]['phrase'] = $phrase;
            $groups[$key]['articles'][] = [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? __('seo-content-ai::filament.performance_hub.untitled_article')),
                'url' => ArticleResource::getUrl('edit', ['record' => $article->id]),
            ];
        }

        return collect($groups)
            ->filter(static fn (array $group): bool => count($group['articles'] ?? []) > 1)
            ->map(static function (array $group): array {
                return [
                    'phrase' => (string) ($group['phrase'] ?? ''),
                    'article_count' => count($group['articles'] ?? []),
                    'articles' => array_values($group['articles'] ?? []),
                ];
            })
            ->sortByDesc(static fn (array $row): int => (int) ($row['article_count'] ?? 0))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    public function getGscQueriesSource(?int $siteId): array
    {
        $snapshot = $this->resolveGscSnapshot($siteId);

        return $this->normalizeQueries($snapshot['queries'] ?? []);
    }

    /**
     * @return array<string, int>
     */
    public function getGscQueryDistribution(?int $siteId): array
    {
        return app(GscQueriesTableService::class)->distributionFromQueries($this->getGscQueriesSource($siteId));
    }

    public function getGscTotalQueries(?int $siteId): ?int
    {
        $snapshot = $this->resolveGscSnapshot($siteId);
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : [];

        if (isset($kpis['total_queries']) && is_numeric($kpis['total_queries'])) {
            return (int) $kpis['total_queries'];
        }

        $queries = $this->normalizeQueries($snapshot['queries'] ?? []);

        return $queries !== [] ? count($queries) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGscPerformanceChart(?int $siteId, string $metric = 'clicks'): array
    {
        $allowedMetrics = ['clicks', 'impressions', 'ctr', 'position'];
        if (! in_array($metric, $allowedMetrics, true)) {
            $metric = 'clicks';
        }

        $snapshot = $this->resolveGscSnapshot($siteId);
        if ($snapshot === []) {
            return $this->emptyChartPayload($metric, 'empty');
        }

        $timeseries = is_array($snapshot['timeseries'] ?? null) ? $snapshot['timeseries'] : [];
        $current = is_array($timeseries['current'] ?? null) ? $timeseries['current'] : [];
        $previous = is_array($timeseries['previous'] ?? null) ? $timeseries['previous'] : [];
        $chartStatus = (string) ($snapshot['chart_status'] ?? 'empty');

        if ($current === [] && $previous === []) {
            return $this->emptyChartPayload($metric, $chartStatus === 'failed' ? 'failed' : 'empty');
        }

        $labels = array_values(array_unique(array_merge(
            array_column($current, 'date'),
            array_column($previous, 'date'),
        )));
        sort($labels);

        $currentValues = $this->mapTimeseriesMetric($current, $labels, $metric);
        $previousValues = $this->mapTimeseriesMetric($previous, $labels, $metric);

        return [
            'has_data' => true,
            'status' => 'ok',
            'labels' => $labels,
            'current' => $currentValues,
            'previous' => $previousValues,
            'metric' => $metric,
            'is_lower_better' => $metric === 'position',
            'current_label' => __('seo-content-ai::filament.performance_hub.chart_current_period'),
            'previous_label' => __('seo-content-ai::filament.performance_hub.chart_previous_period'),
            'period_days' => (int) ($timeseries['period_days'] ?? 28),
            'current_start' => (string) ($timeseries['current_start'] ?? ''),
            'current_end' => (string) ($timeseries['current_end'] ?? ''),
        ];
    }

    /**
     * @param  list<array{date: string, clicks: int, impressions: int, ctr: float, position: float}>  $rows
     * @param  list<string>  $labels
     * @return list<float|int>
     */
    private function mapTimeseriesMetric(array $rows, array $labels, string $metric): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) ($row['date'] ?? '')] = $row;
        }

        return array_map(static function (string $label) use ($indexed, $metric): float|int {
            $row = $indexed[$label] ?? null;
            if (! is_array($row)) {
                return 0;
            }

            return match ($metric) {
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
                default => 0,
            };
        }, $labels);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyChartPayload(string $metric, string $status): array
    {
        return [
            'has_data' => false,
            'status' => $status,
            'labels' => [],
            'current' => [],
            'previous' => [],
            'metric' => $metric,
            'is_lower_better' => $metric === 'position',
            'current_label' => __('seo-content-ai::filament.performance_hub.chart_current_period'),
            'previous_label' => __('seo-content-ai::filament.performance_hub.chart_previous_period'),
            'period_days' => 28,
            'current_start' => '',
            'current_end' => '',
        ];
    }

    public function pushKeywordToEditor(string $phrase, int $siteId, string $type = Keyword::TYPE_SUGGEST): ?Keyword
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return null;
        }

        if ($siteId <= 0) {
            return null;
        }

        $normalizedType = in_array($type, [Keyword::TYPE_SUGGEST, Keyword::TYPE_NORMAL], true)
            ? $type
            : Keyword::TYPE_SUGGEST;

        return $this->keywordPersistence->upsert(
            phrase: $phrase,
            type: $normalizedType,
            siteId: $siteId,
            metrics: [
                'performance_hub_source' => 'quick_wins',
                'pushed_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGscSnapshot(?int $siteId): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $site->loadMissing('metas');
        $raw = trim((string) ($site->getMeta(self::GSC_SNAPSHOT_META) ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>
     */
    private function normalizeQueries(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $query = trim((string) ($row['query'] ?? $row['keyword'] ?? ''));
            if ($query === '') {
                continue;
            }

            $clicks = (int) ($row['clicks'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $ctr = isset($row['ctr'])
                ? round((float) $row['ctr'], 2)
                : ($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0);
            $position = isset($row['position']) ? round((float) $row['position'], 1) : null;

            $normalized[] = [
                'query' => $query,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position' => $position,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queries
     * @return array{
     *     total_clicks: int,
     *     total_impressions: int,
     *     avg_ctr: float,
     *     avg_position: float|null,
     *     has_data: bool,
     * }
     */
    private function aggregateKpisFromQueries(array $queries): array
    {
        $totalClicks = 0;
        $totalImpressions = 0;
        $positionSum = 0.0;
        $positionCount = 0;

        foreach ($queries as $row) {
            $totalClicks += (int) ($row['clicks'] ?? 0);
            $totalImpressions += (int) ($row['impressions'] ?? 0);

            if (($row['position'] ?? null) !== null) {
                $positionSum += (float) $row['position'];
                $positionCount++;
            }
        }

        return [
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'avg_ctr' => $totalImpressions > 0
                ? round(($totalClicks / $totalImpressions) * 100, 2)
                : 0.0,
            'avg_position' => $positionCount > 0 ? round($positionSum / $positionCount, 1) : null,
            'has_data' => $queries !== [],
        ];
    }

}
