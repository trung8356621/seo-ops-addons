<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetricStatus;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Services\SeoExtendedProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordGroupMetricSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroupItem;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Omnichannel\Addons\Seo\Enums\PerformanceHubSectionKey;

final class SeoPerformanceDashboardService
{
    public function __construct(
        private readonly SeoPerformanceHubService $performanceHub,
        private readonly GscMonthlyDashboardService $gscMonthlyDashboard,
        private readonly GscQueriesTableService $gscQueriesTable,
        private readonly DataForSeoConnectionService $dataForSeo,
        private readonly SeoSerpProviderConnectionService $serpConnections,
        private readonly GoogleSearchConsoleConnectionService $gscConnection,
        private readonly KeywordRankCheckService $rankCheckService,
        private readonly SeoRankKeywordGroupService $rankGroups,
        private readonly KeywordSerpChangeAnalysisService $serpChangeAnalysis,
        private readonly SerpProviderCapabilityService $providerCapabilities,
        private readonly KeywordSearchVolumeService $searchVolume,
        private readonly KeywordRankComparisonResultService $comparisonResults,
        private readonly SeoProviderRegistry $providerRegistry,
        private readonly SeoProviderConnectionStatusService $connectionStatus,
        private readonly SeoProviderCapabilityResolver $capabilityResolver,
        private readonly SeoExtendedProviderConnectionService $extendedConnections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildGscState(
        ?int $siteId,
        string $sortBy = 'impressions',
        string $sortDir = 'desc',
        string $search = '',
        ?string $positionBucket = null,
        int $page = 1,
        int $perPage = GscQueriesTableService::DEFAULT_PER_PAGE,
        string $chartMetric = 'clicks',
        string $periodKey = '',
    ): array {
        $monthly = $this->gscMonthlyDashboard->buildState($siteId, $periodKey, $chartMetric);
        $gscKpis = is_array($monthly['kpis'] ?? null) ? $monthly['kpis'] : [];
        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $sourceQueries = is_array($monthly['queries'] ?? null) ? $monthly['queries'] : [];
        $tableState = $this->gscQueriesTable->buildTableState(
            queries: $sourceQueries,
            search: $search,
            positionBucket: $positionBucket,
            sortBy: $sortBy,
            sortDir: $sortDir,
            page: $page,
            perPage: $perPage,
        );

        $hasData = ($monthly['has_data'] ?? false) === true;

        return [
            'connection' => $gscStatus,
            'settings_url' => $gscStatus['gsc_edit_url'] ?? $this->resolveSettingsUrl(),
            'period_key' => (string) ($monthly['period_key'] ?? ''),
            'period_label' => (string) ($monthly['period_label'] ?? ''),
            'period_start' => (string) ($monthly['period_start'] ?? ''),
            'period_end' => (string) ($monthly['period_end'] ?? ''),
            'last_synced_at' => $monthly['last_synced_at'] ?? null,
            'month_options' => is_array($monthly['month_options'] ?? null) ? $monthly['month_options'] : [],
            'can_go_next_month' => \Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMonthlyPeriod::canGoNext((string) ($monthly['period_key'] ?? '')),
            'kpis' => $this->buildGscKpiCards($gscKpis, $hasData ? (int) ($gscKpis['total_queries'] ?? 0) : null),
            'distribution' => $this->performanceHub->getGscQueryDistributionFromQueries($sourceQueries),
            'chart' => is_array($monthly['chart'] ?? null) ? $monthly['chart'] : [],
            'queries' => $tableState['rows'],
            'queries_pagination' => $tableState['pagination'],
            'queries_total_filtered' => $tableState['total_filtered'],
            'queries_total_source' => $tableState['total_source'],
            'quick_wins' => $this->performanceHub->getQuickWinQueriesFromSource($sourceQueries),
            'has_data' => $hasData,
            'has_pages' => false,
            'position_bucket' => $this->gscQueriesTable->normalizePositionBucket($positionBucket),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRankState(
        ?int $groupId,
        int $userId,
        string $source,
        string $keywordSearch = '',
        string $positionBucket = '',
        string $comparisonBatchId = '',
    ): array {
        $provider = $this->providerRegistry->resolveProviderFromSource($source) ?? $source;
        if (! $this->providerRegistry->isRawSerpProvider($provider)) {
            throw new \InvalidArgumentException("Invalid rank provider source: {$source}");
        }

        $definition = $this->providerRegistry->get($provider);
        $group = $groupId !== null && $groupId > 0
            ? $this->rankGroups->findAccessibleGroup($groupId, $userId)
            : null;

        $rankingRows = $this->buildRankingRows($group, $provider, $keywordSearch, $positionBucket);
        $distribution = $this->buildRankingDistribution($rankingRows);
        $providerStatus = $this->serpConnections->statusForUser($userId, $provider);
        $hasTargetDomain = filled($group?->target_domain);
        $sections = array_map(static fn (PerformanceHubSectionKey $section): string => $section->value, $definition->dashboardSections);

        return [
            'source' => $source,
            'provider' => $provider,
            'dashboard_sections' => $sections,
            'available_actions' => $definition->dashboardActions,
            'group' => $group !== null ? [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'label' => $group->summaryLabel(),
                'country_code' => (string) $group->country_code,
                'language_code' => (string) $group->language_code,
                'location' => $group->location,
                'device' => (string) $group->device,
                'target_domain' => $group->target_domain,
                'keyword_count' => $group->items()->count(),
            ] : null,
            'connections' => [
                'provider' => $this->buildRankConnectionStrip($provider, $providerStatus),
                'settings_url' => $this->resolveSettingsUrl(),
            ],
            'kpis' => $this->buildRankKpiCards(
                rankingRows: $rankingRows,
                distribution: $distribution,
                groupKeywordCount: $group !== null ? $group->items()->count() : 0,
                hasTargetDomain: $hasTargetDomain,
            ),
            'ranking_rows' => $rankingRows,
            'serp_changes' => $this->serpChangeAnalysis->buildChanges($group?->id, $provider),
            'serp_changes_requires_two_checks' => true,
            'provider_capabilities' => $this->capabilityResolver->legacyToolbarCapabilities($userId, $provider),
            'distribution' => $distribution,
            'advanced_analysis' => $this->buildAdvancedAnalysis(
                group: $group,
                provider: $provider,
                userId: $userId,
                hasTargetDomain: $hasTargetDomain,
                comparisonBatchId: $comparisonBatchId,
            ),
            'has_rank_data' => ($group?->items()->count() ?? 0) > 0,
            'has_rank_provider' => ($providerStatus['configured'] ?? false) === true,
            'has_target_domain' => $hasTargetDomain,
            'position_bucket' => $positionBucket,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildState(
        ?int $siteId,
        string $keywordSearch = '',
        string $sortBy = 'impressions',
        string $sortDir = 'desc',
        string $device = 'all',
        string $location = '',
    ): array {
        return [
            'gsc' => $this->buildGscState($siteId, $sortBy, $sortDir),
            'rank' => $this->buildRankState(null, (int) auth()->id(), SerpProviderKeys::SERPER),
        ];
    }

    public function resolveDefaultDataSource(?int $siteId): string
    {
        $userId = (int) auth()->id();
        $tabs = $this->connectionStatus->performanceTabsForUser($userId);
        if ($tabs !== []) {
            return (string) $tabs[0]['key'];
        }

        return 'gsc';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSourceTabs(int $userId): array
    {
        return $this->connectionStatus->performanceTabsForUser($userId);
    }

    public function resolveSourceOrFallback(string $requestedSource, int $userId, ?int $siteId): string
    {
        $tabs = $this->availableSourceTabs($userId);
        $allowed = array_map(static fn (array $tab): string => (string) $tab['key'], $tabs);

        if (in_array($requestedSource, $allowed, true)) {
            return $requestedSource;
        }

        return $this->resolveDefaultDataSource($siteId);
    }

    public function hasRankProvider(string $source): bool
    {
        $provider = $this->providerRegistry->resolveProviderFromSource($source);

        return $provider !== null
            && $this->providerRegistry->isRawSerpProvider($provider)
            && $this->serpConnections->isConfiguredForUser((int) auth()->id(), $provider);
    }

    public function isKeywordMetricsSource(string $source): bool
    {
        $provider = $this->providerRegistry->resolveProviderFromSource($source);

        return $provider === \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::KEYWORDS_EVERYWHERE;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildKeywordMetricsState(?int $groupId, int $userId, string $source): array
    {
        $provider = $this->providerRegistry->resolveProviderFromSource($source);
        if ($provider !== \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::KEYWORDS_EVERYWHERE) {
            throw new \InvalidArgumentException("Invalid keyword metrics source: {$source}");
        }

        $definition = $this->providerRegistry->get($provider);
        $group = $groupId !== null && $groupId > 0
            ? $this->rankGroups->findAccessibleGroup($groupId, $userId)
            : null;
        $connectionStatus = $this->extendedConnections->statusForUser($userId, $provider);
        $hasImplementedMetrics = false;
        foreach ($definition->dashboardSections as $section) {
            if ($section !== PerformanceHubSectionKey::IntegrationState) {
                $hasImplementedMetrics = $definition->isCapabilityImplemented(\Omnichannel\Addons\Seo\Enums\SeoProviderCapabilityKey::SearchVolume);
                break;
            }
        }

        return [
            'source' => $source,
            'provider' => $provider,
            'dashboard_sections' => array_map(static fn (PerformanceHubSectionKey $section): string => $section->value, $definition->dashboardSections),
            'available_actions' => $definition->dashboardActions,
            'group' => $group !== null ? [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'label' => $group->summaryLabel(),
                'keyword_count' => $group->items()->count(),
            ] : null,
            'connections' => [
                'provider' => [
                    'label' => $definition->label,
                    'status' => $connectionStatus['status'] ?? 'not_configured',
                    'status_label' => $connectionStatus['label'] ?? '',
                    'configured' => ($connectionStatus['configured'] ?? false) === true,
                ],
                'settings_url' => $this->resolveSettingsUrl(),
            ],
            'integration_state' => [
                'state' => $hasImplementedMetrics ? 'partial_implementation' : 'partial_implementation',
                'message' => __('seo-content-ai::filament.performance_hub.keyword_metrics_not_integrated'),
            ],
            'keyword_count' => $group?->items()->count() ?? 0,
            'metrics_available_count' => 0,
        ];
    }

    /**
     * @param  list<string>  $metrics
     * @return array{queued: bool, keyword_count: int, run_id: int|null, metrics: list<string>}
     */
    public function dispatchRankCheck(
        int $groupId,
        int $userId,
        string $source,
        array $metrics = ['rank'],
    ): array {
        $provider = $this->providerRegistry->resolveProviderFromSource($source) ?? $source;

        if (! $this->serpConnections->isConfiguredForUser($userId, $provider)) {
            throw new \RuntimeException(__('seo-content-ai::filament.api_connections.serp_not_configured'));
        }

        $metrics = $this->capabilityResolver->filterDispatchableMetrics($userId, $provider, $metrics);

        if ($metrics === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.metric_not_supported'));
        }

        return $this->rankCheckService->dispatchForGroup(
            groupId: $groupId,
            userId: $userId,
            provider: $provider,
            metrics: $metrics,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRankConnectionStrip(string $provider, array $providerStatus): array
    {
        return [
            'provider' => $provider,
            'label' => SerpProviderKeys::label($provider),
            'status' => $providerStatus['label'] ?? __('seo-content-ai::filament.api_connections.not_configured'),
            'status_code' => $providerStatus['status'] ?? 'not_configured',
            'configured' => ($providerStatus['configured'] ?? false) === true,
            'active' => ($providerStatus['active'] ?? false) === true,
            'last_checked_at' => $providerStatus['last_checked_at'] ?? null,
            'last_rank_check_at' => $providerStatus['last_rank_check_at'] ?? null,
            'usage_label' => $providerStatus['usage_label'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGscKpiCards(array $gscKpis, ?int $totalQueries): array
    {
        $hasData = ($gscKpis['has_data'] ?? false) === true;

        return [
            'total_clicks' => [
                'value' => $hasData ? (int) ($gscKpis['total_clicks'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'total_impressions' => [
                'value' => $hasData ? (int) ($gscKpis['total_impressions'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'avg_ctr' => [
                'value' => $hasData ? (float) ($gscKpis['avg_ctr'] ?? 0) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'avg_position' => [
                'value' => $hasData ? ($gscKpis['avg_position'] ?? null) : null,
                'label' => $hasData ? null : 'not_synced',
            ],
            'total_queries' => [
                'value' => $hasData ? $totalQueries : null,
                'label' => $hasData ? null : 'not_synced',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rankingRows
     * @param  array<string, int>  $distribution
     * @return array<string, mixed>
     */
    private function buildRankKpiCards(
        array $rankingRows,
        array $distribution,
        int $groupKeywordCount = 0,
        bool $hasTargetDomain = true,
    ): array {
        $trackedCount = $groupKeywordCount > 0 ? $groupKeywordCount : count($rankingRows);
        $hasPositionData = collect($rankingRows)->contains(
            static fn (array $row): bool => is_numeric($row['position'] ?? null),
        );

        $avgPosition = null;
        if ($hasPositionData) {
            $positions = collect($rankingRows)
                ->pluck('position')
                ->filter(static fn (mixed $value): bool => is_numeric($value))
                ->map(static fn (mixed $value): float => (float) $value);
            $avgPosition = $positions->isNotEmpty() ? round($positions->avg(), 1) : null;
        }

        $positionLabel = ! $hasTargetDomain
            ? 'no_target_domain'
            : ($hasPositionData ? null : 'no_data');

        return [
            'tracked_keywords' => [
                'value' => $trackedCount > 0 ? $trackedCount : null,
                'label' => $trackedCount > 0 ? null : 'no_data',
            ],
            'top_3' => [
                'value' => $hasPositionData ? (int) ($distribution['top_3'] ?? 0) : null,
                'label' => $positionLabel,
            ],
            'top_10' => [
                'value' => $hasPositionData ? (int) (($distribution['top_3'] ?? 0) + ($distribution['top_4_10'] ?? 0)) : null,
                'label' => $positionLabel,
            ],
            'avg_position' => [
                'value' => $avgPosition,
                'label' => $positionLabel ?? ($avgPosition === null ? 'no_data' : null),
            ],
            'visibility' => [
                'value' => $hasPositionData ? $this->calculateVisibilityScore($rankingRows) : null,
                'label' => $positionLabel ?? 'not_synced',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRankingRows(
        ?SeoRankKeywordGroup $group,
        string $provider,
        string $keywordSearch,
        string $positionBucket = '',
    ): array {
        if ($group === null) {
            return [];
        }

        $itemsQuery = SeoRankKeywordGroupItem::query()
            ->where('group_id', $group->id)
            ->with('keyword')
            ->orderBy('id');

        if (trim($keywordSearch) !== '') {
            $needle = '%'.addcslashes(trim($keywordSearch), '%_').'%';
            $itemsQuery->whereHas('keyword', static fn ($builder) => $builder->where('phrase', 'like', $needle));
        }

        $items = $itemsQuery->get();
        if ($items->isEmpty()) {
            return [];
        }

        $itemIds = $items->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $latestIds = KeywordRankSnapshot::query()
            ->selectRaw('MAX(id) as id')
            ->where('rank_group_id', $group->id)
            ->where('provider', $provider)
            ->whereIn('rank_group_item_id', $itemIds)
            ->groupBy('rank_group_item_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $snapshotsByItemId = $latestIds === []
            ? collect()
            : KeywordRankSnapshot::query()
                ->whereIn('id', $latestIds)
                ->get()
                ->keyBy(static fn (KeywordRankSnapshot $snapshot): int => (int) ($snapshot->rank_group_item_id ?? 0));

        $metricSnapshots = $this->latestMetricSnapshotsByItem($itemIds);

        $rows = $items->map(function (SeoRankKeywordGroupItem $item) use ($snapshotsByItemId, $provider, $group, $metricSnapshots): array {
            $snapshot = $snapshotsByItemId->get((int) $item->id);
            $metrics = $metricSnapshots[(int) $item->id] ?? [];

            if ($snapshot === null) {
                return [
                    'keyword_id' => (int) $item->keyword_id,
                    'keyword' => (string) ($item->keyword?->phrase ?? ''),
                    'position' => null,
                    'change' => null,
                    'volume' => $metrics['search_volume']['value'] ?? null,
                    'volume_status' => $metrics['search_volume']['status'] ?? KeywordMetricStatus::Pending->value,
                    'allintitle' => $metrics['allintitle']['value'] ?? null,
                    'allintitle_status' => $metrics['allintitle']['status'] ?? KeywordMetricStatus::Pending->value,
                    'url' => null,
                    'status' => KeywordMetricStatus::Pending->value,
                    'error' => null,
                    'updated_at' => null,
                ];
            }

            $previous = KeywordRankSnapshot::query()
                ->where('rank_group_id', $group->id)
                ->where('rank_group_item_id', $snapshot->rank_group_item_id)
                ->where('provider', $provider)
                ->where('id', '<', $snapshot->id)
                ->orderByDesc('id')
                ->first();

            $change = null;
            if ($previous !== null && $snapshot->position !== null && $previous->position !== null) {
                $change = (int) round((float) $previous->position - (float) $snapshot->position);
            }

            return [
                'keyword_id' => (int) $snapshot->keyword_id,
                'keyword' => (string) ($snapshot->keyword?->phrase ?? $item->keyword?->phrase ?? ''),
                'position' => $snapshot->position,
                'change' => $change,
                'volume' => $metrics['search_volume']['value'] ?? null,
                'volume_status' => $metrics['search_volume']['status'] ?? KeywordMetricStatus::NotConfigured->value,
                'allintitle' => $metrics['allintitle']['value'] ?? null,
                'allintitle_status' => $metrics['allintitle']['status'] ?? KeywordMetricStatus::Pending->value,
                'url' => $snapshot->ranking_url,
                'status' => $this->normalizeRankStatus($snapshot->request_status),
                'error' => $snapshot->error_message,
                'updated_at' => $snapshot->checked_at?->toDateTimeString(),
            ];
        });

        return $rows
            ->filter(function (array $row) use ($positionBucket): bool {
                if ($positionBucket === '') {
                    return true;
                }

                return $this->matchesPositionBucket($row['position'] ?? null, $positionBucket);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function buildRankingDistribution(array $rows): array
    {
        $distribution = [
            'top_3' => 0,
            'top_4_10' => 0,
            'top_11_20' => 0,
            'top_21_50' => 0,
            'top_51_100' => 0,
        ];

        foreach ($rows as $row) {
            $position = $row['position'] ?? null;
            if (! is_numeric($position)) {
                continue;
            }

            $pos = (int) round((float) $position);
            if ($pos <= 3) {
                $distribution['top_3']++;
            } elseif ($pos <= 10) {
                $distribution['top_4_10']++;
            } elseif ($pos <= 20) {
                $distribution['top_11_20']++;
            } elseif ($pos <= 50) {
                $distribution['top_21_50']++;
            } elseif ($pos <= 100) {
                $distribution['top_51_100']++;
            }
        }

        return $distribution;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array<string, array{value: int|null, status: string}>>
     */
    private function latestMetricSnapshotsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $latestIds = KeywordGroupMetricSnapshot::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('rank_group_item_id', $itemIds)
            ->groupBy('rank_group_item_id', 'metric_type')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $mapped = [];
        foreach (KeywordGroupMetricSnapshot::query()->whereIn('id', $latestIds)->get() as $snapshot) {
            $itemId = (int) $snapshot->rank_group_item_id;
            $mapped[$itemId][$snapshot->metric_type] = [
                'value' => $snapshot->value_int !== null ? (int) $snapshot->value_int : null,
                'status' => (string) $snapshot->status,
            ];
        }

        return $mapped;
    }

    private function normalizeRankStatus(?string $status): string
    {
        return match ($status) {
            'success_found' => KeywordMetricStatus::Success->value,
            'success_not_found' => KeywordMetricStatus::NotFound->value,
            default => $status !== null && $status !== '' ? KeywordMetricStatus::Failed->value : KeywordMetricStatus::Pending->value,
        };
    }

    /**
     * @return array{
     *     has_any: bool,
     *     organic_visibility: array{eligible: bool, successful_run_count: int, data: array{labels: list<string>, current: list<int>, previous: list<int>, has_data: bool}},
     *     provider_comparison: array{eligible: bool, provider_count: int, data: list<array<string, mixed>>}
     * }
     */
    private function buildAdvancedAnalysis(
        ?SeoRankKeywordGroup $group,
        string $provider,
        int $userId,
        bool $hasTargetDomain,
        string $comparisonBatchId,
    ): array {
        $successfulRunCount = $this->countSuccessfulRankRuns($group, $provider);
        $organicEligible = $group !== null
            && $hasTargetDomain
            && $successfulRunCount >= 2;

        $organicData = $organicEligible
            ? $this->buildVisibilityChart($group, $provider, $hasTargetDomain)
            : ['labels' => [], 'current' => [], 'previous' => [], 'has_data' => false];

        $providerCount = 0;
        foreach ($this->serpConnections->configuredForUser($userId) as $connection) {
            if ($this->providerRegistry->isRankCompatibleProvider((string) $connection->provider)) {
                $providerCount++;
            }
        }
        $comparisonRows = $comparisonBatchId !== ''
            ? $this->comparisonResults->buildRows($comparisonBatchId)
            : [];
        $comparisonEligible = $providerCount >= 2
            && $comparisonRows !== []
            && SeoAccessControl::canAccessManagerFeatures();

        return [
            'has_any' => $organicEligible || $comparisonEligible,
            'organic_visibility' => [
                'eligible' => $organicEligible,
                'successful_run_count' => $successfulRunCount,
                'data' => $organicData,
            ],
            'provider_comparison' => [
                'eligible' => $comparisonEligible,
                'provider_count' => $providerCount,
                'data' => $comparisonRows,
            ],
        ];
    }

    private function countSuccessfulRankRuns(?SeoRankKeywordGroup $group, string $provider): int
    {
        if ($group === null) {
            return 0;
        }

        return (int) KeywordRankCheckRun::query()
            ->where('rank_group_id', $group->id)
            ->where('provider', $provider)
            ->where('status', 'completed')
            ->whereIn('id', KeywordRankSnapshot::query()
                ->select('run_id')
                ->where('rank_group_id', $group->id)
                ->where('provider', $provider)
                ->whereNotNull('run_id')
                ->distinct())
            ->count();
    }

    /**
     * @return array{labels: list<string>, current: list<int>, previous: list<int>, has_data: bool}
     */
    private function buildVisibilityChart(?SeoRankKeywordGroup $group, string $provider, bool $hasTargetDomain): array
    {
        if ($group === null || ! $hasTargetDomain) {
            return ['labels' => [], 'current' => [], 'previous' => [], 'has_data' => false];
        }

        $snapshots = KeywordRankSnapshot::query()
            ->where('rank_group_id', $group->id)
            ->where('provider', $provider)
            ->where('checked_at', '>=', now()->subDays(56))
            ->orderBy('checked_at')
            ->get(['checked_at', 'position']);

        if ($snapshots->isEmpty()) {
            return ['labels' => [], 'current' => [], 'previous' => [], 'has_data' => false];
        }

        $currentBuckets = [];
        $previousBuckets = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->checked_at === null || $snapshot->position === null) {
                continue;
            }

            $day = $snapshot->checked_at->toDateString();
            $visibility = max(0, 100 - (int) round((float) $snapshot->position));

            if ($snapshot->checked_at->gte(now()->subDays(28))) {
                $currentBuckets[$day] = ($currentBuckets[$day] ?? 0) + $visibility;
            } else {
                $previousBuckets[$day] = ($previousBuckets[$day] ?? 0) + $visibility;
            }
        }

        $labels = collect(array_keys($currentBuckets))->sort()->values()->all();

        return [
            'labels' => $labels,
            'current' => array_map(static fn (string $label): int => (int) ($currentBuckets[$label] ?? 0), $labels),
            'previous' => array_map(static fn (string $label): int => (int) ($previousBuckets[$label] ?? 0), $labels),
            'has_data' => $labels !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function calculateVisibilityScore(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $score = collect($rows)
            ->filter(static fn (array $row): bool => is_numeric($row['position'] ?? null))
            ->avg(static fn (array $row): int => max(0, 100 - (int) round((float) $row['position'])));

        return (int) round((float) $score);
    }

    private function matchesPositionBucket(mixed $position, string $bucket): bool
    {
        if (! is_numeric($position)) {
            return false;
        }

        $pos = (int) round((float) $position);

        return match ($bucket) {
            '1-3' => $pos <= 3,
            '4-10' => $pos >= 4 && $pos <= 10,
            '11-20' => $pos >= 11 && $pos <= 20,
            '21-50' => $pos >= 21 && $pos <= 50,
            '51-100' => $pos >= 51 && $pos <= 100,
            default => true,
        };
    }

    private function resolveSettingsUrl(): string
    {
        if (SeoConnectionContext::hash() === null) {
            return '#';
        }

        return AiConnectionResource::getUrl();
    }
}
