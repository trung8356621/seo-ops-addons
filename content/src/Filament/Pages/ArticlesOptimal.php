<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoAuditKeywordFlagService;
use Omnichannel\Addons\Seo\Services\SeoAuditScanService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

final class ArticlesOptimal extends SeoPanelPage
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Article SEO audit';

    protected static ?string $title = 'Article SEO audit';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Articles';

    protected static ?int $navigationSort = 6;

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

    public string $scanState = 'idle';

    public ?string $scanError = null;

    public ?string $scanNotice = null;

    /** @var array<string, int>|null */
    public ?array $cacheStatusCounts = null;

    /** @var array<int, int> */
    public array $selectedArticleIds = [];

    public ?int $sidebarProjectId = null;

    /** Sidebar assign: true = ẩn. Persist qua Livewire để remorph không reset Alpine. */
    public bool $sidebarCollapsed = true;

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
        $languages = SeoArticle::query()
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language')
            ->filter()
            ->all();

        $options = [];
        foreach ($languages as $lang) {
            $label = match ($lang) {
                'vi' => 'Tiếng Việt',
                'en' => 'English',
                'ja' => '日本語',
                'ko' => '한국어',
                'zh' => '中文',
                'fr' => 'Français',
                default => mb_strtoupper((string) $lang),
            };
            $options[(string) $lang] = $label;
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
        $query = SeoArticle::query()
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->whereNotNull('type')
            ->where('type', '!=', '');

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
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(static fn (mixed $type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
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
        $this->filterSiteId = $this->validatedFilterSiteId(throw: false);
        $this->invalidateScanResults();
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

    /**
     * @return array<int, string>
     */
    public function getContentProjectOptions(): array
    {
        $options = [];
        $siteId = $this->validatedFilterSiteId(throw: false);
        if ($siteId !== null) {
            $options += ArticleResource::contentProjectOptionsForSeoAudit((int) $siteId);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function getAssignTypeOptions(): array
    {
        return SeoProjectTask::typeOptions();
    }

    /**
     * @return array<string, string>
     */
    public function getRewriteModeOptions(): array
    {
        return SeoProjectTask::rewriteModeOptions();
    }

    public function selectSidebarProject(mixed $projectId): void
    {
        $this->sidebarProjectId = (int) $projectId > 0 ? (int) $projectId : null;
        $this->skipRender();
    }

    /**
     * @return list<array{id:int,title:string,status:string,type:string}>
     */
    public function getSidebarProjectArticles(): array
    {
        $projectId = (int) ($this->sidebarProjectId ?? 0);
        if ($projectId <= 0) {
            return [];
        }

        return SeoProjectTask::query()
            ->with('article:id,title,status')
            ->where('project_id', $projectId)
            ->orderBy('target_date')
            ->orderBy('id')
            ->get()
            ->map(function (SeoProjectTask $task): array {
                $article = $task->article;

                return [
                    'id' => (int) ($article?->id ?? 0),
                    'title' => trim((string) ($article?->title ?? $task->source_content)),
                    'status' => (string) ($article?->status ?? $task->status ?? ''),
                    'type' => (string) ($task->type ?? ''),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int|string>  $articleIds
     * @param  array<string, mixed>  $data
     */
    /**
     * @return array{project_id:int, remaining:int}
     */
    public function assignFromSidebar(array $articleIds, array $data = []): array
    {
        if (! isset($data['project_id']) || (int) $data['project_id'] <= 0) {
            $data['project_id'] = $this->sidebarProjectId;
        }

        $data['ignore_monthly_capacity'] = true;

        $this->assignArticlesToContentProject($articleIds, $data);

        $projectId = (int) ($data['project_id'] ?? 0);
        $project = $projectId > 0 ? SeoProject::query()->find($projectId) : null;
        $remaining = $project?->remainingTaskCapacity() ?? 0;

        if ($project instanceof SeoProject && $remaining <= 2) {
            Notification::make()
                ->title($remaining === 0
                    ? __('seo-content-ai::filament.articles_optimal.project_capacity_full')
                    : __('seo-content-ai::filament.articles_optimal.project_capacity_near'))
                ->body(__('seo-content-ai::filament.articles_optimal.project_capacity_remaining', [
                    'count' => $remaining,
                ]))
                ->warning()
                ->send();
        }

        return [
            'project_id' => $projectId,
            'remaining' => $remaining,
        ];
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function assignArticleToContentProject(int $articleId, array $data): void
    {
        $this->assignArticlesToContentProject([$articleId], $data);
    }

    public function assignArticleToSelectedProject(int $articleId): void
    {
        $this->assignArticlesToContentProject([$articleId], [
            'project_id' => $this->sidebarProjectId,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'ignore_monthly_capacity' => true,
        ]);
    }

    public function assignSelectedArticlesToSelectedProject(mixed $projectId = null): void
    {
        $this->assignArticlesToContentProject($this->selectedArticleIds, [
            'project_id' => $projectId !== null && (int) $projectId > 0 ? (int) $projectId : $this->sidebarProjectId,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'ignore_monthly_capacity' => true,
        ]);
    }

    /**
     * @param  array<int, int|string>  $articleIds
     * @param  array<string, mixed>  $data
     */
    private function assignArticlesToContentProject(array $articleIds, array $data): void
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        if ($projectId <= 0 || ! SeoProject::query()->whereKey($projectId)->exists()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body(__('seo-content-ai::filament.articles_optimal.assign_no_project'))
                ->warning()
                ->send();
            $this->skipRender();

            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            $this->skipRender();

            return;
        }

        $records = $this->accessibleArticleQuery()
            ->whereIn('id', $ids)
            ->with(['articleMetas' => static function ($relation): void {
                $relation->where('meta_key', 'seo_focus_keyword');
            }])
            ->get();

        if ($records->isEmpty()) {
            $this->skipRender();

            return;
        }

        $analyzer = app(SeoAnalyzerService::class);
        $focusKeywordInput = trim((string) ($data['focus_keyword'] ?? ''));
        $missingFocus = $records->filter(static function (SeoArticle $article) use ($analyzer): bool {
            return trim((string) ($analyzer->resolveFocusKeywordForArticle($article) ?? '')) === '';
        });

        if ($missingFocus->count() > 1) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body(__('seo-content-ai::filament.articles_optimal.assign_missing_keyword_bulk'))
                ->warning()
                ->send();
            $this->skipRender();

            return;
        }

        if ($missingFocus->count() === 1 && $focusKeywordInput === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body(__('seo-content-ai::filament.articles_optimal.assign_missing_keyword_required'))
                ->warning()
                ->send();
            $this->skipRender();

            return;
        }

        if ($focusKeywordInput !== '') {
            $userId = (int) (auth()->id() ?? 0);
            foreach ($missingFocus as $article) {
                $siteId = (int) ($article->site_id ?? 0);
                if ($siteId <= 0) {
                    continue;
                }

                KeywordFocusAttach::syncMainKeyword($article, $siteId, $userId, $focusKeywordInput);
                $article->unsetRelation('articleMetas');
            }
        }

        $summary = ArticleResource::assignArticlesFromFormData(
            Collection::make($records),
            $projectId,
            $data,
        );

        $this->selectedArticleIds = array_values(array_diff(array_map('intval', $this->selectedArticleIds), $ids));
        $this->sidebarProjectId = $projectId;

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.assign_completed'))
            ->body(ArticleResource::buildAssignContentProjectBody($summary))
            ->success()
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
        );
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

        $query = SeoArticle::query()
            ->countsTowardSeoScore()
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->orderByDesc('updated_at');

        ArticleResource::applySeoAuditCandidateScope($query);

        $query->where('site_id', $siteId);

        if ($this->filterLanguage !== null && $this->filterLanguage !== '') {
            $query->where('language', $this->filterLanguage);
        }

        $postType = trim((string) ($this->filterPostType ?? ''));
        if ($postType !== '') {
            $query->where('type', $postType);
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
