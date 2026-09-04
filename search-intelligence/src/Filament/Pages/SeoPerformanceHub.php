<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleBulkSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\GscQueriesTableService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordRankComparisonResultService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordRankComparisonService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordSearchVolumeService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceDashboardService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoPerformanceHubService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoRankKeywordGroupService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMonthlyPeriod;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSocialTop10Builder;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

final class SeoPerformanceHub extends SeoPanelPage
{
    public const TARGET_DOMAIN_CUSTOM = '__custom__';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = null;

    protected static ?string $navigationLabel = 'SEO Performance';

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SEO;

    protected static ?string $slug = 'performance-hub';

    protected static string $view = 'seo-content-ai::seo.performance-hub';

    protected static bool $shouldRegisterNavigation = true;

    #[Url(as: 'month')]
    public string $gscMonth = '';

    #[Url(as: 'source')]
    public string $dataSource = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'queries';

    #[Url(as: 'sort')]
    public string $querySortBy = 'impressions';

    #[Url(as: 'dir')]
    public string $querySortDir = 'desc';

    #[Url(as: 'rank_group')]
    public ?int $rankGroupId = null;

    #[Url(as: 'q')]
    public string $keywordSearch = '';

    public string $keywordSearchInput = '';

    #[Url(as: 'position_bucket')]
    public string $positionBucket = '';

    #[Url(as: 'gsc_page')]
    public int $gscPage = 1;

    #[Url(as: 'gsc_per_page')]
    public int $gscPerPage = GscQueriesTableService::DEFAULT_PER_PAGE;

    #[Url(as: 'gsc_q')]
    public string $gscQuerySearch = '';

    public string $gscQuerySearchInput = '';

    #[Url(as: 'gsc_metric')]
    public string $gscChartMetric = 'clicks';

    public bool $gscMcpDrawerOpen = false;

    public bool $gscMcpDrawerLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $gscMcpPreview = null;

    public bool $gscMcpShowRaw = false;

    public bool $gscMcpRebuilding = false;

    public string $dateRange = '28d';

    public string $device = 'all';

    public string $location = '';

    public bool $isGscBulkSyncing = false;

    /** @var array<string, mixed>|null */
    public ?array $gscBulkSyncResult = null;

    public string $gscImportCsv = '';

    /** @var array<string, mixed>|null */
    public ?array $gscImportPreview = null;

    #[Url(as: 'comparison_batch')]
    public string $comparisonBatchId = '';

    public string $comparisonKeyword = '';

    public string $groupFormName = '';

    public string $groupFormDescription = '';

    public string $groupFormCountry = 'vn';

    public string $groupFormLanguage = 'vi';

    public string $groupFormLocation = '';

    public string $groupFormDevice = 'desktop';

    public string $groupFormTargetDomain = '';

    public string $groupFormTargetDomainChoice = '';

    public string $groupFormTargetDomainCustom = '';

    public string $groupFormKeywordsText = '';

    public ?int $editingGroupId = null;

    public string $groupModalMode = 'create';

    public bool $groupModalLoading = false;

    public bool $groupModalSubmitting = false;

    public ?string $groupModalLoadError = null;

    public int $groupModalLoadToken = 0;

    public bool $runMetricsRank = true;

    public bool $runMetricsAllintitle = true;

    public bool $runMetricsSearchVolume = true;

    /** @var list<string> */
    public array $comparisonProviders = [];

    private SeoPerformanceHubService $performanceHub;

    private SeoPerformanceDashboardService $dashboard;

    private GoogleSearchConsoleSyncService $gscSync;

    private GoogleSearchConsoleBulkSyncService $gscBulkSync;

    private GoogleSearchConsoleConnectionService $gscConnection;

    private GscQueriesTableService $gscQueriesTable;

    private SeoSerpProviderConnectionService $serpConnections;

    private KeywordRankComparisonService $rankComparison;

    private KeywordRankComparisonResultService $comparisonResults;

    private SeoRankKeywordGroupService $rankGroups;

    private SeoProviderRegistry $providerRegistry;

    private SeoProviderCapabilityResolver $capabilityResolver;

    private int $lastResolvedSiteId = 0;

