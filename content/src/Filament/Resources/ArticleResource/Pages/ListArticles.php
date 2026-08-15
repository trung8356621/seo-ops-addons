<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageResolver;
use Omnichannel\Addons\Content\Support\ArticleListDiagnostics;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Livewire\Concerns\RefreshesOnDomainContextChanged;
use App\Support\RuntimeLogger;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

class ListArticles extends ListRecords
{
    use RefreshesOnDomainContextChanged;

    public const TAB_POSTS = 'posts';

    public const TAB_CATEGORIES = 'categories';

    public const TAB_QUEUE = 'queue';

    public const TAB_REVIEWED = 'reviewed';

    public const TAB_SKIPPED = 'skipped';

    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.list-articles';

    #[Url(as: 'tab')]
    public string $contentTab = self::TAB_POSTS;

    public const DEFAULT_RECORDS_PER_PAGE = 30;

    /**
     * Per-page qua GET (?perPage=) — không persist session (tránh treo khi chọn max/all).
     *
     * @return array<string, mixed>
     */
    protected function queryString(): array
    {
        return [
            'tableRecordsPerPage' => [
                'as' => 'perPage',
                'except' => self::DEFAULT_RECORDS_PER_PAGE,
            ],
        ];
    }

    public function updatedTableRecordsPerPage(): void
    {
        session()->forget($this->getTablePerPageSessionKey());
        $this->resetPage();
    }

    public function getDefaultTableRecordsPerPageSelectOption(): int|string
    {
        session()->forget($this->getTablePerPageSessionKey());

        $pageOptions = array_map(
            static fn (int|string $option): int|string => is_numeric($option) ? (int) $option : $option,
            $this->getTable()->getPaginationPageOptions(),
        );

        $fromQuery = request()->query('perPage');
        if ($fromQuery !== null && $fromQuery !== '') {
            $candidate = is_numeric($fromQuery) ? (int) $fromQuery : $fromQuery;
            if (in_array($candidate, $pageOptions, true)) {
                return $candidate;
            }
        }

        if ($this->tableRecordsPerPage !== null && $this->tableRecordsPerPage !== '') {
            $candidate = is_numeric($this->tableRecordsPerPage)
                ? (int) $this->tableRecordsPerPage
                : $this->tableRecordsPerPage;
            if (in_array($candidate, $pageOptions, true)) {
                return $candidate;
            }
        }

        $default = $this->getTable()->getDefaultPaginationPageOption() ?? self::DEFAULT_RECORDS_PER_PAGE;
        $default = is_numeric($default) ? (int) $default : $default;

        if (in_array($default, $pageOptions, true)) {
            return $default;
        }

        return $pageOptions[0] ?? self::DEFAULT_RECORDS_PER_PAGE;
    }

