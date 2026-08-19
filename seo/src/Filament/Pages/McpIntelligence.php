<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpAiContextBuilder;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpEligibleContentScope;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpMarkdownRenderer;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpTokenEstimator;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpUiPresenter;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpContentDistributionAggregator;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;
use Throwable;

final class McpIntelligence extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'mcp-intelligence';

    protected static string $view = 'seo-content-ai::filament.pages.mcp-intelligence';

    #[Url(as: 'site')]
    public ?int $siteId = null;

    #[Url(as: 'period')]
    public string $periodKey = '';

    public bool $showFinalizeConfirm = false;

    public string $refreshingSource = '';

    public ?string $previewSource = null;

    public int $linkedArticlesPage = 1;

    public int $linkedArticlesPerPage = 10;

    public function updatedPeriodKey(): void
    {
        $this->previewSource = null;
        $this->linkedArticlesPage = 1;
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
        return __('seo-content-ai::filament.mcp_intelligence.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.mcp_intelligence.title');
    }

    public function mount(): void
    {
        if ($this->periodKey === '' || preg_match('/^\d{4}-\d{2}$/', $this->periodKey) !== 1) {
            $this->periodKey = sprintf('%04d-%02d', (int) now()->year, (int) now()->month);
        }
        $this->resolveInitialSiteId();
    }

    #[On('domain-context-changed')]
    #[On('seoGlobalSiteChanged')]
    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        // MCP dùng domain picker cục bộ — không đồng bộ từ GlobalSeoBar.
        $this->previewSource = null;
        $this->linkedArticlesPage = 1;
    }

    public function updatedSiteId(): void
    {
        $this->previewSource = null;
        $this->linkedArticlesPage = 1;
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        return SeoAccessControl::accessibleSitesQuery()
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->mapWithKeys(static fn (Site $site): array => [(int) $site->id => (string) $site->domain])
            ->all();
    }

    /**
     * @return list<array{year: int, month: int, label: string, exists: bool, status: string, coverage: string}>
     */
    public function periodOptions(): array
    {
        $periods = app(McpPeriodService::class);
        $out = [];
        $seen = [];
        foreach ($periods->selectablePeriods() as $period) {
            $key = $period->periodKey();
            $seen[$key] = true;
            $out[] = [
                'year' => (int) $period->year,
                'month' => (int) $period->month,
                'label' => sprintf('%02d/%d', (int) $period->month, (int) $period->year),
                'exists' => $period->exists,
                'status' => (string) ($period->status ?? 'open'),
                'coverage' => $period->exists ? $period->coverageKind() : 'unknown',
            ];
        }
        $candidates = [
            [(int) now()->year, (int) now()->month],
            [(int) now()->copy()->subMonth()->year, (int) now()->copy()->subMonth()->month],
        ];
        foreach ($candidates as [$year, $month]) {
            $key = sprintf('%04d-%02d', $year, $month);
            if (isset($seen[$key])) {
                continue;
            }
            array_unshift($out, [
                'year' => $year,
                'month' => $month,
                'label' => sprintf('%02d/%d', $month, $year),
                'exists' => false,
                'status' => 'open',
                'coverage' => 'unknown',
            ]);
            $seen[$key] = true;
        }

        return $out;
    }

    public function selectedYear(): int
    {
        return (int) substr($this->periodKey, 0, 4) ?: (int) now()->year;
    }

    public function selectedMonth(): int
    {
        return (int) substr($this->periodKey, 5, 2) ?: (int) now()->month;
    }

    public function selectedPeriod(): ?SeoMcpPeriod
    {
        return app(McpPeriodService::class)->find($this->selectedYear(), $this->selectedMonth());
    }

    public function selectedSite(): ?Site
    {
        $id = (int) $this->siteId;
        if ($id <= 0 || ! SeoAccessControl::canAccessSite($id)) {
            return null;
        }

        return Site::query()->find($id);
    }

    public function createPeriod(): void
    {
        $period = app(McpPeriodService::class)->create($this->selectedYear(), $this->selectedMonth());
        Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.period_created', [
            'period' => $period->periodKey(),
        ]))->success()->send();
    }

    public function requestFinalize(): void
    {
        $period = $this->selectedPeriod();
        if (! $period instanceof SeoMcpPeriod) {
            return;
        }
        $result = app(McpPeriodService::class)->finalize($period, auth()->id(), false);
        if ($result['needs_confirmation']) {
            $this->showFinalizeConfirm = true;

            return;
        }
        $this->showFinalizeConfirm = false;
        Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.finalized'))->success()->send();
    }

    public function confirmFinalize(): void
    {
        $period = $this->selectedPeriod();
        if (! $period instanceof SeoMcpPeriod) {
            return;
        }
        app(McpPeriodService::class)->finalize($period, auth()->id(), true);
        $this->showFinalizeConfirm = false;
        Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.finalized'))->success()->send();
    }

    public function reopenPeriod(): void
    {
        $period = $this->selectedPeriod();
        if (! $period instanceof SeoMcpPeriod) {
            return;
        }
        app(McpPeriodService::class)->reopen($period);
        Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.reopened'))->success()->send();
    }

    public function generateReport(): void
    {
        $this->runGenerate(null);
    }

    public function refreshSiteSnapshot(): void
    {
        $this->runGenerate([McpSourceKey::Site->value]);
    }

    public function refreshKeywordSnapshot(): void
    {
        $this->runGenerate([McpSourceKey::Keywords->value]);
    }

    public function refreshAll(): void
    {
        $this->runGenerate(null);
    }

    public function openSourcePreview(string $source): void
    {
        $this->previewSource = $source;
    }

    public function closeDrawers(): void
    {
        $this->previewSource = null;
    }

    public function keywordsErrorUrl(): string
    {
        return KeywordResource::buildOperationalTagFilterUrl(KeywordTag::ERROR);
    }

    public function keywordsUnclusteredUrl(): string
    {
        return KeywordResource::getUrl('clusters');
    }

    public function clusterUrl(string $clusterKey): string
    {
        $key = trim($clusterKey);
        if ($key === '') {
            return KeywordResource::getUrl('clusters');
        }

        return KeywordResource::getUrl('cluster', ['clusterKey' => $key]);
    }

    public function markdownRenderer(): McpMarkdownRenderer
    {
        return app(McpMarkdownRenderer::class);
    }

    public function aiContextBuilder(): McpAiContextBuilder
    {
        return app(McpAiContextBuilder::class);
    }

    public function tokenEstimator(): McpTokenEstimator
    {
        return app(McpTokenEstimator::class);
    }

    public function presenter(): MonthlyMcpUiPresenter
    {
        return app(MonthlyMcpUiPresenter::class);
    }

    private function resolveInitialSiteId(): void
    {
        $current = (int) ($this->siteId ?? 0);
        if ($current > 0 && SeoAccessControl::canAccessSite($current)) {
            return;
        }

        $global = SeoAccessControl::globalSiteId();
        if ($global !== null && $global > 0 && SeoAccessControl::canAccessSite($global)) {
            $this->siteId = $global;

            return;
        }

        $options = $this->siteOptions();
        if ($options !== []) {
            $this->siteId = (int) array_key_first($options);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function viewState(): array
    {
        try {
            $period = $this->selectedPeriod();
            $site = $this->selectedSite();
            $report = ($period instanceof SeoMcpPeriod && $site instanceof Site)
                ? app(MonthlyMcpReportService::class)->find($period, (int) $site->id)
                : null;
            $siteSnap = ($period instanceof SeoMcpPeriod && $site instanceof Site)
                ? app(MonthlyMcpSnapshotService::class)->find($period, (int) $site->id, McpSourceKey::Site)
                : null;
            $kwSnap = ($period instanceof SeoMcpPeriod && $site instanceof Site)
                ? app(MonthlyMcpSnapshotService::class)->find($period, (int) $site->id, McpSourceKey::Keywords)
                : null;

            $siteStatus = $this->sourceCardStatus($siteSnap, $site, McpSourceKey::Site);
            $kwStatus = $this->sourceCardStatus($kwSnap, $site, McpSourceKey::Keywords);
            $readyCount = (($siteSnap?->isUsable() ?? false) ? 1 : 0) + (($kwSnap?->isUsable() ?? false) ? 1 : 0);
            $changedAfterFinalize = false;
            if ($period?->isFinalized() && $site instanceof Site) {
                $changedAfterFinalize = in_array($siteStatus['freshness'], ['stale'], true)
                    || in_array($kwStatus['freshness'], ['stale'], true);
            }

            $aiContext = is_array($report?->ai_context_json) ? $report->ai_context_json : [];
            $siteId = (int) ($site?->id ?? 0);
            $markdown = $siteId > 0
                ? $this->markdownBundle($siteId, $this->periodKey)
                : [
                    'site' => '',
                    'keywords' => '',
                    'combined' => '',
                    'ai_context' => '',
                    'preview' => ['site' => '', 'keywords' => '', 'combined' => '', 'ai_context' => ''],
                    'tokens' => ['characters' => 0, 'estimated_tokens' => 0],
                    'updated_at' => null,
                ];

            $siteSummary = is_array($siteSnap?->summary_json) ? $siteSnap->summary_json : [];
            if ($site instanceof Site) {
                $liveDistribution = app(SiteMcpContentDistributionAggregator::class)->aggregate((int) $site->id);
                if (($liveDistribution['available'] ?? false) === true) {
                    $siteSummary['content_distribution'] = $liveDistribution;
                }
            }
            $linkedTotalInt = $siteId > 0 ? $this->linkedArticlesTotalEligible($siteId) : null;
            $linkedRows = [];
            if ($siteId > 0 && $linkedTotalInt !== null) {
                $linkedRows = $this->linkedArticlesPageRows($siteId, $this->linkedArticlesPage, $this->linkedArticlesPerPage);
            }

            return [
                'period' => $period,
                'site' => $site,
                'report' => $report,
                'site_snap' => $siteSnap,
                'keyword_snap' => $kwSnap,
                'site_card' => $siteStatus,
                'keyword_card' => $kwStatus,
                'site_summary' => $siteSummary,
                'source_ready' => $readyCount,
                'source_total' => 2,
                'changed_after_finalize' => $changedAfterFinalize,
                'markdown' => $markdown,
                'ai_json' => $aiContext === [] ? '' : (string) json_encode($aiContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'site_preview_json' => $siteSnap instanceof SeoMcpSourceSnapshot
                    ? (string) json_encode($siteSnap->preparedPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    : '',
                'keyword_preview_json' => $kwSnap instanceof SeoMcpSourceSnapshot
                    ? (string) json_encode($kwSnap->preparedPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    : '',
                'coverage' => $period instanceof SeoMcpPeriod
                    ? [
                        'kind' => $period->coverageKind(),
                        'available' => (int) $period->available_sites,
                        'expected' => (int) $period->expected_sites,
                    ]
                    : ['kind' => 'unknown', 'available' => 0, 'expected' => 0],
                'linked_articles_total' => $linkedTotalInt,
                'linked_articles_rows' => $linkedRows,
                'load_error' => false,
            ];
        } catch (Throwable) {
            return [
                'period' => null,
                'site' => null,
                'report' => null,
                'site_snap' => null,
                'keyword_snap' => null,
                'site_card' => ['freshness' => 'failed', 'relative' => null, 'metrics' => [], 'failed' => true],
                'keyword_card' => ['freshness' => 'failed', 'relative' => null, 'metrics' => [], 'failed' => true],
                'site_summary' => [],
                'source_ready' => 0,
                'source_total' => 2,
                'changed_after_finalize' => false,
                'markdown' => [
                    'site' => '',
                    'keywords' => '',
                    'combined' => '',
                    'ai_context' => '',
                    'preview' => ['site' => '', 'keywords' => '', 'combined' => '', 'ai_context' => ''],
                    'tokens' => ['characters' => 0, 'estimated_tokens' => 0],
                    'updated_at' => null,
                ],
                'ai_json' => '',
                'site_preview_json' => '',
                'keyword_preview_json' => '',
                'coverage' => ['kind' => 'unknown', 'available' => 0, 'expected' => 0],
                'linked_articles_total' => null,
                'linked_articles_rows' => [],
                'load_error' => true,
            ];
        }
    }

    /**
     * @return list<array{
     *   id: int,
     *   title: string,
     *   internal_links: int,
     *   updated_at: ?string,
     *   status: string
     * }>
     */
    private function linkedArticlesPageRows(int $siteId, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            $page = 1;
            $perPage = 10;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return [];
        }

        $eligibleIds = $this->eligibleInternalLinkTargetArticleIds($siteId);
        if ($eligibleIds === []) {
            return [];
        }

        $counts = SeoLinkMap::query()
            ->selectRaw('seo_link_maps.target_article_id as target_article_id')
            ->selectRaw('COUNT(*) as internal_links')
            ->where('seo_link_maps.link_type', SeoLinkMapType::Internal)
            ->where('seo_link_maps.status', '!=', SeoLinkMapStatus::Ignored)
            ->whereNotNull('seo_link_maps.target_article_id')
            ->whereIn('seo_link_maps.target_article_id', $eligibleIds)
            ->whereHas('sourceArticle', static function (Builder $query) use ($siteId): void {
                $query->where('site_id', $siteId)
                    ->where('status', '!=', 'trash')
                    ->whereNull('deleted_at');
            })
            ->groupBy('seo_link_maps.target_article_id');

        $offset = ($page - 1) * $perPage;
        $rows = SeoArticle::query()
            ->select([
                'articles.id',
                'articles.title',
                'articles.updated_at',
                'articles.status',
                DB::raw('counts.internal_links as internal_links'),
            ])
            ->joinSub($counts, 'counts', static function ($join): void {
                $join->on('counts.target_article_id', '=', 'articles.id');
            })
            ->where('articles.site_id', $siteId)
            ->whereIn('articles.id', $eligibleIds)
            ->orderByDesc('internal_links')
            ->orderByDesc('articles.updated_at')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row->id ?? 0),
                'title' => (string) ($row->title ?? ('Article #'.($row->id ?? ''))),
                'internal_links' => (int) ($row->internal_links ?? 0),
                'updated_at' => $row->updated_at?->toIso8601String(),
                'status' => (string) ($row->status ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{start: int, end: int}
     */
    public static function linkedArticlesRangeMeta(int $total, int $page, int $perPage): array
    {
        $safeTotal = max(0, $total);
        if ($safeTotal === 0) {
            return ['start' => 0, 'end' => 0];
        }

        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);

        $start = ($safePage - 1) * $safePerPage + 1;
        $end = min($safeTotal, $safePage * $safePerPage);

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{first: int, last: int}
     */
    public static function linkedArticlesPageWindow(int $currentPage, int $totalPages, int $radius = 2): array
    {
        $safeTotal = max(1, $totalPages);
        $safeCurrent = max(1, min($currentPage, $safeTotal));
        $safeRadius = max(0, $radius);

        return [
            'first' => max(1, $safeCurrent - $safeRadius),
            'last' => min($safeTotal, $safeCurrent + $safeRadius),
        ];
    }

    /**
     * @template T
     * @param  list<T>  $clusters
     * @return list<T>
     */
    public static function topClusters(array $clusters, int $limit): array
    {
        return array_slice($clusters, 0, max(0, $limit));
    }

    /**
     * @return list<int>
     */
    private function eligibleInternalLinkTargetArticleIds(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        $query = SeoArticle::query()->where('site_id', $siteId);
        $query = McpEligibleContentScope::applyToSeoArticleTarget($query);

        return $query
            ->countsTowardSeoScore()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return int|null
     */
    private function linkedArticlesTotalEligible(int $siteId): ?int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return null;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return null;
        }

        $eligibleIds = $this->eligibleInternalLinkTargetArticleIds($siteId);
        if ($eligibleIds === []) {
            // Fully computable, but no eligible target entities in this tenant.
            return 0;
        }

        $mapsCount = SeoLinkMap::query()
            ->where('link_type', SeoLinkMapType::Internal)
            ->where('status', '!=', SeoLinkMapStatus::Ignored)
            ->whereNotNull('target_article_id')
            ->whereIn('target_article_id', $eligibleIds)
            ->whereHas('sourceArticle', static function (Builder $query) use ($siteId): void {
                $query->where('site_id', $siteId)
                    ->where('status', '!=', 'trash')
                    ->whereNull('deleted_at');
            })
            ->distinct()
            ->count('target_article_id');

        return (int) $mapsCount;
    }

    /**
     * @return array{
     *   site: string,
     *   keywords: string,
     *   combined: string,
     *   ai_context: string,
     *   preview: array{site: string, keywords: string, combined: string, ai_context: string},
     *   tokens: array{characters: int, estimated_tokens: int},
     *   updated_at: ?string
     * }
     */
    private function markdownBundle(int $siteId, string $periodKey): array
    {
        $renderer = $this->markdownRenderer();
        $builder = $this->aiContextBuilder();
        $converter = app(SimpleMarkdownHtmlConverter::class);
        $site = $renderer->renderSite($siteId, $periodKey);
        $keywords = $renderer->renderKeywords($siteId, $periodKey);
        $combined = $renderer->renderCombined($siteId, $periodKey);
        $aiContext = $builder->build($siteId, $periodKey);
        $tokens = $this->tokenEstimator()->estimate($aiContext);
        $period = $this->selectedPeriod();
        $report = ($period instanceof SeoMcpPeriod)
            ? app(MonthlyMcpReportService::class)->find($period, $siteId)
            : null;

        return [
            'site' => $site,
            'keywords' => $keywords,
            'combined' => $combined,
            'ai_context' => $aiContext,
            'preview' => [
                'site' => $converter->toHtml($site),
                'keywords' => $converter->toHtml($keywords),
                'combined' => $converter->toHtml($combined),
                'ai_context' => $converter->toHtml($aiContext),
            ],
            'tokens' => $tokens,
            'updated_at' => $report?->generated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>|null  $keys
     */
    private function runGenerate(?array $keys): void
    {
        $site = $this->selectedSite();
        $periods = app(McpPeriodService::class);
        $period = $this->selectedPeriod() ?? $periods->create($this->selectedYear(), $this->selectedMonth());
        if (! $site instanceof Site) {
            Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.pick_site'))->danger()->send();

            return;
        }
        $this->refreshingSource = $keys[0] ?? 'all';
        try {
            app(MonthlyMcpReportService::class)->generate($period, $site, $keys);
            Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.updated'))->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('seo-content-ai::filament.mcp_intelligence.update_failed'))->body($e->getMessage())->danger()->send();
        } finally {
            $this->refreshingSource = '';
        }
    }

    /**
     * @return array{freshness: string, relative: ?string, metrics: array<string, mixed>, failed: bool}
     */
    private function sourceCardStatus(?SeoMcpSourceSnapshot $snap, ?Site $site, McpSourceKey $key): array
    {
        if (! $snap instanceof SeoMcpSourceSnapshot) {
            return ['freshness' => 'missing', 'relative' => null, 'metrics' => [], 'failed' => false];
        }
        $freshness = $site instanceof Site
            ? app(MonthlyMcpSnapshotService::class)->displayStatus($snap, $site)
            : (string) $snap->status;
        $liveAt = $site instanceof Site
            ? app(MonthlyMcpSnapshotService::class)->find($snap->period, (int) $site->id, $key)?->source_updated_at?->toIso8601String()
            : $snap->source_updated_at?->toIso8601String();

        return [
            'freshness' => $freshness,
            'relative' => $site instanceof Site
                ? $this->presenter()->humanWhen($snap->source_updated_at?->toIso8601String() ?? $liveAt)
                : MonthlyMcpFreshness::relative($snap->source_updated_at?->toIso8601String() ?? $liveAt),
            'absolute' => SystemDateTime::formatDateTime($snap->source_updated_at?->toIso8601String() ?? $liveAt),
            'metrics' => is_array($snap->metrics_json) ? $snap->metrics_json : [],
            'failed' => $freshness === 'failed',
            'generated_at' => $snap->generated_at?->toIso8601String(),
            'hash' => (string) ($snap->content_hash ?? ''),
            'schema' => $key->schema(),
            'error' => (string) ($snap->error_message ?? ''),
        ];
    }
}