    public function boot(
        SeoPerformanceHubService $performanceHub,
        SeoPerformanceDashboardService $dashboard,
        GoogleSearchConsoleSyncService $gscSync,
        GoogleSearchConsoleBulkSyncService $gscBulkSync,
        GoogleSearchConsoleConnectionService $gscConnection,
        GscQueriesTableService $gscQueriesTable,
        SeoSerpProviderConnectionService $serpConnections,
        KeywordRankComparisonService $rankComparison,
        KeywordRankComparisonResultService $comparisonResults,
        SeoRankKeywordGroupService $rankGroups,
        SeoProviderRegistry $providerRegistry,
        SeoProviderCapabilityResolver $capabilityResolver,
    ): void {
        $this->performanceHub = $performanceHub;
        $this->dashboard = $dashboard;
        $this->gscSync = $gscSync;
        $this->gscBulkSync = $gscBulkSync;
        $this->gscConnection = $gscConnection;
        $this->gscQueriesTable = $gscQueriesTable;
        $this->serpConnections = $serpConnections;
        $this->rankComparison = $rankComparison;
        $this->comparisonResults = $comparisonResults;
        $this->rankGroups = $rankGroups;
        $this->providerRegistry = $providerRegistry;
        $this->capabilityResolver = $capabilityResolver;
    }