    public function mount(): void
    {
        ArticleListDiagnostics::begin('seo.articles.index');
        ArticleListDiagnostics::mark('tenant_site_resolution');

        try {
            parent::mount();

            $tab = request()->query('tab', self::TAB_POSTS);
            if ($tab === 'sync-queue') {
                $tab = self::TAB_QUEUE;
            }
            if (is_string($tab) && in_array($tab, [
                self::TAB_POSTS,
                self::TAB_CATEGORIES,
                self::TAB_QUEUE,
                self::TAB_REVIEWED,
                self::TAB_SKIPPED,
            ], true)) {
                $this->contentTab = $tab;
            }

            $categoryFilter = request()->input('tableFilters.category_id.value');
            if ($categoryFilter !== null && $categoryFilter !== '') {
                $this->contentTab = self::TAB_POSTS;
            }

            $this->ensureDefaultPostsTableFilters();
            ArticleListDiagnostics::mark('page_mount');
        } catch (\Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'seo.articles.index',
                'request_id' => ArticleListDiagnostics::requestId(),
                'content_tab' => $this->contentTab ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * Default Articles (posts) filters: language=vi, post_type=post
     * (same as ?tableFilters[language][value]=vi&tableFilters[post_type][value]=post).
     */
    protected function ensureDefaultPostsTableFilters(): void
    {
        if ($this->contentTab !== self::TAB_POSTS) {
            return;
        }

        // Respect explicit query-string filters (including cleared empty values).
        if (request()->has('tableFilters')) {
            return;
        }

        $this->tableFilters ??= [];

        $language = trim((string) ($this->tableFilters['language']['value'] ?? ''));
        $postType = trim((string) ($this->tableFilters['post_type']['value'] ?? ''));

        if ($language !== '' && $postType !== '') {
            return;
        }

        if ($language === '') {
            $this->tableFilters['language'] = ['value' => 'vi'];
        }

        if ($postType === '') {
            $this->tableFilters['post_type'] = ['value' => 'post'];
        }

        // Chỉ set state — không gọi getTableFiltersForm()/handleTableFilterUpdates() ở mount:
        // $this->table chưa init (bootedInteractsWithTable chạy sau mount).
        // Form filters được fill trong bootedInteractsWithTable từ $this->tableFilters.
    }

    public function getContentTabUrl(string $tab): string
    {
        $params = ['tab' => $tab];

        $filters = $this->tableFilters ?? [];
        unset($filters['type']);

        if ($tab === self::TAB_CATEGORIES) {
            unset($filters['category_id'], $filters['post_type']);
        } elseif ($tab === self::TAB_QUEUE) {
            unset($filters['category_id'], $filters['post_type'], $filters['taxonomy'], $filters['type']);
        } elseif ($tab === self::TAB_REVIEWED || $tab === self::TAB_SKIPPED) {
            unset($filters['category_id'], $filters['post_type'], $filters['taxonomy'], $filters['type']);
        } else {
            unset($filters['taxonomy']);
            if (trim((string) ($filters['language']['value'] ?? '')) === '') {
                $filters['language'] = ['value' => 'vi'];
            }
            if (trim((string) ($filters['post_type']['value'] ?? '')) === '') {
                $filters['post_type'] = ['value' => 'post'];
            }
        }

        if ($filters !== []) {
            $params['tableFilters'] = $filters;
        }

        return ArticleResource::panelUrl('index').'?'.http_build_query($params);
    }

    /**
     * Badge Sync Queue — số bài chưa sync thành công (cùng scope với tab).
     * Short cache so badge count does not block list HTML on every GET.
     */
    public function getSyncQueueBadgeCount(): int
    {
        return (int) ArticleListDiagnostics::measure('tab_counts', function (): int {
            $ttl = (int) config('seo-content-ai.article_list.sync_queue_badge_cache_seconds', 15);
            $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
            $userId = (int) (auth()->id() ?? 0);
            $cacheKey = 'seo.article_list.sync_queue_badge.'.$siteId.'.'.$userId;

            $resolver = static function (): int {
                $query = ArticleResource::getEloquentQuery();
                ArticleResource::applyContentTabScope($query, self::TAB_QUEUE);
                ArticleResource::applyExcludeSkipSeoAuditScope($query);

                return (int) $query->count();
            };

            if ($ttl <= 0) {
                return $resolver();
            }

            return (int) Cache::remember($cacheKey, $ttl, $resolver);
        });
    }

    public function getArticlesFilterUrlForCategory(int $categoryWpId, ?int $siteId = null): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForCategory($categoryWpId, $siteId);
    }

    public function table(Table $table): Table
    {
        return ArticleResource::table($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                return ArticleListDiagnostics::measure('table_query', function () use ($query): Builder {
                    ArticleResource::applyListSelectColumns($query);
                    ArticleResource::applyContentTabScope($query, $this->contentTab);

                    if ($this->contentTab === self::TAB_SKIPPED) {
                        return ArticleResource::applyOnlySkipSeoAuditScope($query);
                    }

                    ArticleResource::applyExcludeSkipSeoAuditScope($query);

                    if ($this->contentTab === self::TAB_CATEGORIES) {
                        return ArticleResource::appendArticlesInCategoryCountSelect($query);
                    }

                    if ($this->contentTab === self::TAB_QUEUE) {
                        return ArticleResource::appendWpSyncQueueMetaSelect($query);
                    }

                    if ($this->contentTab === self::TAB_REVIEWED) {
                        return ArticleResource::applyApprovedReviewScope($query)->whereNotNull('articles.reviewed_at');
                    }

                    if (in_array($this->contentTab, [self::TAB_POSTS, self::TAB_CATEGORIES, self::TAB_QUEUE], true)) {
                        ArticleResource::applyWpSyncQueueUnreviewedScope($query);
                    }

                    return $query;
                });
            });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $records = parent::paginateTableQuery($query);

        $items = method_exists($records, 'getCollection')
            ? $records->getCollection()
            : Collection::make(method_exists($records, 'items') ? $records->items() : []);

        app(ArticleFeaturedImageResolver::class)->primeForArticles($items);

        return $records;
    }

    public function setSeoScoreBandFilter(?string $band = null): void
    {
        $this->tableFilters ??= [];

        // Legacy query-string key from older filter UI — ignore to avoid stale state.
        unset($this->tableFilters['seo_score']);

        if ($band === null || $band === '') {
            unset($this->tableFilters['seo_score_band']);
        } else {
            $this->tableFilters['seo_score_band'] = [
                'value' => $band,
            ];
        }

        $this->getTableFiltersForm()->fill($this->tableFilters);
        $this->handleTableFilterUpdates();
        $this->flushCachedTableRecords();
    }

