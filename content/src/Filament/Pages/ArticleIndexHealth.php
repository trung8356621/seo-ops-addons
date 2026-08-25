<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Pages;

use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionBindingResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionService;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthQueryService;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Articles → Index Health — manual monthly re-check + optional GSC URL Inspection.
 */
final class ArticleIndexHealth extends SeoPanelPage
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'articles/index-health';

    protected static string $view = 'seo-content-ai::filament.pages.article-index-health';

    protected static ?int $navigationSort = 6;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'tab')]
    public string $tab = 'needs_review';

    #[Url(as: 'site')]
    public ?int $filterSiteId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'post_type')]
    public ?string $filterPostType = null;

    #[Url(as: 'focus')]
    public ?int $focusArticleId = null;

    /** @var list<int> */
    public array $selectedArticleIds = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $historyByArticle = [];

    /** @var array<string, mixed>|null */
    public ?array $activeInspectionRun = null;

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.index_health.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.index_health.title');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ArticleResource::canViewAny();
    }

    public function mount(): void
    {
        abort_unless(ArticleResource::canViewAny(), 403);
        if ($this->filterSiteId === null) {
            $global = SeoAccessControl::globalSiteId();
            $this->filterSiteId = ($global !== null && $global > 0) ? $global : null;
        }
        $this->refreshActiveRun();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->selectedArticleIds = [];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSiteId(): void
    {
        $this->resetPage();
        $this->refreshActiveRun();
    }

    /**
     * @return array<string, int>
     */
    public function getSummaryProperty(): array
    {
        return app(ArticleIndexHealthQueryService::class)->summary($this->filters());
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getRowsProperty()
    {
        return app(ArticleIndexHealthQueryService::class)->paginate($this->filters() + [
            'per_page' => 25,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function getSiteOptionsProperty(): array
    {
        $query = Site::query()->orderBy('domain');
        SeoAccessControl::applyAccessibleSiteScope($query, 'id');

        return $query
            ->pluck('domain', 'id')
            ->mapWithKeys(static fn ($domain, $id): array => [(int) $id => (string) $domain])
            ->all();
    }

    public function getGscConfiguredProperty(): bool
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            return false;
        }

        return app(GscUrlInspectionBindingResolver::class)->hasBinding($siteId);
    }

    public function getGscConfigureUrlProperty(): string
    {
        try {
            return AiConnectionResource::getUrl('index');
        } catch (Throwable) {
            return '/seo/settings/api';
        }
    }

    public function markIndexed(int $articleId): void
    {
        $this->recordOne($articleId, ArticleIndexCheckStatus::Indexed);
    }

    public function markNotIndexed(int $articleId): void
    {
        $this->recordOne($articleId, ArticleIndexCheckStatus::NotIndexed);
    }

    public function markUnsure(int $articleId): void
    {
        $this->recordOne($articleId, ArticleIndexCheckStatus::Unknown);
    }

    public function bulkMarkIndexed(): void
    {
        $this->recordBulk(ArticleIndexCheckStatus::Indexed);
    }

    public function bulkMarkNotIndexed(): void
    {
        $this->recordBulk(ArticleIndexCheckStatus::NotIndexed);
    }

    public function inspectWithGsc(int $articleId): void
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle || ! SeoAccessControl::canAccessArticle($article)) {
            Notification::make()->danger()->title('Access denied')->send();

            return;
        }

        $siteId = (int) ($article->site_id ?? 0);
        if (! app(GscUrlInspectionBindingResolver::class)->hasBinding($siteId)) {
            Notification::make()
                ->warning()
                ->title(__('seo-content-ai::filament.index_health.gsc_not_configured'))
                ->send();

            return;
        }

        try {
            $result = app(GscUrlInspectionService::class)->inspectArticle(
                $articleId,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
            if (! ($result['ok'] ?? false)) {
                Notification::make()
                    ->danger()
                    ->title((string) ($result['error_message'] ?? __('seo-content-ai::filament.index_health.gsc_inspect_failed')))
                    ->send();

                return;
            }

            Notification::make()
                ->success()
                ->title(__('seo-content-ai::filament.index_health.gsc_recorded', [
                    'status' => (string) ($result['effective_health'] ?? $result['check_status'] ?? ''),
                ]))
                ->send();
            unset($this->historyByArticle[$articleId]);
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function inspectSelectedWithGsc(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            Notification::make()->warning()->title(__('seo-content-ai::filament.index_health.site_required'))->send();

            return;
        }

        if (! app(GscUrlInspectionBindingResolver::class)->hasBinding($siteId)) {
            Notification::make()
                ->warning()
                ->title(__('seo-content-ai::filament.index_health.gsc_not_configured'))
                ->send();

            return;
        }

        $ids = array_values(array_filter(array_map('intval', $this->selectedArticleIds)));
        if ($ids === []) {
            Notification::make()->warning()->title(__('seo-content-ai::filament.index_health.select_required'))->send();

            return;
        }

        try {
            $result = app(GscUrlInspectionRunService::class)->queueForArticles(
                $siteId,
                $ids,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
            $this->notifyRunQueued($result);
            $this->selectedArticleIds = [];
            $this->refreshActiveRun();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function inspectDueWithGsc(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            Notification::make()->warning()->title(__('seo-content-ai::filament.index_health.site_required'))->send();

            return;
        }

        if (! app(GscUrlInspectionBindingResolver::class)->hasBinding($siteId)) {
            Notification::make()
                ->warning()
                ->title(__('seo-content-ai::filament.index_health.gsc_not_configured'))
                ->send();

            return;
        }

        try {
            $result = app(GscUrlInspectionRunService::class)->queueDue(
                $siteId,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
            $this->notifyRunQueued($result);
            $this->refreshActiveRun();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function refreshActiveRun(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0) {
            $this->activeInspectionRun = null;

            return;
        }

        $this->activeInspectionRun = app(GscUrlInspectionRunService::class)->latestActiveRunForSite($siteId);
    }

    public function toggleHistory(int $articleId): void
    {
        if (isset($this->historyByArticle[$articleId])) {
            unset($this->historyByArticle[$articleId]);

            return;
        }

        $this->historyByArticle[$articleId] = app(ArticleIndexHealthQueryService::class)->history($articleId);
    }

    /**
     * @return array{site_id: int|null, search: string, tab: string, post_type: string|null}
     */
    private function filters(): array
    {
        return [
            'site_id' => $this->filterSiteId,
            'search' => $this->search,
            'tab' => $this->tab !== '' ? $this->tab : 'needs_review',
            'post_type' => $this->filterPostType,
        ];
    }

    private function recordOne(int $articleId, ArticleIndexCheckStatus $status): void
    {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle || ! SeoAccessControl::canAccessArticle($article)) {
            Notification::make()->danger()->title('Access denied')->send();

            return;
        }

        try {
            $result = app(ArticleIndexHealthRecorder::class)->record(
                $article,
                $status,
                'manual',
                auth()->id() !== null ? (int) auth()->id() : null,
            );
            Notification::make()
                ->success()
                ->title(__('seo-content-ai::filament.index_health.recorded', [
                    'status' => $result['effective_health'],
                ]))
                ->send();
            unset($this->historyByArticle[$articleId]);
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    private function recordBulk(ArticleIndexCheckStatus $status): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selectedArticleIds)));
        if ($ids === []) {
            Notification::make()->warning()->title(__('seo-content-ai::filament.index_health.select_required'))->send();

            return;
        }

        $result = app(ArticleIndexHealthRecorder::class)->recordBulk(
            $ids,
            $status,
            'manual',
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        Notification::make()
            ->success()
            ->title(__('seo-content-ai::filament.index_health.bulk_recorded', [
                'count' => (int) $result['recorded'],
            ]))
            ->send();

        $this->selectedArticleIds = [];
        $this->historyByArticle = [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyRunQueued(array $result): void
    {
        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->danger()
                ->title((string) ($result['error_message'] ?? __('seo-content-ai::filament.index_health.gsc_inspect_failed')))
                ->send();

            return;
        }

        if ($result['queued'] ?? false) {
            Notification::make()
                ->success()
                ->title(__('seo-content-ai::filament.index_health.gsc_queued', [
                    'count' => (int) ($result['requested'] ?? 0),
                ]))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('seo-content-ai::filament.index_health.gsc_batch_done', [
                'inspected' => (int) ($result['inspected'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ]))
            ->send();
    }
}