    public function booted(): void
    {
        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($this->lastResolvedSiteId > 0 && $this->lastResolvedSiteId !== $siteId) {
            $this->resetGscTableState();
        }

        $this->lastResolvedSiteId = $siteId;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.performance_hub.nav_seo_performance');
    }

    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    /**
     * WordPress-style module: SEO → Performance / MCP Intelligence.
     *
     * @return array<int, \Filament\Navigation\NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation()) {
            return [];
        }

        $parentLabel = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::moduleSeo();
        $children = [
            \Filament\Navigation\NavigationItem::make(static::getNavigationLabel())
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::isSeoPerformanceNav()),
        ];

        if (\Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::canAccess()) {
            $children[] = \Filament\Navigation\NavigationItem::make(
                \Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::getNavigationLabel()
            )
                ->parentItem($parentLabel)
                ->url(\Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::getUrl())
                ->isActiveWhen(fn (): bool => \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::isMcpIntelligenceNav());
        }

        if (\Omnichannel\Addons\Social\Filament\Pages\SocialProfilesPage::canAccess()) {
            $children[] = \Filament\Navigation\NavigationItem::make(
                \Omnichannel\Addons\Social\Filament\Pages\SocialProfilesPage::getNavigationLabel()
            )
                ->parentItem($parentLabel)
                ->url(\Omnichannel\Addons\Social\Filament\Pages\SocialProfilesPage::getUrl())
                ->isActiveWhen(fn (): bool => \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::isSocialNav());
        }

        if (\Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage::canAccess()) {
            $children[] = \Filament\Navigation\NavigationItem::make(
                \Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage::getNavigationLabel()
            )
                ->parentItem($parentLabel)
                ->url(\Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage::getUrl())
                ->isActiveWhen(fn (): bool => \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::isSeedingTopicsNav());
        }

        return [
            \Filament\Navigation\NavigationItem::make($parentLabel)
                ->icon(static::getNavigationIcon())
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::isSeoModule())
                ->childItems($children),
        ];
    }

    public function mount(): void
    {
        if ($this->activeTab === 'ai-discovery') {
            $this->redirect(AiKeywordDiscovery::getUrl());

            return;
        }

        if (! in_array($this->dateRange, ['7d', '28d', '90d'], true)) {
            $this->dateRange = '28d';
        }

        if (! in_array($this->device, ['all', 'desktop', 'mobile', 'tablet'], true)) {
            $this->device = 'all';
        }

        if ($this->dataSource === '') {
            $this->dataSource = $this->dashboard->resolveDefaultDataSource($this->resolveSiteId());
        } else {
            $this->dataSource = $this->dashboard->resolveSourceOrFallback(
                $this->dataSource,
                (int) auth()->id(),
                $this->resolveSiteId(),
            );
        }

        $this->positionBucket = (string) ($this->gscQueriesTable->normalizePositionBucket($this->positionBucket) ?? '');
        $this->gscPerPage = $this->gscQueriesTable->normalizePerPage($this->gscPerPage);
        $this->gscPage = max(1, $this->gscPage);
        $this->gscChartMetric = $this->normalizeGscChartMetric($this->gscChartMetric);
        $this->gscMonth = GscMonthlyPeriod::normalize($this->gscMonth !== '' ? $this->gscMonth : null);

        $this->normalizeActiveTab();
        $this->ensureRankGroupSelected();
        $this->reconcileStaleRankRuns();
        $this->keywordSearchInput = $this->keywordSearch;
        $this->gscQuerySearchInput = $this->gscQuerySearch;
    }

    private function reconcileStaleRankRuns(): void
    {
        if (! $this->isRankProviderSource() && ! $this->isKeywordMetricsSource()) {
            return;
        }

        $groupId = (int) ($this->rankGroupId ?? 0);
        if ($groupId <= 0) {
            return;
        }

        app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordRankCheckService::class)
            ->reconcileStaleRuns($groupId, $this->dataSource);
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.performance_hub.title');
    }

    public function setDataSource(string $source): void
    {
        if (! $this->providerRegistry->isPerformanceSource($source)) {
            return;
        }

        $provider = $this->providerRegistry->resolveProviderFromSource($source);
        if ($provider !== null && $provider !== 'google_search_console') {
            if (! app(\Omnichannel\Addons\SearchIntelligence\Services\SeoProviderConnectionStatusService::class)->isConfigured((int) auth()->id(), $provider)) {
                return;
            }
        }

        $this->dataSource = $source;
        $this->normalizeActiveTab();
        if ($source !== 'gsc') {
            $this->ensureRankGroupSelected();

            return;
        }

        $this->gscPage = 1;
        $this->gscMcpPreview = null;
    }

    public function setGscMonth(string $month): void
    {
        $this->gscMonth = GscMonthlyPeriod::normalize($month);
        $this->gscPage = 1;
        $this->gscMcpPreview = null;
        $this->dispatchGscChartRefresh();
    }

    public function previousGscMonth(): void
    {
        $this->setGscMonth(GscMonthlyPeriod::previousKey($this->gscMonth));
    }

    public function nextGscMonth(): void
    {
        if (! GscMonthlyPeriod::canGoNext($this->gscMonth)) {
            return;
        }

        $this->setGscMonth(GscMonthlyPeriod::nextKey($this->gscMonth));
    }

    public function openGscMcpDrawer(): void
    {
        $this->gscMcpDrawerOpen = true;
        $this->gscMcpShowRaw = false;
        $this->loadGscMcpPreview();
    }

    public function closeGscMcpDrawer(): void
    {
        $this->gscMcpDrawerOpen = false;
        $this->gscMcpDrawerLoading = false;
        $this->gscMcpShowRaw = false;
    }

    public function loadGscMcpPreview(): void
    {
        $this->gscMcpDrawerLoading = true;
        $siteId = (int) ($this->resolveSiteId() ?? 0);
        $periodKey = GscMonthlyPeriod::normalize($this->gscMonth);

        if ($siteId <= 0) {
            $this->gscMcpPreview = [
                'status' => 'no_site',
                'period_key' => $periodKey,
                'period_label' => GscMonthlyPeriod::label($periodKey),
            ];
            $this->gscMcpDrawerLoading = false;

            return;
        }

        $period = app(McpPeriodService::class)->find(
            (int) substr($periodKey, 0, 4),
            (int) substr($periodKey, 5, 2),
        );

        $stored = null;
        if ($period !== null) {
            $stored = app(MonthlyMcpSnapshotService::class)->find($period, $siteId, McpSourceKey::Gsc);
        }

        if ($stored instanceof SeoMcpSourceSnapshot && $stored->isUsable()) {
            $payload = $stored->preparedPayload();
            $this->gscMcpPreview = [
                'status' => 'stored',
                'period_key' => $periodKey,
                'period_label' => GscMonthlyPeriod::label($periodKey),
                'generated_at' => $payload['generated_at'] ?? null,
                'source_updated_at' => $payload['source_updated_at'] ?? null,
                'source_period' => [
                    'start' => GscMonthlyPeriod::bounds($periodKey)[0],
                    'end' => GscMonthlyPeriod::bounds($periodKey)[1],
                ],
                'metrics' => $payload['metrics'] ?? [],
                'summary' => $payload['summary'] ?? [],
                'context' => $payload['context'] ?? [],
                'social_top10' => $this->buildGscSocialTop10($siteId, $periodKey, $payload),
                'raw_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'has_stored_snapshot' => true,
            ];
            $this->gscMcpDrawerLoading = false;

            return;
        }

        $built = app(GscMcpContextBuilder::class)->build($siteId, $periodKey);
        $metrics = is_array($built['metrics'] ?? null) ? $built['metrics'] : [];
        $absent = ($metrics['absent'] ?? false) === true;

        $this->gscMcpPreview = [
            'status' => $absent ? 'absent' : 'live',
            'period_key' => $periodKey,
            'period_label' => GscMonthlyPeriod::label($periodKey),
            'generated_at' => now()->toIso8601String(),
            'source_updated_at' => $built['source_updated_at'] ?? null,
            'source_period' => [
                'start' => GscMonthlyPeriod::bounds($periodKey)[0],
                'end' => GscMonthlyPeriod::bounds($periodKey)[1],
            ],
            'metrics' => $metrics,
            'summary' => is_array($built['summary'] ?? null) ? $built['summary'] : [],
            'context' => is_array($built['context'] ?? null) ? $built['context'] : [],
            'social_top10' => $absent ? ['items' => [], 'unmapped_pages' => 0, 'period_key' => $periodKey, 'excluded_no_page' => 0] : $this->buildGscSocialTop10($siteId, $periodKey, $built),
            'absent_reason' => $metrics['absent_reason'] ?? null,
            'raw_json' => (string) json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'has_stored_snapshot' => false,
        ];
        $this->gscMcpDrawerLoading = false;
    }

    public function rebuildGscMcpSnapshot(): void
    {
        if ($this->gscMcpRebuilding || ! SeoAccessControl::canMutateInSeoPanel()) {
            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return;
        }

        $periodKey = GscMonthlyPeriod::normalize($this->gscMonth);
        [$year, $month] = GscMonthlyPeriod::parse($periodKey);

        $this->gscMcpRebuilding = true;

        try {
            $period = app(McpPeriodService::class)->create($year, $month);
            app(MonthlyMcpSnapshotService::class)->capture($period, $site, McpSourceKey::Gsc->value);
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.gsc_mcp_rebuilt'))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.gsc_mcp_rebuild_failed'))
                ->body(mb_substr($exception->getMessage(), 0, 240))
                ->danger()
                ->send();
        }

        $this->gscMcpRebuilding = false;
        $this->gscMcpDrawerLoading = true;
        $this->loadGscMcpPreview();
    }

    public function toggleGscMcpRaw(): void
    {
        $this->gscMcpShowRaw = ! $this->gscMcpShowRaw;
    }

    /**
     * Deterministic Social Top 10 from selected-month GSC MCP — no AI.
     *
     * @param  array<string, mixed>  $mcpPayload
     * @return array{items: list<array<string, mixed>>, unmapped_pages: int, period_key: string, excluded_no_page: int}
     */
    private function buildGscSocialTop10(int $siteId, string $periodKey, array $mcpPayload): array
    {
        try {
            return app(GscSocialTop10Builder::class)->build($siteId, $periodKey, $mcpPayload);
        } catch (\Throwable) {
            return [
                'items' => [],
                'unmapped_pages' => 0,
                'period_key' => $periodKey,
                'excluded_no_page' => 0,
            ];
        }
    }

