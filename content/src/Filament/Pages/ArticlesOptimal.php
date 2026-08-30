<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\Seo\Services\SeoAuditKeywordFlagService;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

/**
 * Legacy Article SEO audit page — kept for compatibility/tests.
 * Primary UX cut over to ContentProjectSeoAuditPlanner; mount redirects.
 */
final class ArticlesOptimal extends SeoPanelPage
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Article SEO audit';

    protected static ?string $title = 'Article SEO audit';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Articles';

    protected static ?int $navigationSort = 6;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'articles/optimal';

    protected static string $view = 'seo-content-ai::filament.pages.articles-optimal';

    #[Url(as: 'site')]
    public ?int $filterSiteId = null;

    /** @var list<string> */
    #[Url(as: 'rules')]
    public array $selectedScoringRuleKeys = [];

    #[Url(as: 'low')]
    public bool $filterLowSeoScore = false;

    #[Url(as: 'tech')]
    public bool $filterTechnicalSeoScore = false;

    #[Url(as: 'lang')]
    public ?string $filterLanguage = null;

    #[Url(as: 'post_type')]
    public ?string $filterPostType = null;

    #[Url(as: 'scan')]
    public bool $hasScanned = false;

    #[Url(as: 'sort')]
    public ?string $resultsSortBy = null;

    #[Url(as: 'dir')]
    public string $resultsSortDir = 'asc';

    public string $scanState = 'idle';

    public ?string $scanError = null;

    public ?string $scanNotice = null;

    /** @var array<string, int>|null */
    public ?array $cacheStatusCounts = null;

    /** @var array<int, int> */
    public array $selectedArticleIds = [];

    public bool $scanning = false;

    public bool $defaultLoading = false;

    public static function canAccess(array $parameters = []): bool
    {
        return ArticleResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.articles_optimal.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.articles_optimal.navigation');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public function updatedFilterSiteId(): void
    {
        $this->filterSiteId = $this->validatedFilterSiteId(throw: false);
        $this->invalidateScanResults();
    }

    public function updatedFilterLanguage(): void
    {
        $this->invalidateScanResults();
    }

    public function updatedFilterPostType(): void
    {
        $this->invalidateScanResults();
    }

    public function updatedSelectedScoringRuleKeys(): void
    {
        if ($this->scanState === 'scanning') {
            return;
        }

        $this->selectedScoringRuleKeys = array_values(array_filter(
            $this->selectedScoringRuleKeys,
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        $this->invalidateScanResults();
    }

    public function updatedFilterLowSeoScore(): void
    {
        if ($this->scanState === 'scanning') {
            return;
        }

        $this->invalidateScanResults();
    }

    public function updatedFilterTechnicalSeoScore(): void
    {
        if ($this->scanState === 'scanning') {
            return;
        }

        $this->invalidateScanResults();
    }

    /**
     * @return array<int, string>
     */
    public function getSiteFilterOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function getLanguageOptions(): array
    {
        $languages = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()->where('status', '!=', 'trash'),
        )
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language')
            ->filter()
            ->all();

        $options = [];
        foreach ($languages as $lang) {
            $code = \Omnichannel\Addons\Content\Support\ArticleLanguageCode::normalize((string) $lang);
            if ($code === '') {
                continue;
            }
            $options[$code] = \Omnichannel\Addons\Content\Support\ArticleLanguageCode::label($code);
        }

        return $options;
    }

    /**
     * Union post_type hiện có trên site (hoặc mọi site trong scope).
     *
     * @return array<string, string>
     */
    public function getPostTypeOptions(): array
    {
        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()->where('status', '!=', 'trash'),
        );

        $siteId = $this->validatedFilterSiteId(throw: false);

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = array_map('intval', array_keys($this->getSiteFilterOptions()));
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        $types = $query
            ->whereHas('articleMetas', static function (Builder $meta): void {
                $meta->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE)
                    ->whereIn('meta_value', ContentType::values());
            })
            ->with(['articleMetas' => static fn ($q) => $q->where('meta_key', ArticleContentClassification::META_CONTENT_TYPE)])
            ->limit(500)
            ->get()
            ->map(static fn (SeoArticle $article): string => ArticleContentClassification::for($article)->contentType()->value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $options = [];
        foreach ($types as $type) {
            $options[$type] = $type;
        }

        return $options;
    }

    /**
     * @return list<array{key: string, label: string, category: string}>
     */
    public function getScoringRuleFilterDefinitions(): array
    {
        return app(SeoScoringSettingsService::class)->auditFilterDefinitions(
            app(SeoPromptSettingsService::class)->resolveArticleLengthTarget('article'),
        );
    }

    /**
     * @return list<array{key: string, label: string, threshold: int}>
     */
    public function getAggregateFilterDefinitions(): array
    {
        return app(SeoScoringSettingsService::class)->aggregateFilterDefinitions();
    }

    public function runScan(): void
    {
        if ($this->scanState === 'scanning') {
            return;
        }

        $this->scanState = 'scanning';
        $this->scanError = null;
        $this->scanNotice = null;
        $this->scanning = true;

        try {
            $this->filterSiteId = $this->validatedFilterSiteId();

            $scanService = app(SeoAuditScanService::class);
            $this->cacheStatusCounts = $scanService->cacheStatusCounts($this->baseArticleQuery());

            $total = (int) ($this->cacheStatusCounts['total'] ?? 0);
            $analyzed = (int) ($this->cacheStatusCounts['analyzed'] ?? 0);
            $pending = (int) ($this->cacheStatusCounts['pending'] ?? 0);
            $processing = (int) ($this->cacheStatusCounts['processing'] ?? 0);
            $remaining = (int) ($this->cacheStatusCounts['remaining'] ?? 0);

            if ($total > 0 && $analyzed === 0) {
                $this->hasScanned = true;
                $this->scanState = 'empty';
                $this->scanNotice = __('seo-content-ai::filament.articles_optimal.scan_no_cached_data', [
                    'pending' => $pending + $processing + $remaining,
                ]);

                return;
            }

            $filteredTotal = $scanService->buildFilteredQuery(
                $this->baseArticleQuery(),
                $this->selectedScoringRuleKeys,
                $this->filterLowSeoScore,
                $this->filterTechnicalSeoScore,
            )->count();

            $this->hasScanned = true;
            $this->resetPage();
            unset($this->resultsPaginator);

            if ($filteredTotal === 0) {
                $this->scanState = 'empty';
            } else {
                $this->scanState = 'completed';
            }

            if ($pending > 0 || $processing > 0 || $remaining > 0) {
                $this->scanNotice = __('seo-content-ai::filament.articles_optimal.scan_partial_cache_notice', [
                    'pending' => $pending + $remaining,
                    'processing' => $processing,
                ]);
            }
        } catch (Throwable $exception) {
            $this->scanState = 'failed';
            $this->scanError = $exception instanceof ValidationException
                ? (string) ($exception->errors()['domain_ids'][0] ?? __('seo-content-ai::filament.articles_optimal.domain_required'))
                : __('seo-content-ai::filament.articles_optimal.scan_failed');
            $this->hasScanned = false;
            $this->cacheStatusCounts = null;

            if (! $exception instanceof ValidationException) {
                report($exception);
            }
        } finally {
            $this->scanning = false;
        }
    }

    public function mount(): void
    {
        $siteId = $this->filterSiteId;
        if ($siteId === null || $siteId <= 0) {
            $querySite = (int) request()->query('site', 0);
            $siteId = $querySite > 0 ? $querySite : null;
        }

        $params = [];
        if ($siteId !== null && $siteId > 0) {
            $params['site'] = $siteId;
        }

        $this->redirect(ContentProjectSeoAuditPlanner::getUrl($params), navigate: false);
    }

    public function loadDefaultAuditResults(): void
    {
        if ($this->defaultLoading) {
            return;
        }

        $this->defaultLoading = true;
        $this->scanError = null;

        try {
            $scanService = app(SeoAuditScanService::class);
            $this->cacheStatusCounts = $scanService->cacheStatusCounts($this->baseArticleQuery());

            $keywordFlaggedTotal = app(SeoAuditKeywordFlagService::class)
                ->applyKeywordFlagScope($this->baseArticleQuery())
                ->count();

            $this->hasScanned = true;
            $this->scanState = $keywordFlaggedTotal > 0 ? 'completed' : 'empty';
            unset($this->resultsPaginator);
        } catch (Throwable $exception) {
            report($exception);
            $this->scanState = 'failed';
            $this->scanError = __('seo-content-ai::filament.articles_optimal.scan_failed');
            $this->hasScanned = false;
        } finally {
            $this->defaultLoading = false;
        }
    }

    public function notifyAssignBlockedMissingKeyword(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
            ->body(__('seo-content-ai::filament.articles_optimal.assign_missing_keyword_bulk'))
            ->warning()
            ->send();

        $this->skipRender();
    }

    public function queueMissingScoringForFilterSite(): void
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.queue_missing_scoring'))
                ->body(__('seo-content-ai::filament.articles_optimal.scan_no_accessible_sites'))
                ->warning()
                ->send();

            return;
        }

        $result = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->queueMissingForSite($siteId);

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.queue_missing_scoring'))
            ->body(__('seo-content-ai::filament.articles_optimal.queue_missing_scoring_success', [
                'count' => $result['queued'],
            ]))
            ->success()
            ->send();
    }

    public function skipSeoAudit(int $articleId): void
    {
        $skipped = $this->skipSeoAuditForIds([$articleId]);
        if ($skipped === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.skip_audit_failed'))
                ->danger()
                ->send();
            $this->skipRender();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.skip_audit_success'))
            ->success()
            ->send();
        $this->skipRender();
    }

    /**
     * @param  array<int, int|string>|null  $articleIds  Ưu tiên IDs từ Alpine (tránh race entangle).
     */
    public function skipSelectedSeoAudit(mixed $articleIds = null): void
    {
        $rawIds = is_array($articleIds) ? $articleIds : $this->selectedArticleIds;
        $ids = array_values(array_unique(array_map('intval', $rawIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.skip_audit_none_selected'))
                ->warning()
                ->send();
            $this->skipRender();

            return;
        }

        $skipped = $this->skipSeoAuditForIds($ids);
        $this->selectedArticleIds = [];

        if ($skipped === 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.skip_audit_failed'))
                ->danger()
                ->send();
            $this->skipRender();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.skip_audit_bulk_success', ['count' => $skipped]))
            ->success()
            ->send();
        $this->skipRender();
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function skipSeoAuditForIds(array $articleIds): int
    {
        $skipped = 0;

        foreach ($articleIds as $articleId) {
            $article = $this->findAccessibleArticle((int) $articleId);
            if ($article === null) {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => ArticleResource::META_SKIP_SEO_AUDIT],
                ['meta_value' => '1'],
            );
            $skipped++;
        }

        return $skipped;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function resultsPaginator(): LengthAwarePaginator
    {
        return $this->getResultsPaginator();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function getResultsPaginator(): LengthAwarePaginator
    {
        if (! $this->hasScanned) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
        }

        return app(SeoAuditKeywordFlagService::class)->paginateMergedResults(
            $this->baseArticleQuery(),
            $this->selectedScoringRuleKeys,
            $this->filterLowSeoScore,
            $this->filterTechnicalSeoScore,
            max(1, (int) $this->getPage()),
            15,
            $this->normalizedResultsSortBy(),
            $this->normalizedResultsSortDir(),
        );
    }

    public function sortResultsByScore(): void
    {
        if ($this->resultsSortBy === 'score') {
            $this->resultsSortDir = $this->resultsSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->resultsSortBy = 'score';
            $this->resultsSortDir = 'asc';
        }

        $this->resetPage();
        unset($this->resultsPaginator);
    }

    private function normalizedResultsSortBy(): ?string
    {
        return $this->resultsSortBy === 'score' ? 'score' : null;
    }

    private function normalizedResultsSortDir(): string
    {
        return strtolower($this->resultsSortDir) === 'desc' ? 'desc' : 'asc';
    }

    private function invalidateScanResults(): void
    {
        $this->hasScanned = false;
        $this->cacheStatusCounts = null;
        $this->scanState = 'idle';
        $this->scanError = null;
        $this->scanNotice = null;
        unset($this->resultsPaginator);
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function baseArticleQuery(): Builder
    {
        $siteId = $this->validatedFilterSiteId();

        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()
                ->countsTowardSeoScore()
                ->where('status', '!=', 'trash')
                ->orderByDesc('updated_at'),
        );

        ArticleResource::applySeoAuditCandidateScope($query);

        $query->where('articles.site_id', $siteId);

        if ($this->filterLanguage !== null && $this->filterLanguage !== '') {
            $query->where('language', $this->filterLanguage);
        }

        $postType = trim((string) ($this->filterPostType ?? ''));
        if ($postType !== '') {
            $mapped = match ($postType) {
                'article' => ContentType::Post,
                default => ContentType::tryFromString($postType),
            };
            if ($mapped !== null) {
                ArticleContentClassification::scopeContentType($query, $mapped);
            }
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    private function validatedFilterSiteId(bool $throw = true): ?int
    {
        $siteId = (int) ($this->filterSiteId ?? 0);
        $valid = $siteId > 0 && array_key_exists($siteId, $this->getSiteFilterOptions());

        if (! $valid) {
            if (! $throw) {
                return null;
            }

            $message = $siteId <= 0
                ? __('seo-content-ai::filament.articles_optimal.domain_required')
                : __('seo-content-ai::filament.articles_optimal.domain_invalid');

            throw ValidationException::withMessages([
                'domain_ids' => [$message],
            ]);
        }

        return $siteId;
    }

    private function findAccessibleArticle(int $articleId): ?SeoArticle
    {
        return $this->accessibleArticleQuery()->whereKey($articleId)->first();
    }

    /**
     * @return Builder<SeoArticle>
     */
    private function accessibleArticleQuery(): Builder
    {
        $query = SeoArticle::query();

        $siteId = $this->validatedFilterSiteId(throw: false);

        if ($siteId !== null) {
            $query->where('site_id', $siteId);

            return $query;
        }

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = array_map('intval', array_keys($this->getSiteFilterOptions()));
            if ($siteIds !== []) {
                $query->whereIn('site_id', $siteIds);
            }
        }

        return $query;
    }
}