    public function syncArticleMainKeyword(int $articleId, string $phrase): void
    {
        abort_unless(SeoAccessControl::canAccessPlannerFeatures(), 403);

        $article = ArticleResource::getEloquentQuery()
            ->whereKey($articleId)
            ->first();

        if (! $article instanceof SeoArticle) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) (ArticleResource::resolveArticleSiteId($article) ?? SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $current = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
        $phrase = trim($phrase);
        $keywordChanged = $phrase !== $current;

        if ($keywordChanged) {
            KeywordFocusAttach::syncMainKeyword(
                $article,
                $siteId,
                (int) auth()->id(),
                $phrase,
            );

            app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($article->fresh());
        }

        $article = $article->fresh();
        if (! $article instanceof SeoArticle) {
            return;
        }

        $content = app(ArticleKeywordLinkReconcileService::class)->resolveArticleContent($article);
        $scoreResult = app(SeoAnalyzerService::class)->analyzeSubmittedContent($article, $content);
        $article = $article->fresh();
        $score = (int) ($scoreResult['score'] ?? $article?->seoProfile?->seo_score ?? 0);
        $this->flushCachedTableRecords();

        if (! $keywordChanged) {
            return;
        }

        $wpPostId = (int) ($article?->wordpressLink?->wp_post_id ?? 0);

        if ($wpPostId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_synced'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_synced_score_only', [
                    'score' => $score,
                    'keyword' => $phrase !== '' ? $phrase : __('seo-content-ai::filament.article_list.seo_keyword_empty'),
                ]))
                ->warning()
                ->send();

            return;
        }

        $wpResult = app(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class)
            ->syncSeoMeta($article, auth()->user(), 'list_articles.sync_seo_meta', [
                'focus_keyword' => $phrase,
            ]);

        if (! ($wpResult['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.main_keyword_sync_failed'))
                ->body(__('seo-content-ai::filament.article_list.main_keyword_wp_sync_failed_with_score', [
                    'score' => $score,
                    'message' => (string) ($wpResult['message'] ?? __('seo-content-ai::filament.article_list.main_keyword_wp_sync_failed')),
                ]))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.sync_queued_title'))
            ->body((string) ($wpResult['message'] ?? ''))
            ->info()
            ->send();
    }

    public function retryArticleSyncQueue(int $articleId): void
    {
        $this->resyncArticleSyncQueue($articleId);
    }

    public function resyncArticleSyncQueue(int $articleId): void
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        $article = ArticleResource::getEloquentQuery()->whereKey($articleId)->first();
        if (! $article instanceof SeoArticle) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_failed'))
                ->danger()
                ->send();

            return;
        }

        $result = app(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class)
            ->resyncQueued($article, auth()->user(), 'list_articles.resync');

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_failed'))
                ->body((string) ($result['message'] ?? ''))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.sync_queue_resync_queued'))
            ->body((string) ($result['message'] ?? ''))
            ->info()
            ->send();

        $this->resetTable();
    }

    public function cancelArticleSyncQueue(int $articleId): void
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);

        $article = ArticleResource::getEloquentQuery()->whereKey($articleId)->first();
        if (! $article instanceof SeoArticle) {
            return;
        }

        if (! app(ArticleWpSyncQueueService::class)->cancel($article)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.sync_queue_cancel_failed'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.sync_queue_cancelled'))
            ->success()
            ->send();

        $this->resetTable();
    }

    /**
     * @return list<array{
     *     date: string,
     *     date_label: string,
     *     count: int,
     *     articles: list<array{id: int, title: string, reviewed_time: string, edit_url: string, view_url: string|null}>
     * }>
     */
    public function getReviewedArticlesGrouped(): array
    {
        return ArticleResource::buildReviewedArticlesGrouped();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_from_keywords')
                ->label('Create new articles')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->form([
                    Forms\Components\Placeholder::make('main_domain')
                        ->label('Main domain')
                        ->content(fn (SeoMainDomainService $mainDomain): string => $mainDomain->resolveMainSiteLabel()),
                    Forms\Components\Textarea::make('keywords')
                        ->label('Keywords')
                        ->placeholder("One keyword per line\nExample:\nmen leather backpack\nnon-woven bags")
                        ->rows(8)
                        ->required()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data, CreateArticlesFromTaskService $service): void {
                    try {
                        $result = $service->runFromKeywords(
                            (string) ($data['keywords'] ?? ''),
                        );

                        $body = sprintf(
                            'Success: %d · Failed: %d',
                            $result['created'],
                            $result['failed'],
                        );

                        if ($result['messages'] !== []) {
                            $body .= "\n".implode("\n", array_slice($result['messages'], 0, 8));
                            if (count($result['messages']) > 8) {
                                $body .= "\n…";
                            }
                        }

                        $notification = Notification::make()
                            ->title('Keywords processed')
                            ->body($body);

                        if ($result['failed'] > 0 && $result['created'] === 0) {
                            $notification->danger();
                        } elseif ($result['failed'] > 0) {
                            $notification->warning();
                        } else {
                            $notification->success();
                        }

                        $notification->send();
                    } catch (\InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Unable to create articles')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->modalHeading('Auto create articles')
                ->modalDescription('Enter keyword list. System will run configured "Publish article" workflow in SEO -> Settings.')
                ->modalSubmitActionLabel('Run workflow & create'),
            Actions\Action::make('trash')
                ->label('Trash')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->url(fn (): string => ArticleResource::getUrl('trash')),
        ];
    }
}