    public function previewGscImport(): void
    {
        if (trim($this->gscImportCsv) === '') {
            $this->gscImportPreview = null;

            return;
        }

        $service = app(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscManualImportService::class);
        $siteId = (int) ($this->resolveSiteId() ?? 0);
        $previewPropertyRef = $siteId > 0 ? 'preview:site:'.$siteId : 'preview:unscoped';
        $preview = $service->preview($this->gscImportCsv, $previewPropertyRef);

        $this->gscImportPreview = [
            'valid' => (int) ($preview->summary['valid'] ?? count($preview->validRows)),
            'invalid' => (int) ($preview->summary['invalid'] ?? count($preview->invalidRows)),
            'duplicate' => (int) ($preview->summary['duplicate'] ?? count($preview->duplicateRows)),
            'total' => (int) ($preview->summary['total'] ?? 0),
        ];
    }

    public function setRankGroup(?int $groupId): void
    {
        if ($groupId === null || $groupId <= 0) {
            $this->rankGroupId = null;

            return;
        }

        $group = $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
        if ($group === null) {
            return;
        }

        $this->rankGroupId = $groupId;
    }

    public function openGroupModal(?int $groupId = null): void
    {
        $this->groupModalLoadError = null;
        $this->groupModalSubmitting = false;
        $this->groupModalLoadToken++;

        if ($groupId !== null && $groupId > 0) {
            $this->groupModalMode = 'edit';
            $this->editingGroupId = $groupId;
            $this->groupModalLoading = true;
            $this->resetGroupForm();

            return;
        }

        $this->groupModalMode = 'create';
        $this->editingGroupId = null;
        $this->groupModalLoading = false;
        $this->resetGroupForm();
    }

    public function loadGroupModalData(int $groupId): void
    {
        $token = $this->groupModalLoadToken;

        if ($this->editingGroupId !== $groupId || $this->groupModalMode !== 'edit') {
            return;
        }

        try {
            $group = $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
            if ($group === null) {
                throw new \RuntimeException(__('seo-content-ai::filament.rank_group.not_accessible'));
            }

            $keywordsText = $group->items()
                ->with('keyword')
                ->get()
                ->map(static fn ($item): string => (string) ($item->keyword?->phrase ?? ''))
                ->filter()
                ->implode("\n");

            if ($token !== $this->groupModalLoadToken || $this->editingGroupId !== $groupId) {
                return;
            }

            $this->groupFormName = (string) $group->name;
            $this->groupFormDescription = (string) ($group->description ?? '');
            $this->groupFormCountry = (string) $group->country_code;
            $this->groupFormLanguage = (string) $group->language_code;
            $this->groupFormLocation = (string) ($group->location ?? '');
            $this->groupFormDevice = (string) $group->device;
            $this->hydrateTargetDomainFormState((string) ($group->target_domain ?? ''));
            $this->groupFormKeywordsText = $keywordsText;
            $this->groupModalLoadError = null;
            $this->groupModalLoading = false;
        } catch (\Throwable $exception) {
            if ($token !== $this->groupModalLoadToken || $this->editingGroupId !== $groupId) {
                return;
            }

            $this->groupModalLoadError = $exception->getMessage();
            $this->groupModalLoading = false;
            $this->resetGroupForm();
            $this->editingGroupId = $groupId;
        }
    }

