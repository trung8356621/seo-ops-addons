<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpPeriodService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpFreshness;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpReportService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSnapshotService;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpUiPresenter;
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

    public bool $showAiContext = false;

    public string $aiContextView = 'readable';

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
        $this->syncSiteFromGlobalContext();
    }

    public function onDomainContextChanged(mixed $domain = null, mixed $siteId = null): void
    {
        parent::onDomainContextChanged($domain, $siteId);
        $this->syncSiteFromGlobalContext(is_numeric($siteId) ? (int) $siteId : null);
        $this->previewSource = null;
        $this->showAiContext = false;
        $this->aiContextView = 'readable';
    }

    public function updatedSiteId(): void
    {
        $this->previewSource = null;
        $this->showAiContext = false;
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

    public function openAiContext(): void
    {
        $this->showAiContext = true;
        $this->aiContextView = 'readable';
    }

    public function openSourcePreview(string $source): void
    {
        $this->previewSource = $source;
    }

    public function closeDrawers(): void
    {
        $this->showAiContext = false;
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

    public function presenter(): MonthlyMcpUiPresenter
    {
        return app(MonthlyMcpUiPresenter::class);
    }

    private function syncSiteFromGlobalContext(?int $siteId = null): void
    {
        $resolved = $siteId !== null && $siteId > 0 ? $siteId : SeoAccessControl::globalSiteId();
        $this->siteId = ($resolved !== null && $resolved > 0) ? $resolved : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewState(): array
    {
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
        $presenter = $this->presenter();
        $readable = $presenter->readableSections($aiContext);

        return [
            'period' => $period,
            'site' => $site,
            'report' => $report,
            'site_snap' => $siteSnap,
            'keyword_snap' => $kwSnap,
            'site_card' => $siteStatus,
            'keyword_card' => $kwStatus,
            'source_ready' => $readyCount,
            'source_total' => 2,
            'changed_after_finalize' => $changedAfterFinalize,
            'ai_readable' => $readable,
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