    public function retryLoadGroupModal(): void
    {
        $groupId = (int) ($this->editingGroupId ?? 0);
        if ($groupId <= 0) {
            return;
        }

        $this->groupModalLoadError = null;
        $this->groupModalLoading = true;
        $this->groupModalLoadToken++;
        $this->resetGroupForm();
        $this->loadGroupModalData($groupId);
    }

    public function closeGroupModal(): void
    {
        $this->groupModalLoadToken++;
        $this->groupModalLoading = false;
        $this->groupModalSubmitting = false;
        $this->groupModalLoadError = null;
        $this->editingGroupId = null;
        $this->groupModalMode = 'create';
        $this->resetGroupForm();
        $this->dispatch('close-rank-group-modal');
    }

    public function saveGroupModal(): void
    {
        if ($this->groupModalLoading || $this->groupModalSubmitting || $this->groupModalLoadError !== null) {
            return;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->groupModalSubmitting = true;

        $payload = [
            'name' => $this->groupFormName,
            'description' => $this->groupFormDescription,
            'country_code' => $this->groupFormCountry,
            'language_code' => $this->groupFormLanguage,
            'location' => $this->groupFormLocation,
            'device' => $this->groupFormDevice,
            'target_domain' => $this->resolveGroupFormTargetDomain(),
            'keywords_text' => $this->groupFormKeywordsText,
        ];

        try {
            if ($this->editingGroupId !== null && $this->editingGroupId > 0) {
                $group = $this->rankGroups->findAccessibleGroup($this->editingGroupId, (int) auth()->id());
                if ($group === null) {
                    throw new \RuntimeException(__('seo-content-ai::filament.rank_group.not_accessible'));
                }

                $group = $this->rankGroups->updateGroup($group, (int) auth()->id(), $payload);
            } else {
                $group = $this->rankGroups->createGroup((int) auth()->id(), $payload);
            }
        } catch (\RuntimeException $exception) {
            $this->groupModalSubmitting = false;
            Notification::make()
                ->title(__('seo-content-ai::filament.rank_group.save_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->groupModalSubmitting = false;
        $this->rankGroupId = (int) $group->id;
        $this->closeGroupModal();

        Notification::make()
            ->title(__('seo-content-ai::filament.rank_group.saved'))
            ->success()
            ->send();
    }

    public function duplicateRankGroup(int $groupId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return;
        }

        $group = $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
        if ($group === null) {
            return;
        }

        $copy = $this->rankGroups->duplicateGroup($group, (int) auth()->id());
        $this->rankGroupId = (int) $copy->id;

        Notification::make()
            ->title(__('seo-content-ai::filament.rank_group.duplicated'))
            ->success()
            ->send();
    }

    public function archiveRankGroup(int $groupId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return;
        }

        $group = $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
        if ($group === null) {
            return;
        }

        $this->rankGroups->archiveGroup($group, (int) auth()->id());

        if ($this->rankGroupId === $groupId) {
            $this->rankGroupId = null;
            $this->ensureRankGroupSelected();
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.rank_group.archived'))
            ->success()
            ->send();
    }

    public function deleteRankGroup(int $groupId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return;
        }

        $group = $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
        if ($group === null) {
            return;
        }

        $this->rankGroups->deleteGroup($group, (int) auth()->id());

        if ($this->rankGroupId === $groupId) {
            $this->rankGroupId = null;
            $this->ensureRankGroupSelected();
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.rank_group.deleted'))
            ->success()
            ->send();
    }

    public function setRankPositionBucket(string $bucket): void
    {
        $allowed = ['1-3', '4-10', '11-20', '21-50', '51-100'];
        if (! in_array($bucket, $allowed, true)) {
            return;
        }

        $this->positionBucket = $this->positionBucket === $bucket ? '' : $bucket;
    }

    public function clearRankPositionBucket(): void
    {
        $this->positionBucket = '';
    }

    public function testSerpConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel() || ! $this->isRankProviderSource()) {
            return;
        }

        $connection = $this->serpConnections->resolveForUser((int) auth()->id(), $this->dataSource);
        if ($connection === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.serp_not_configured'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->serpConnections->testConnection($connection);
        Notification::make()
            ->title(($result['ok'] ?? false)
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body((string) ($result['message'] ?? ''))
            ->{($result['ok'] ?? false) ? 'success' : 'danger'}()
            ->send();
    }

    public function runComparisonCheck(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures() || ! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $group = $this->resolveRankGroup();
        $trackedDomain = $group?->target_domain;

        $providers = $this->comparisonProviders !== []
            ? $this->comparisonProviders
            : array_map(
                static fn (array $tab): string => (string) $tab['key'],
                $this->serpConnections->tabSourcesForUser((int) auth()->id()),
            );

        try {
            $result = $this->rankComparison->dispatchComparison(
                userId: (int) auth()->id(),
                providers: $providers,
                keywordPhrase: $this->comparisonKeyword !== '' ? $this->comparisonKeyword : null,
                country: $group?->country_code,
                location: $group?->location,
                language: $group?->language_code,
                device: $group?->device,
                trackedDomain: $trackedDomain,
            );
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.comparison_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->comparisonBatchId = (string) ($result['batch_id'] ?? '');
        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.comparison_queued'))
            ->body(__('seo-content-ai::filament.performance_hub.comparison_queued_body', [
                'count' => (int) ($result['job_count'] ?? 0),
            ]))
            ->success()
            ->send();
    }

    public function setActiveTab(string $tab): void
    {
        $allowed = $this->dataSource === 'gsc'
            ? ['queries', 'quick-wins', 'pages']
            : ['rankings', 'serp-changes'];

        if (! in_array($tab, $allowed, true)) {
            return;
        }

        if ($this->activeTab !== $tab) {
            $this->gscPage = 1;
        }

        $this->activeTab = $tab;
    }

    public function setPositionBucket(string $bucket): void
    {
        $normalized = $this->gscQueriesTable->normalizePositionBucket($bucket);
        if ($normalized === null) {
            return;
        }

        $this->positionBucket = $this->positionBucket === $normalized ? '' : $normalized;
        $this->gscPage = 1;
    }

    public function clearPositionBucket(): void
    {
        $this->positionBucket = '';
        $this->gscPage = 1;
    }

    public function gotoGscPage(int $page): void
    {
        $this->gscPage = max(1, $page);
    }

    public function setGscPerPage(int $perPage): void
    {
        $this->gscPerPage = $this->gscQueriesTable->normalizePerPage($perPage);
        $this->gscPage = 1;
    }

    public function setGscChartMetric(string $metric): void
    {
        $this->gscChartMetric = $this->normalizeGscChartMetric($metric);
        $this->dispatchGscChartRefresh();
    }

    public function applyGscQuerySearch(): void
    {
        $this->gscQuerySearch = trim($this->gscQuerySearchInput);
        $this->gscQuerySearchInput = $this->gscQuerySearch;
        $this->gscPage = 1;
    }

    public function clearGscQuerySearch(): void
    {
        $this->gscQuerySearch = '';
        $this->gscQuerySearchInput = '';
        $this->gscPage = 1;
    }

    public function applyKeywordSearch(): void
    {
        $this->keywordSearch = trim($this->keywordSearchInput);
        $this->keywordSearchInput = $this->keywordSearch;
    }

    public function clearKeywordSearch(): void
    {
        $this->keywordSearch = '';
        $this->keywordSearchInput = '';
    }

    public function updatedGscMonth(): void
    {
        $this->gscMonth = GscMonthlyPeriod::normalize($this->gscMonth);
        $this->gscPage = 1;
        $this->gscMcpPreview = null;
        $this->dispatchGscChartRefresh();
    }

    public function sortGscQueries(string $column): void
    {
        if ($this->querySortBy === $column) {
            $this->querySortDir = $this->querySortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->querySortBy = $column;
            $this->querySortDir = 'desc';
        }

        $this->gscPage = 1;
    }

    public function syncGscData(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $connectionId = (int) ($gscStatus['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            $connection = $this->gscConnection->resolveForSite($siteId);
            $connectionId = (int) ($connection?->id ?? 0);
        }

        if ($connectionId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body(__('seo-content-ai::filament.api_connections.not_configured'))
                ->warning()
                ->send();

            return;
        }

        $mapResult = $this->gscBulkSync->ensureSiteMapped($siteId, $connectionId, (int) auth()->id());
        if (! $mapResult['ok']) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body((string) ($mapResult['message'] ?? __('seo-content-ai::filament.api_connections.gsc_mapping_missing')))
                ->warning()
                ->send();

            return;
        }

        $result = $this->gscSync->syncSiteWithDetails(
            $siteId,
            (int) auth()->id(),
            GscMonthlyPeriod::normalize($this->gscMonth),
        );
        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();

        unset($this->gscDashboardState);
        $this->dispatchGscChartRefresh();
    }

    public function syncAllMappedGscDomains(): void
    {
        if ($this->isGscBulkSyncing) {
            return;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->isGscBulkSyncing = true;

        $siteId = $this->resolveSiteId();
        $gscStatus = $this->gscConnection->statusForSite($siteId);
        $connectionId = (int) ($gscStatus['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            $connection = $this->gscConnection->resolveForSite($siteId);
            $connectionId = (int) ($connection?->id ?? 0);
        }

        if ($connectionId <= 0) {
            $this->isGscBulkSyncing = false;
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_sync_failed'))
                ->body(__('seo-content-ai::filament.api_connections.not_configured'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->gscBulkSync->autoMapAndSyncAll((int) auth()->id(), $connectionId, queueSync: false);
        $this->gscBulkSyncResult = $result;
        $this->isGscBulkSyncing = false;

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_bulk_sync_complete')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'] ?? '')
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function retryGscSyncForSite(int $siteId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel() || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }

        $result = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
        if ($this->gscBulkSyncResult !== null && is_array($this->gscBulkSyncResult['rows'] ?? null)) {
            foreach ($this->gscBulkSyncResult['rows'] as $index => $row) {
                if ((int) ($row['site_id'] ?? 0) !== $siteId) {
                    continue;
                }

                $this->gscBulkSyncResult['rows'][$index]['sync_status'] = $result['ok']
                    ? (($result['query_count'] ?? 0) === 0 ? 'empty_success' : 'synced')
                    : 'failed';
                $this->gscBulkSyncResult['rows'][$index]['error'] = $result['ok'] ? null : $result['message'];
            }
        }

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function runKeywordRankCheck(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $groupId = (int) ($this->rankGroupId ?? 0);
        if ($groupId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.rank_group.select_group_first'))
                ->warning()
                ->send();

            return;
        }

        $metrics = [];
        if ($this->runMetricsRank) {
            $metrics[] = 'rank';
        }
        if ($this->runMetricsAllintitle) {
            $metrics[] = 'allintitle';
        }
        if ($this->runMetricsSearchVolume) {
            $metrics[] = 'search_volume';
        }

        if ($metrics === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.run_select_metric'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = $this->dashboard->dispatchRankCheck(
                groupId: $groupId,
                userId: (int) auth()->id(),
                source: $this->dataSource,
                metrics: $metrics,
            );
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.rank_check_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (($result['queued'] ?? false) === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.rank_check_queued'))
                ->body(__('seo-content-ai::filament.performance_hub.rank_check_queued_body', [
                    'count' => (int) ($result['keyword_count'] ?? 0),
                ]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.rank_check_failed'))
            ->warning()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function gscDashboardState(): array
    {
        if ($this->dataSource !== 'gsc') {
            return [];
        }

        return $this->dashboard->buildGscState(
            siteId: $this->resolveSiteId(),
            sortBy: $this->querySortBy,
            sortDir: $this->querySortDir,
            search: $this->gscQuerySearch,
            positionBucket: $this->positionBucket !== '' ? $this->positionBucket : null,
            page: $this->gscPage,
            perPage: $this->gscPerPage,
            chartMetric: $this->gscChartMetric,
            periodKey: GscMonthlyPeriod::normalize($this->gscMonth),
        );
    }

    #[Computed]
    public function availableSourceTabs(): array
    {
        return $this->dashboard->availableSourceTabs((int) auth()->id());
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function rankDashboardState(): array
    {
        if (! $this->isRankProviderSource()) {
            return [];
        }

        return $this->dashboard->buildRankState(
            groupId: $this->rankGroupId,
            userId: (int) auth()->id(),
            source: $this->dataSource,
            keywordSearch: $this->keywordSearch,
            positionBucket: $this->positionBucket,
            comparisonBatchId: $this->comparisonBatchId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function keywordMetricsDashboardState(): array
    {
        if (! $this->isKeywordMetricsSource()) {
            return [];
        }

        return $this->dashboard->buildKeywordMetricsState(
            groupId: $this->rankGroupId,
            userId: (int) auth()->id(),
            source: $this->dataSource,
        );
    }

    #[Computed]
    public function groupFormKeywordCount(): int
    {
        return count($this->rankGroups->parseKeywordLines($this->groupFormKeywordsText));
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function rankGroupDomainOptions(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->pluck('domain')
            ->map(static fn (mixed $domain): string => trim((string) $domain))
            ->filter(static fn (string $domain): bool => $domain !== '')
            ->values()
            ->all();
    }

    #[Computed]
    public function rankProviderCapabilities(): array
    {
        if (! $this->providerRegistry->isRawSerpSource($this->dataSource)) {
            return ['rank' => false, 'allintitle' => false, 'search_volume' => false, 'search_volume_configured' => false];
        }

        $provider = $this->providerRegistry->resolveProviderFromSource($this->dataSource) ?? $this->dataSource;

        return $this->capabilityResolver->legacyToolbarCapabilities((int) auth()->id(), $provider);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rankGroupOptions(): array
    {
        return $this->rankGroups->listOptionsForUser((int) auth()->id());
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function comparisonRows(): array
    {
        if ($this->comparisonBatchId === '') {
            return [];
        }

        return $this->comparisonResults->buildRows($this->comparisonBatchId);
    }

    public function pushQuickWinToEditor(string $phrase, string $type = Keyword::TYPE_SUGGEST): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $keyword = $this->performanceHub->pushKeywordToEditor($phrase, $siteId, $type);
        if (! $keyword instanceof Keyword) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.push_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.push_success', ['phrase' => $keyword->phrase]))
            ->success()
            ->send();
    }

    private function dispatchGscChartRefresh(): void
    {
        $this->dispatch('performance-hub-gsc-chart-refresh');
    }

    private function resetGscTableState(): void
    {
        $this->gscPage = 1;
        $this->positionBucket = '';
        $this->gscQuerySearch = '';
        $this->gscQuerySearchInput = '';
    }

    private function normalizeGscChartMetric(string $metric): string
    {
        return in_array($metric, ['clicks', 'impressions', 'ctr', 'position'], true)
            ? $metric
            : 'clicks';
    }

    private function normalizeActiveTab(): void
    {
        if ($this->dataSource === 'gsc') {
            if (! in_array($this->activeTab, ['queries', 'quick-wins', 'pages'], true)) {
                $this->activeTab = 'queries';
            }

            return;
        }

        if (! in_array($this->activeTab, ['rankings', 'serp-changes'], true)) {
            $this->activeTab = 'rankings';
        }
    }

    private function isRankProviderSource(): bool
    {
        return $this->providerRegistry->isRawSerpSource($this->dataSource);
    }

    private function isKeywordMetricsSource(): bool
    {
        return $this->dashboard->isKeywordMetricsSource($this->dataSource);
    }

    private function resolveSiteId(): ?int
    {
        return SeoAccessControl::globalSiteId();
    }

    private function resolveRankGroup(): ?SeoRankKeywordGroup
    {
        $groupId = (int) ($this->rankGroupId ?? 0);
        if ($groupId <= 0) {
            return null;
        }

        return $this->rankGroups->findAccessibleGroup($groupId, (int) auth()->id());
    }

    private function ensureRankGroupSelected(): void
    {
        if (! $this->isRankProviderSource() && ! $this->isKeywordMetricsSource()) {
            return;
        }

        if ($this->resolveRankGroup() !== null) {
            return;
        }

        $options = $this->rankGroups->listOptionsForUser((int) auth()->id());
        if ($options === []) {
            $this->rankGroupId = null;

            return;
        }

        $this->rankGroupId = (int) $options[0]['id'];
    }

    private function resetGroupForm(): void
    {
        $this->groupFormName = '';
        $this->groupFormDescription = '';
        $this->groupFormCountry = 'vn';
        $this->groupFormLanguage = 'vi';
        $this->groupFormLocation = '';
        $this->groupFormDevice = 'desktop';
        $this->groupFormTargetDomain = '';
        $this->groupFormTargetDomainChoice = '';
        $this->groupFormTargetDomainCustom = '';
        $this->groupFormKeywordsText = '';
    }

    private function hydrateTargetDomainFormState(string $storedDomain): void
    {
        $normalized = $this->rankGroups->normalizeTargetDomain($storedDomain) ?? trim($storedDomain);

        if ($normalized === null || $normalized === '') {
            $this->groupFormTargetDomainChoice = '';
            $this->groupFormTargetDomainCustom = '';
            $this->groupFormTargetDomain = '';

            return;
        }

        foreach ($this->rankGroupDomainOptions as $domain) {
            $optionNormalized = $this->rankGroups->normalizeTargetDomain($domain) ?? strtolower($domain);
            if ($optionNormalized !== null && strtolower($optionNormalized) === strtolower($normalized)) {
                $this->groupFormTargetDomainChoice = $domain;
                $this->groupFormTargetDomainCustom = '';
                $this->groupFormTargetDomain = $optionNormalized;

                return;
            }
        }

        $this->groupFormTargetDomainChoice = self::TARGET_DOMAIN_CUSTOM;
        $this->groupFormTargetDomainCustom = $normalized;
        $this->groupFormTargetDomain = $normalized;
    }

    private function resolveGroupFormTargetDomain(): ?string
    {
        if ($this->groupFormTargetDomainChoice === self::TARGET_DOMAIN_CUSTOM) {
            $custom = trim($this->groupFormTargetDomainCustom);

            return $custom !== '' ? $custom : null;
        }

        if ($this->groupFormTargetDomainChoice === '') {
            return null;
        }

        return $this->groupFormTargetDomainChoice;
    }
}
