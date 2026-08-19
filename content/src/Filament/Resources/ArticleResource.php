<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Models\SeoMediaProcessingHistory;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\Seo\Services\SeoNotificationService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleResource extends SeoPanelResource
{
    protected static ?string $model = SeoArticle::class;

    protected static ?string $slug = 'articles';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Articles';

    protected static ?string $modelLabel = 'Article';

    protected static ?string $pluralModelLabel = 'Articles';

    protected static ?int $navigationSort = 1;

    /** article_meta: bỏ qua lọc Article SEO Audit (Laravel only — không đụng WordPress). */
    public const META_SKIP_SEO_AUDIT = 'skip_seo_audit';

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }

    public static function panelId(): string
    {
        return 'seo';
    }

    /**
     * URL resource trong panel SEO (dùng khi gọi ngoài ngữ cảnh Filament, VD: API preview).
     */
    public static function panelUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true): string
    {
        return static::getUrl($name, $parameters, $isAbsolute, panel: static::panelId());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label(__('seo-content-ai::filament.article_list.status'))
                    ->options([
                        'draft' => __('seo-content-ai::filament.article_list.status_draft'),
                        'published' => __('seo-content-ai::filament.article_list.status_published'),
                        'scheduled' => __('seo-content-ai::filament.article_list.status_scheduled'),
                        'private' => __('seo-content-ai::filament.article_list.status_private'),
                    ])
                    ->default('draft')
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordAction('edit')
            ->columns([
                Tables\Columns\ViewColumn::make('featured_thumb')
                    ->label(__('seo-content-ai::filament.article_list.thumb'))
                    ->view('seo-content-ai::filament.tables.columns.article-list-thumbnail')
                    ->getStateUsing(fn (SeoArticle $record): array => app(ArticleFeaturedImageProjection::class)->forList($record))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_data_out_of_sync')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(function (SeoArticle $record): ?string {
                        return app(ArticleWordPressSyncFlagService::class)->hasDataOutOfSync($record)
                            ? __('seo-content-ai::filament.article_list.data_out_of_sync')
                            : null;
                    })
                    ->placeholder('')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (SeoArticle $record): ?string {
                        if (filled($record->slug)) {
                            return '/'.ltrim((string) $record->slug, '/');
                        }

                        if ((int) ($record->wordpressLink?->wp_post_id ?? $record->getAttributes()['wp_post_id'] ?? 0) > 0) {
                            return 'WP ID: '.(int) ($record->wordpressLink?->wp_post_id ?? $record->getAttributes()['wp_post_id']);
                        }

                        return null;
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.article_list.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        'published' => __('seo-content-ai::filament.article_list.status_published'),
                        'scheduled' => __('seo-content-ai::filament.article_list.status_scheduled'),
                        'private' => __('seo-content-ai::filament.article_list.status_private'),
                        'draft' => __('seo-content-ai::filament.article_list.status_draft'),
                        default => $state ?: '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('seo-content-ai::filament.article_list.type'))
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state, SeoArticle $record): string => static::resolveWordPressPostTypeLabel($record))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('articles_in_category_count')
                    ->label(__('seo-content-ai::filament.article_list.articles_in_category'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->url(function (SeoArticle $record): ?string {
                        $wpId = (int) ($record->getAttributes()['wp_post_id'] ?? $record->wordpressLink?->wp_post_id ?? 0);
                        if ($wpId <= 0) {
                            return null;
                        }

                        return app(\Omnichannel\Addons\Seo\Services\DomainOverviewService::class)
                            ->buildArticlesFilterUrlForCategory($wpId, (int) ($record->site_id ?? 0) ?: null);
                    })
                    ->color('primary')
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_CATEGORIES)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('language')
                    ->label(__('seo-content-ai::filament.article_list.language'))
                    ->visible(fn (): bool => app(SitePolylangService::class)->anyAccessibleSiteHasPolylang())
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(function (?string $state, SeoArticle $record): string {
                        $record->loadMissing('site');

                        return app(SitePolylangService::class)->languageLabel(
                            (string) ($state ?? ''),
                            $record->site,
                        );
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('author')
                    ->label(__('seo-content-ai::filament.article_list.author'))
                    ->badge()
                    ->getStateUsing(function (SeoArticle $record): string {
                        if ($record->user_id === null) {
                            return __('seo-content-ai::filament.article_list.system');
                        }

                        $record->loadMissing('user');

                        return (string) ($record->user?->display_name ?? $record->user?->email ?? __('seo-content-ai::filament.article_list.system'));
                    })
                    ->color(fn (string $state): string => $state === __('seo-content-ai::filament.article_list.system') ? 'gray' : 'primary')
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.article_list.updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('articles.updated_at', $direction))
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('seo-content-ai::filament.article_list.published'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('list_pas.published_at', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label(__('seo-content-ai::filament.article_list.reviewed_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('articles.reviewed_at', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('wp_sync_queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->badge()
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueStatus($record))
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                        ArticleWpSyncQueueService::STATUS_CANCELLED => __('seo-content-ai::filament.article_list.queue_status_cancelled'),
                        ArticleWpSyncQueueService::STATUS_STALE => __('seo-content-ai::filament.article_list.queue_status_stale'),
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => 'warning',
                        ArticleWpSyncQueueService::STATUS_PROCESSING => 'info',
                        ArticleWpSyncQueueService::STATUS_COMPLETED => 'success',
                        ArticleWpSyncQueueService::STATUS_FAILED, ArticleWpSyncQueueService::STATUS_STALE => 'danger',
                        ArticleWpSyncQueueService::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    })
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_sync_queue_queued_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_queued_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'queued_at'),
                    ))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('wp_sync_queue_error')
                    ->label(__('seo-content-ai::filament.article_list.queue_error'))
                    ->wrap()
                    ->limit(80)
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueField($record, 'error'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE)
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\ViewColumn::make('seo_details')
                    ->label(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                        view('seo-content-ai::filament.tables.columns.article-seo-details-header')->render(),
                    ))
                    ->view('seo-content-ai::filament.tables.columns.article-seo-details')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        static::applyListExtensionJoins($query);

                        return $query
                            ->orderByRaw('CASE WHEN list_sap.skip_seo_score = 1 THEN 1 ELSE 0 END ASC')
                            ->orderBy('list_sap.seo_score', $direction);
                    })
                    ->disabledClick()
                    ->visible(fn ($livewire): bool => ! ($livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE))
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('articles.updated_at', 'desc')
            ->defaultKeySort()
            ->filters([
                SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->visible(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->options(function (): array {
                        $query = Site::query()->orderBy('domain');

                        if (SeoAccessControl::shouldScopeToAccountOwner()) {
                            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
                        }

                        return $query->pluck('domain', 'id')->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_domains'))
                    ->indicator(__('seo-content-ai::filament.article_list.domain'))
                    ->query(function (Builder $query, array $data): void {
                        $siteId = $data['value'] ?? null;
                        if ($siteId === null || $siteId === '') {
                            return;
                        }

                        $query->where('articles.site_id', $siteId);
                    }),
                SelectFilter::make('language')
                    ->label('Ngôn ngữ')
                    ->visible(fn (): bool => app(SitePolylangService::class)->anyAccessibleSiteHasPolylang())
                    ->options(fn (): array => app(SitePolylangService::class)->defaultLanguageOptions())
                    ->default('vi')
                    ->native(false)
                    ->placeholder('Tất cả ngôn ngữ')
                    ->indicator('Ngôn ngữ')
                    ->query(function (Builder $query, array $data): void {
                        $lang = trim((string) ($data['value'] ?? ''));
                        if ($lang === '') {
                            return;
                        }

                        $query->where('articles.language', $lang);
                    }),
                SelectFilter::make('post_type')
                    ->label(__('seo-content-ai::filament.article_list.post_type'))
                    ->options(static function (): array {
                        $defaultLabels = [
                            'post' => __('seo-content-ai::filament.article_list.post_type_post'),
                            'page' => __('seo-content-ai::filament.article_list.post_type_page'),
                            'product' => __('seo-content-ai::filament.article_list.post_type_product'),
                        ];
                        if (! \Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasTable('article_meta')) {
                            return $defaultLabels;
                        }
                        $slugs = \Omnichannel\Addons\Content\Models\ArticleMeta::query()
                            ->where('meta_key', 'wp_post_type')
                            ->whereNotNull('meta_value')
                            ->where('meta_value', '!=', '')
                            ->distinct()
                            ->pluck('meta_value')
                            ->sort()
                            ->values()
                            ->all();
                        if ($slugs === []) {
                            return $defaultLabels;
                        }
                        $options = [];
                        foreach ($slugs as $slug) {
                            $options[$slug] = $defaultLabels[$slug]
                                ?? ucfirst(str_replace(['_', '-'], ' ', (string) $slug));
                        }
                        return $options;
                    })
                    ->default('post')
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_post_types'))
                    ->indicator(__('seo-content-ai::filament.article_list.post_type'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_POSTS)
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_POSTS) {
                            return;
                        }

                        $postType = $data['value'] ?? null;
                        if (! is_string($postType) || $postType === '') {
                            return;
                        }

                        static::applyPostTypeFilterScope($query, $postType);
                    }),
                SelectFilter::make('taxonomy')
                    ->label(__('seo-content-ai::filament.article_list.taxonomy'))
                    ->options([
                        'category' => __('seo-content-ai::filament.article_list.post_type_category'),
                        'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
                    ])
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_taxonomies'))
                    ->indicator(__('seo-content-ai::filament.article_list.taxonomy'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_CATEGORIES)
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_CATEGORIES) {
                            return;
                        }

                        $taxonomy = $data['value'] ?? null;
                        if (! is_string($taxonomy) || $taxonomy === '') {
                            return;
                        }

                        $query->where('articles.type', $taxonomy);
                    }),
                SelectFilter::make('category_id')
                    ->label(__('seo-content-ai::filament.article_list.category_filter'))
                    ->options(fn ($livewire): array => $livewire instanceof Pages\ListArticles
                        ? static::buildCategoryFilterOptions($livewire)
                        : [])
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_categories'))
                    ->indicator(__('seo-content-ai::filament.article_list.category_filter'))
                    ->visible(fn ($livewire): bool => $livewire instanceof Pages\ListArticles
                        && $livewire->contentTab === Pages\ListArticles::TAB_POSTS
                        && trim((string) ($livewire->tableFilters['post_type']['value'] ?? '')) !== 'page')
                    ->query(function (Builder $query, array $data, $livewire): void {
                        if (! $livewire instanceof Pages\ListArticles
                            || $livewire->contentTab !== Pages\ListArticles::TAB_POSTS) {
                            return;
                        }

                        $categoryWpId = (int) ($data['value'] ?? 0);
                        if ($categoryWpId <= 0) {
                            return;
                        }

                        static::applyCategoryMembershipScope($query, $categoryWpId);
                    }),
                SelectFilter::make('seo_score_band')
                    ->label(__('seo-content-ai::filament.article_list.seo_score'))
                    ->visible(fn (): bool => false)
                    ->options([
                        'poor' => __('seo-content-ai::filament.article_list.seo_score_poor'),
                        'fair' => __('seo-content-ai::filament.article_list.seo_score_fair'),
                        'good' => __('seo-content-ai::filament.article_list.seo_score_good'),
                        'excellent' => __('seo-content-ai::filament.article_list.seo_score_excellent'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $band = $data['value'] ?? null;
                        if (! is_string($band) || $band === '') {
                            return;
                        }

                        static::applyListExtensionJoins($query);
                        $query->where(function (Builder $sub): void {
                            $sub->where('list_sap.skip_seo_score', false)
                                ->orWhereNull('list_sap.skip_seo_score');
                        })->whereNotNull('list_sap.seo_score');

                        match ($band) {
                            'poor' => $query->where('list_sap.seo_score', '<', 50),
                            'fair' => $query->whereBetween('list_sap.seo_score', [50, 69.99]),
                            'good' => $query->whereBetween('list_sap.seo_score', [70, 89.99]),
                            'excellent' => $query->where('list_sap.seo_score', '>=', 90),
                            default => null,
                        };
                    })
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.all_scores'))
                    ->indicator(__('seo-content-ai::filament.article_list.seo_score')),
                Filter::make('seo_link')
                    ->label(__('seo-content-ai::filament.article_list.links_in_article'))
                    ->form([
                        Forms\Components\Hidden::make('url'),
                        Forms\Components\Hidden::make('type'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return;
                        }

                        $type = $data['type'] ?? null;

                        $query->whereHas('linkMaps', function (Builder $linkQuery) use ($url, $type): void {
                            $linkQuery->where('target_external_url', trim($url));

                            if ($type === 'internal') {
                                $linkQuery->where('link_type', SeoLinkMapType::Internal);
                            } elseif ($type === 'external') {
                                $linkQuery->whereIn('link_type', [
                                    SeoLinkMapType::External,
                                    SeoLinkMapType::WikiTrust,
                                ]);
                            }
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return null;
                        }

                        $type = $data['type'] ?? null;
                        $typeLabel = $type === 'internal' ? 'internal' : ($type === 'external' ? 'external' : '');

                        return __('seo-content-ai::filament.article_list.link').($typeLabel !== '' ? ' '.$typeLabel : '').': '.Str::limit($url, 48);
                    }),
                Filter::make('keyword')
                    ->label(__('seo-content-ai::filament.article_list.keyword'))
                    ->form([
                        Forms\Components\Hidden::make('keyword_id'),
                        Forms\Components\Hidden::make('usage'),
                        Forms\Components\Hidden::make('internal_link_only'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return;
                        }

                        $usage = (string) ($data['usage'] ?? '');

                        if ($usage === 'main') {
                            $query->whereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                $subQuery->selectRaw('CAST(meta_value AS UNSIGNED)')
                                    ->from('keyword_meta')
                                    ->where('keyword_id', $keywordId)
                                    ->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::MainArticleId->value);
                            });

                            return;
                        }

                        if ($usage === 'internal_link' || ($data['internal_link_only'] ?? '') === '1') {
                            $query->whereHas('linkMaps', function (Builder $linkQuery) use ($keywordId): void {
                                $linkQuery
                                    ->where('keyword_id', $keywordId)
                                    ->where('link_type', SeoLinkMapType::Internal);
                            });

                            return;
                        }

                        $query->where(function (Builder $scopeQuery) use ($keywordId): void {
                            $scopeQuery
                                ->whereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                    $subQuery->selectRaw('CAST(meta_value AS UNSIGNED)')
                                        ->from('keyword_meta')
                                        ->where('keyword_id', $keywordId)
                                        ->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::MainArticleId->value);
                                })
                                ->orWhereIn('articles.id', function ($subQuery) use ($keywordId): void {
                                    $subQuery->select('source_article_id')
                                        ->from('seo_link_maps')
                                        ->where('keyword_id', $keywordId)
                                        ->whereNotNull('source_article_id');
                                });
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return null;
                        }

                        $phrase = Keyword::query()
                            ->whereKey($keywordId)
                            ->value('phrase');

                        if (! is_string($phrase) || $phrase === '') {
                            return __('seo-content-ai::filament.article_list.keyword').' #'.$keywordId;
                        }

                        $usage = (string) ($data['usage'] ?? '');
                        $suffix = match (true) {
                            $usage === 'main' => ' ('.__('seo-content-ai::filament.article_list.main_article').')',
                            $usage === 'internal_link', ($data['internal_link_only'] ?? '') === '1' => ' ('.__('seo-content-ai::filament.article_list.has_internal_link').')',
                            default => '',
                        };

                        return __('seo-content-ai::filament.article_list.keyword').': '.$phrase.$suffix;
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 5,
            ])
            ->persistFiltersInSession()
            ->defaultPaginationPageOption(30)
            ->paginationPageOptions([10, 30, 50, 100])
            ->actionsAlignment('start')
            ->actions(static::getArticleTableRowActionsMerged())
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_articles')
                        ->label(__('seo-content-ai::filament.article_list.review_articles'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (): bool => ! SeoAccessControl::isContentManager())
                        ->extraAttributes([
                            'wire:confirm' => __('seo-content-ai::filament.article_list.review_article_description'),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $approvedCount = 0;
                            $deletedMediaCount = 0;
                            $user = auth()->user();
                            if (! $user instanceof User) {
                                return;
                            }
                            $reviewService = app(ArticleReviewService::class);

                            foreach ($records as $record) {
                                if (! $record instanceof SeoArticle) {
                                    continue;
                                }

                                try {
                                    $result = $reviewService->ensureApproved(
                                        $record,
                                        $user,
                                        source: 'article_list.bulk_approve',
                                    );
                                    $deletedMediaCount += (int) ($result['deleted_media_count'] ?? 0);
                                    $approvedCount++;
                                } catch (ArticleReviewException $exception) {
                                    Notification::make()
                                        ->title($exception->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.bulk_review_completed'))
                                ->body(__('seo-content-ai::filament.article_list.bulk_review_body', [
                                    'approved' => $approvedCount,
                                    'deleted' => $deletedMediaCount,
                                ]))
                                ->success()
                                ->send();
                        }),
                    AssignToContentProjectActionFactory::tableBulkAction(
                        resolvePayload: function (SupportCollection $records): array {
                            $siteId = static::resolveBulkArticlesSiteId($records);
                            $articleIds = $records
                                ->filter(static fn (mixed $record): bool => $record instanceof SeoArticle)
                                ->map(static fn (SeoArticle $article): int => (int) $article->id)
                                ->values()
                                ->all();

                            return AssignToContentProjectContract::articlePayload(
                                source: 'article_table_bulk',
                                articleIds: $articleIds,
                                siteId: $siteId,
                                options: [
                                    'show_quick_create' => true,
                                    'show_article_fields' => true,
                                    'show_keyword_override' => true,
                                    'show_title_override' => true,
                                ],
                            );
                        },
                        visible: fn (): bool => SeoAccessControl::canMutateInSeoPanel(),
                    ),
                    Tables\Actions\BulkAction::make('skip_seo_audit')
                        ->label(__('seo-content-ai::filament.article_list.skip_articles'))
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->visible(fn (): bool => ! static::isArticlesSkippedTab())
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $skippedCount = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof SeoArticle) {
                                    continue;
                                }

                                if (static::articleIsSkipSeoAudit($record)) {
                                    continue;
                                }

                                static::toggleSkipSeoAudit($record);
                                $skippedCount++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.bulk_skip_completed', [
                                    'count' => $skippedCount,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('unskip_seo_audit')
                        ->label(__('seo-content-ai::filament.article_list.unskip_articles'))
                        ->icon('heroicon-o-eye')
                        ->color('warning')
                        ->visible(fn (): bool => static::isArticlesSkippedTab())
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $restoredCount = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof SeoArticle) {
                                    continue;
                                }

                                if (! static::articleIsSkipSeoAudit($record)) {
                                    continue;
                                }

                                static::toggleSkipSeoAudit($record);
                                $restoredCount++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.article_list.bulk_unskip_completed', [
                                    'count' => $restoredCount,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]));
    }

    public static function applyPostTypeFilterScope(Builder $query, string $wpPostType): void
    {
        $wpPostType = strtolower(trim($wpPostType));
        if ($wpPostType === '') {
            return;
        }

        if ($wpPostType === 'post') {
            $query->where(static function (Builder $scopeQuery): void {
                $scopeQuery
                    ->whereHas('articleMetas', static function (Builder $metaQ): void {
                        $metaQ->where('meta_key', 'wp_post_type')->where('meta_value', 'post');
                    })
                    ->orWhere(static function (Builder $legacyQ): void {
                        $legacyQ
                            ->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                                $metaQ->where('meta_key', 'wp_post_type');
                            })
                            ->where(function (Builder $typeQ): void {
                                $typeQ->whereIn('articles.type', ['article'])
                                    ->orWhereNull('articles.type')
                                    ->orWhere('articles.type', '');
                            });
                    });
            });

            return;
        }

        if ($wpPostType === 'product') {
            $query->where(static function (Builder $scopeQuery): void {
                $scopeQuery
                    ->whereHas('articleMetas', static function (Builder $metaQ): void {
                        $metaQ->where('meta_key', 'wp_post_type')->where('meta_value', 'product');
                    })
                    ->orWhere(static function (Builder $legacyQ): void {
                        $legacyQ
                            ->whereDoesntHave('articleMetas', static function (Builder $metaQ): void {
                                $metaQ->where('meta_key', 'wp_post_type');
                            })
                            ->where('articles.type', 'product');
                    });
            });

            return;
        }

        static::applyArticlesWithWpPostTypeMetaScope($query, $wpPostType);
    }

    public static function resolveWordPressPostTypeLabel(SeoArticle $record): string
    {
        $wpPostType = null;
        if ($record->relationLoaded('articleMetas')) {
            $meta = $record->articleMetas->firstWhere('meta_key', 'wp_post_type');
            $wpPostType = $meta?->meta_value;
        } else {
            $wpPostType = $record->articleMetas()
                ->where('meta_key', 'wp_post_type')
                ->value('meta_value');
        }

        $wpPostType = strtolower(trim((string) ($wpPostType ?? '')));

        if ($wpPostType !== '') {
            $labels = [
                'post' => __('seo-content-ai::filament.article_list.post_type_post'),
                'page' => __('seo-content-ai::filament.article_list.post_type_page'),
                'product' => __('seo-content-ai::filament.article_list.post_type_product'),
            ];
            return $labels[$wpPostType] ?? ucfirst(str_replace(['_', '-'], ' ', $wpPostType));
        }

        $type = strtolower(trim((string) ($record->type ?? 'article')));
        return match ($type) {
            'product' => __('seo-content-ai::filament.article_list.post_type_product'),
            'category' => __('seo-content-ai::filament.article_list.post_type_category'),
            'product_category', 'product_cat' => __('seo-content-ai::filament.article_list.post_type_product_category'),
            default => __('seo-content-ai::filament.article_list.post_type_post'),
        };
    }

    private static function applyArticlesWithWpPostTypeMetaScope(Builder $query, string $wpPostType): void
    {
        $wpPostType = strtolower(trim($wpPostType));

        $query->whereHas('articleMetas', static function (Builder $metaQ) use ($wpPostType): void {
            $metaQ->where('meta_key', 'wp_post_type')
                ->where('meta_value', $wpPostType);
        });
    }

    public static function applyContentTabScope(Builder $query, string $contentTab): Builder
    {
        if ($contentTab === Pages\ListArticles::TAB_QUEUE) {
            return static::applyWpSyncQueueScope($query);
        }

        if ($contentTab === Pages\ListArticles::TAB_CATEGORIES) {
            return $query->whereIn('articles.type', ['category', 'product_category']);
        }

        return $query->where(function (Builder $scopeQuery): void {
            $scopeQuery
                ->whereIn('articles.type', ['article', 'product'])
                ->orWhere(function (Builder $sub): void {
                    $sub->whereNull('articles.type')->orWhere('articles.type', '');
                });
        });
    }

    public static function applyCategoryMembershipScope(Builder $query, int $categoryWpId): void
    {
        $query->whereIn('articles.id', function ($subQuery) use ($categoryWpId): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', 'category_ids')
                ->whereRaw(static::articleMetaContainsCategoryWpIdSql(), [$categoryWpId]);
        });
    }

    public static function appendArticlesInCategoryCountSelect(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            static::applyListSelectColumns($query);
        }

        return $query->selectSub(function ($subQuery): void {
            $subQuery->from('articles as post_articles')
                ->selectRaw('count(*)')
                ->whereColumn('post_articles.site_id', 'articles.site_id')
                ->where(function ($typeQuery): void {
                    $typeQuery
                        ->whereIn('post_articles.type', ['article', 'product'])
                        ->orWhere(function ($nullTypeQuery): void {
                            $nullTypeQuery
                                ->whereNull('post_articles.type')
                                ->orWhere('post_articles.type', '');
                        });
                })
                ->whereIn('post_articles.id', function ($metaQuery): void {
                    $metaQuery->select('article_id')
                        ->from('article_meta')
                        ->where('meta_key', 'category_ids')
                        ->whereRaw(static::articleMetaContainsCategoryWpIdSql('list_wal.wp_post_id'));
                });
        }, 'articles_in_category_count');
    }

    public static function appendWpSyncQueueMetaSelect(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            static::applyListSelectColumns($query);
        }

        return $query->selectSub(function ($subQuery): void {
            $subQuery->from('article_meta')
                ->select('meta_value')
                ->whereColumn('article_meta.article_id', 'articles.id')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                ->limit(1);
        }, 'wp_sync_queue_meta');
    }

    public static function applyWpSyncQueueScope(Builder $query): Builder
    {
        return static::applyWpSyncQueueUnreviewedScope($query)->whereIn('articles.id', function ($subQuery): void {
            $subQuery->select('article_id')
                ->from('article_meta')
                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                ->where(function ($statusQuery): void {
                    ArticleWpSyncQueueService::applyUnfinishedMetaStatusConstraints($statusQuery);
                });
        });
    }

    public static function applyWpSyncQueueListScope(Builder $query): Builder
    {
        // Dedicated /articles/queue page — same unfinished definition as list tab badge.
        return static::applyWpSyncQueueScope($query);
    }

    public static function applyWpSyncQueueUnreviewedScope(Builder $query): Builder
    {
        return static::applyNotApprovedForPublishScope($query);
    }

    /**
     * Articles not approved and not archived (SEO queue / CM visibility).
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyNotApprovedForPublishScope(Builder $query): Builder
    {
        return $query->where(function (Builder $sub): void {
            $sub->whereNull('articles.review_status')
                ->orWhereNotIn('articles.review_status', [
                    ArticleReviewStatus::Approved->value,
                    ArticleReviewStatus::Archived->value,
                ]);
        });
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyApprovedReviewScope(Builder $query): Builder
    {
        return $query->where('articles.review_status', ArticleReviewStatus::Approved->value);
    }

    public static function formatWpSyncQueueDateTime(?string $iso): ?string
    {
        return SeoDisplayTimezone::format($iso);
    }

    public static function queueTable(Table $table): Table
    {
        return $table
            ->recordAction('edit')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('seo-content-ai::filament.article_list.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (SeoArticle $record): ?string {
                        if (filled($record->slug)) {
                            return '/'.ltrim((string) $record->slug, '/');
                        }
                        $wpId = (int) ($record->getAttributes()['wp_post_id'] ?? $record->wordpressLink?->wp_post_id ?? 0);

                        return $wpId > 0 ? 'WP ID: '.$wpId : null;
                    }),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label(__('seo-content-ai::filament.article_list.domain'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('wp_sync_queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->badge()
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueStatus($record))
                    ->formatStateUsing(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                        ArticleWpSyncQueueService::STATUS_CANCELLED => __('seo-content-ai::filament.article_list.queue_status_cancelled'),
                        ArticleWpSyncQueueService::STATUS_STALE => __('seo-content-ai::filament.article_list.queue_status_stale'),
                        default => $state ?: '—',
                    })
                    ->color(fn (?string $state): string => match ((string) $state) {
                        ArticleWpSyncQueueService::STATUS_PENDING => 'warning',
                        ArticleWpSyncQueueService::STATUS_PROCESSING => 'info',
                        ArticleWpSyncQueueService::STATUS_COMPLETED => 'success',
                        ArticleWpSyncQueueService::STATUS_FAILED, ArticleWpSyncQueueService::STATUS_STALE => 'danger',
                        ArticleWpSyncQueueService::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('wp_sync_queue_queued_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_queued_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'queued_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_started_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_started_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'started_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_finished_at')
                    ->label(__('seo-content-ai::filament.article_list.queue_finished_at'))
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::formatWpSyncQueueDateTime(
                        static::resolveWpSyncQueueField($record, 'finished_at'),
                    )),
                Tables\Columns\TextColumn::make('wp_sync_queue_error')
                    ->label(__('seo-content-ai::filament.article_list.queue_error'))
                    ->wrap()
                    ->limit(60)
                    ->getStateUsing(fn (SeoArticle $record): ?string => static::resolveWpSyncQueueField($record, 'error'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('articles.updated_at', 'desc')
            ->filters([
                SelectFilter::make('queue_status')
                    ->label(__('seo-content-ai::filament.article_list.queue_status'))
                    ->options([
                        ArticleWpSyncQueueService::STATUS_PENDING => __('seo-content-ai::filament.article_list.queue_status_pending'),
                        ArticleWpSyncQueueService::STATUS_PROCESSING => __('seo-content-ai::filament.article_list.queue_status_processing'),
                        ArticleWpSyncQueueService::STATUS_COMPLETED => __('seo-content-ai::filament.article_list.queue_status_completed'),
                        ArticleWpSyncQueueService::STATUS_FAILED => __('seo-content-ai::filament.article_list.queue_status_failed'),
                    ])
                    ->native(false)
                    ->placeholder(__('seo-content-ai::filament.article_list.queue_all_statuses'))
                    ->query(function (Builder $query, array $data): void {
                        $status = (string) ($data['value'] ?? '');
                        if ($status === '') {
                            return;
                        }

                        $query->whereIn('articles.id', function ($subQuery) use ($status): void {
                            $subQuery->select('article_id')
                                ->from('article_meta')
                                ->where('meta_key', ArticleWpSyncQueueService::META_KEY)
                                ->where('meta_value', 'like', '%"status":"'.$status.'"%');
                        });
                    }),
            ])
            ->actions(static::getArticleQueueTableRowActions())
            ->bulkActions([]);
    }

    public static function resolveWpSyncQueuePayload(SeoArticle $record): array
    {
        $raw = $record->wp_sync_queue_meta ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            $record->loadMissing('articleMetas');
            $raw = (string) ($record->articleMetas
                ->firstWhere('meta_key', ArticleWpSyncQueueService::META_KEY)?->meta_value ?? '');
        }

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function resolveWpSyncQueueStatus(SeoArticle $record): ?string
    {
        // Heal meta mồ côi khi list/badge đọc status (không chỉ chờ watchdog).
        try {
            app(\Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService::class)
                ->healArticleOrphanMeta($record);
            $record->unsetRelation('articleMetas');
            unset($record->wp_sync_queue_meta);
        } catch (\Throwable) {
            // ignore heal failures in list rendering
        }

        $status = (string) (static::resolveWpSyncQueuePayload($record)['status'] ?? '');

        return $status !== '' ? $status : null;
    }

    public static function resolveWpSyncQueueField(SeoArticle $record, string $field): ?string
    {
        $value = static::resolveWpSyncQueuePayload($record)[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<array{
     *     date: string,
     *     date_label: string,
     *     count: int,
     *     articles: list<array{id: int, title: string, reviewed_time: string, edit_url: string, view_url: string|null}>
     * }>
     */
    public static function buildReviewedArticlesGrouped(): array
    {
        $query = static::applyApprovedReviewScope(SeoArticle::query())
            ->whereNotNull('reviewed_at')
            ->whereNotIn('type', ['category', 'product_category'])
            ->where('status', '!=', 'trash')
            ->with(['site', 'articleMetas'])
            ->orderByDesc('reviewed_at');

        static::applyExcludeSkipSeoAuditScope($query);

        if (SeoAccessControl::shouldScopeToAccountOwner() && ! SeoAccessControl::isContentManager()) {
            SeoAccessControl::applyAccessibleSiteScope($query, 'articles.site_id');
        }

        if (SeoAccessControl::shouldApplyGlobalSiteScope()) {
            $query->where('articles.site_id', (int) SeoAccessControl::globalSiteId());
        }

        if (SeoAccessControl::isContentManager()) {
            static::applyContentManagerOwnershipScope($query);
        }

        /** @var array<string, array{date: string, date_label: string, count: int, articles: list<array{id: int, title: string, reviewed_time: string, edit_url: string, view_url: string|null}>}> $grouped */
        $grouped = [];

        foreach ($query->get() as $article) {
            if (! $article instanceof SeoArticle || $article->reviewed_at === null) {
                continue;
            }

            $reviewedAt = $article->reviewed_at instanceof Carbon
                ? $article->reviewed_at
                : Carbon::parse((string) $article->reviewed_at);

            $dateKey = $reviewedAt->toDateString();

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $reviewedAt->translatedFormat('d/m/Y'),
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $grouped[$dateKey]['articles'][] = [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
                'reviewed_time' => $reviewedAt->format('H:i'),
                'edit_url' => static::getUrl('edit', ['record' => $article]),
                'view_url' => static::resolveWordPressPermalink($article),
            ];
            $grouped[$dateKey]['count']++;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleTableRowActionsMerged(): array
    {
        if (static::isArticlesQueueTab()) {
            return static::getArticleQueueTableRowActions();
        }

        return static::getArticleTableRowActions();
    }

    public static function isArticlesQueueTab(): bool
    {
        if (request()->query('tab') === Pages\ListArticles::TAB_QUEUE) {
            return true;
        }

        $livewire = \Livewire\Livewire::current();

        if ($livewire instanceof Pages\ListArticleSyncQueue) {
            return true;
        }

        return $livewire instanceof Pages\ListArticles
            && $livewire->contentTab === Pages\ListArticles::TAB_QUEUE;
    }

    public static function isArticlesSkippedTab(): bool
    {
        if (request()->query('tab') === Pages\ListArticles::TAB_SKIPPED) {
            return true;
        }

        $livewire = \Livewire\Livewire::current();

        return $livewire instanceof Pages\ListArticles
            && $livewire->contentTab === Pages\ListArticles::TAB_SKIPPED;
    }

    /**
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleQueueTableRowActions(): array
    {
        return [
            static::makeApproveArticleTableAction(),
            Tables\Actions\Action::make('resync_sync_queue')
                ->icon('heroicon-o-arrow-path')
                ->iconButton()
                ->color('warning')
                ->tooltip(__('seo-content-ai::filament.article_list.queue_resync'))
                ->visible(fn (SeoArticle $record): bool => in_array(
                    static::resolveWpSyncQueueStatus($record),
                    [
                        ArticleWpSyncQueueService::STATUS_PENDING,
                        ArticleWpSyncQueueService::STATUS_FAILED,
                        ArticleWpSyncQueueService::STATUS_PROCESSING,
                        ArticleWpSyncQueueService::STATUS_STALE,
                        ArticleWpSyncQueueService::STATUS_CANCELLED,
                    ],
                    true,
                ))
                ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue $livewire): void {
                    $livewire->resyncArticleSyncQueue((int) $record->getKey());
                }),
            Tables\Actions\Action::make('cancel_sync_queue')
                ->icon('heroicon-o-x-circle')
                ->iconButton()
                ->color('danger')
                ->tooltip(__('seo-content-ai::filament.article_list.queue_cancel'))
                ->visible(fn (SeoArticle $record): bool => in_array(
                    static::resolveWpSyncQueueStatus($record),
                    [
                        ArticleWpSyncQueueService::STATUS_PENDING,
                        ArticleWpSyncQueueService::STATUS_FAILED,
                        ArticleWpSyncQueueService::STATUS_PROCESSING,
                        ArticleWpSyncQueueService::STATUS_COMPLETED,
                        ArticleWpSyncQueueService::STATUS_STALE,
                    ],
                    true,
                ))
                ->requiresConfirmation()
                ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue $livewire): void {
                    $livewire->cancelArticleSyncQueue((int) $record->getKey());
                }),
            Tables\Actions\EditAction::make()
                ->iconButton(),
        ];
    }

    /**
     * MariaDB không hỗ trợ CAST(... AS JSON); dùng FIND_IN_SET trên mảng ID phẳng trong meta_value.
     */
    private static function articleMetaContainsCategoryWpIdSql(string $categoryWpIdExpression = '?'): string
    {
        return sprintf(
            'FIND_IN_SET(%s, REPLACE(REPLACE(REPLACE(`meta_value`, " ", ""), "[", ""), "]", "")) > 0',
            $categoryWpIdExpression,
        );
    }

    /**
     * @return array<int|string, string>
     */
    public static function buildCategoryFilterOptions(Pages\ListArticles $livewire): array
    {
        $siteId = (int) ($livewire->tableFilters['site_id']['value'] ?? SeoAccessControl::globalSiteId() ?? 0);

        $query = SeoArticle::query()
            ->leftJoin('wordpress_article_links as wal_cat', 'wal_cat.article_id', '=', 'articles.id')
            ->whereIn('articles.type', ['category', 'product_category'])
            ->where('wal_cat.wp_post_id', '>', 0)
            ->orderBy('articles.title');

        $postType = trim((string) ($livewire->tableFilters['post_type']['value'] ?? ''));
        if ($postType === 'post') {
            $query->where('articles.type', 'category');
        } elseif ($postType === 'product') {
            $query->where('articles.type', 'product_category');
        } elseif ($postType === 'page') {
            return [];
        }

        if ($siteId > 0) {
            $query->where('articles.site_id', $siteId);
        } elseif (SeoAccessControl::shouldScopeToAccountOwner()) {
            SeoAccessControl::applyAccessibleSiteScope($query, 'articles.site_id');
        }

        return $query
            ->get([
                'articles.id',
                'wal_cat.wp_post_id as wp_post_id',
                'articles.title',
                'articles.type',
            ])
            ->mapWithKeys(function (SeoArticle $term): array {
                $wpId = (int) ($term->wp_post_id ?? 0);
                if ($wpId <= 0) {
                    return [];
                }

                $title = trim((string) ($term->title ?? ''));
                $label = $title !== '' ? $title : __('seo-content-ai::filament.article_list.category_fallback', ['id' => $wpId]);

                if ($term->type === 'product_category') {
                    $label = '[SP] '.$label;
                }

                return [$wpId => $label];
            })
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyArticleAccessScopes(
            parent::getEloquentQuery()->with(static::articleEagerLoads()),
            includeGlobalSiteScope: true,
            includeReviewScope: true,
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Trang edit/view cần ĐẦY ĐỦ articleMetas (seo_meta_description, wp_product_gallery...).
        // Không dùng whitelist articleEagerLoads() ở đây — whitelist đó chỉ dành cho trang list,
        // vì relation đã loaded sẽ khiến mọi loadMissing('articleMetas') sau đó bị bỏ qua.
        // Global domain = UI list filter only — không chặn mở/sửa bài thuộc domain khác.
        return static::applyArticleAccessScopes(
            parent::getEloquentQuery()->with(['user', 'site', 'articleMetas']),
            includeGlobalSiteScope: false,
            includeReviewScope: false,
            includeContentManagerOwnershipScope: false,
        );
    }

    /**
     * Filament core resolveRecordRouteBinding() luôn gọi getEloquentQuery() (có global site scope).
     * Override để dùng query không lọc global domain — tránh 404 khi sửa bài khác domain đang chọn.
     */
    public static function resolveRecordRouteBinding(int|string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(
                static::getRecordRouteBindingEloquentQuery(),
                $key,
                static::getRecordRouteKeyName(),
            )
            ->first();
    }

    /**
     * Columns needed for Article List rows — excludes body/blocks/editor_document (heavy payloads).
     * Extension fields come from owner tables (joined in applyListExtensionJoins) with legacy aliases.
     *
     * @return list<string>
     */
    public static function listSelectColumns(): array
    {
        return [
            'articles.id',
            'articles.user_id',
            'articles.site_id',
            'articles.title',
            'articles.slug',
            'articles.language',
            'articles.status',
            'articles.type',
            'list_sap.seo_score as seo_score',
            'list_sap.skip_seo_score as skip_seo_score',
            'list_sap.internal_link_count as internal_link_count',
            'list_sap.external_link_count as external_link_count',
            'list_wal.wp_post_id as wp_post_id',
            'list_pas.published_at as published_at',
            'articles.reviewed_at',
            'articles.review_status',
            'list_cai.archived_at as content_archived_at',
            'articles.created_at',
            'articles.updated_at',
            'articles.deleted_at',
            'list_ams.display_url as featured_thumb_url',
            'list_ams.media_id as featured_media_id',
            'list_ams.status as featured_image_status',
            'list_ams.source as featured_image_source',
        ];
    }

    /**
     * Left-join extension SoT tables for list select / sort / filter (idempotent aliases).
     */
    public static function applyListExtensionJoins(Builder $query): Builder
    {
        if (! static::queryHasJoinAlias($query, 'list_sap')) {
            $query->leftJoin('seo_article_profiles as list_sap', 'list_sap.article_id', '=', 'articles.id');
        }

        if (! static::queryHasJoinAlias($query, 'list_wal')) {
            $query->leftJoin('wordpress_article_links as list_wal', 'list_wal.article_id', '=', 'articles.id');
        }

        if (! static::queryHasJoinAlias($query, 'list_pas')) {
            $query->leftJoin('publishing_article_states as list_pas', 'list_pas.article_id', '=', 'articles.id');
        }

        if (! static::queryHasJoinAlias($query, 'list_ams')) {
            $query->leftJoin('article_media_states as list_ams', function ($join): void {
                $join->on('list_ams.article_id', '=', 'articles.id')
                    ->where('list_ams.role', '=', 'featured');
            });
        }

        if (! static::queryHasJoinAlias($query, 'list_cai')) {
            $query->leftJoin('seo_content_archive_items as list_cai', 'list_cai.article_id', '=', 'articles.id');
        }

        return $query;
    }

    private static function queryHasJoinAlias(Builder $query, string $alias): bool
    {
        foreach ($query->getQuery()->joins ?? [] as $join) {
            $table = strtolower((string) $join->table);
            if ($table === strtolower($alias)
                || str_ends_with($table, ' as '.strtolower($alias))) {
                return true;
            }
        }

        return false;
    }

    public static function applyListSelectColumns(Builder $query): Builder
    {
        static::applyListExtensionJoins($query);

        if ($query->getQuery()->columns === null) {
            $query->select(static::listSelectColumns());
        }

        return $query;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function articleEagerLoads(): array
    {
        return [
            'user',
            'site',
            'faqs',
            'wordpressLink',
            'articleMetas' => static fn ($query) => $query->whereIn('meta_key', [
                'seo_focus_keyword',
                'seo_rule_violations',
                self::META_SKIP_SEO_AUDIT,
                'wp_post_images',
                'wp_featured_image_url',
                ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID,
                'wp_permalink',
                ArticleWordPressSyncFlagService::META_WP_DATA_OUT_OF_SYNC,
            ]),
        ];
    }

    public static function applyArticleAccessScopes(
        Builder $query,
        bool $includeGlobalSiteScope = true,
        bool $includeReviewScope = true,
        bool $includeContentManagerOwnershipScope = true,
    ): Builder {
        if (SeoAccessControl::shouldScopeToAccountOwner() && ! SeoAccessControl::isContentManager()) {
            SeoAccessControl::applyAccessibleSiteScope($query, 'articles.site_id');
        }

        if ($includeGlobalSiteScope && SeoAccessControl::shouldApplyGlobalSiteScope()) {
            $query->where('articles.site_id', (int) SeoAccessControl::globalSiteId());
        }

        if ($includeContentManagerOwnershipScope && SeoAccessControl::isContentManager()) {
            static::applyContentManagerOwnershipScope($query);
        }

        if ($includeReviewScope
            && ! SeoAccessControl::isContentManager()
            && ! SeoAccessControl::canAccessManagerFeatures()) {
            $query->where(function (Builder $sub): void {
                static::applyNotApprovedForPublishScope($sub);
            });
        }

        return $query;
    }

    public static function canContentManagerAccessArticle(SeoArticle $article): bool
    {
        return static::canContentManagerAccessArticleId((int) $article->getKey());
    }

    public static function canContentManagerAccessArticleId(int $articleId): bool
    {
        if (! SeoAccessControl::isContentManager()) {
            return true;
        }

        return static::applyContentManagerOwnershipScope(
            SeoArticle::query()->whereKey($articleId),
        )->exists();
    }

    private static function applyContentManagerOwnershipScope(Builder $query): Builder
    {
        $userId = (int) auth()->id();

        return $query->where(function (Builder $ownership) use ($userId): void {
            $ownership
                ->where('articles.user_id', $userId)
                ->orWhereIn('articles.id', SeoProjectTask::query()
                    ->whereNotNull('article_id')
                    ->whereIn('project_id', SeoProject::query()
                        ->where('user_id', $userId)
                        ->select('id'))
                    ->select('article_id'));
        });
    }

    public static function syncGlobalSiteForArticle(SeoArticle $article): void
    {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }

        if (SeoAccessControl::globalSiteId() === $siteId) {
            return;
        }

        SeoAccessControl::setGlobalSiteId($siteId);
    }

    private static function resolveThumbnailUrl(SeoArticle $record): ?string
    {
        return app(ArticleFeaturedImageProjection::class)->forList($record)['url'];
    }

    private static function resolveWordPressPermalink(SeoArticle $record): ?string
    {
        $record->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($record->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
        $slug = trim((string) ($record->slug ?? ''));

        $resolved = app(WordPressPermalinkBuilder::class)->resolve($record, $cached, $slug !== '' ? $slug : null);
        if ($resolved !== '') {
            return $resolved;
        }

        $site = $record->site;
        if (! $site instanceof Site) {
            return null;
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '' || $slug === '') {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($slug, '/');
    }

    /**
     * Hàng 1: xem WP · skip SEO · duyệt — Hàng 2: gán dự án · sửa · xóa (lưới 3 cột trên list).
     *
     * @return array<int, Tables\Actions\Action>
     */
    public static function getArticleTableRowActions(): array
    {
        return [
            Tables\Actions\Action::make('quick_view_wp')
                ->icon('heroicon-o-eye')
                ->iconButton()
                ->tooltip(__('seo-content-ai::filament.article_list.view_on_wordpress'))
                ->url(fn (SeoArticle $record): string => static::resolveWordPressPermalink($record) ?? '#')
                ->openUrlInNewTab()
                ->disabled(fn (SeoArticle $record): bool => blank(static::resolveWordPressPermalink($record))),
            Tables\Actions\Action::make('toggle_skip_seo_audit')
                ->icon(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record)
                    ? 'heroicon-o-eye'
                    : 'heroicon-o-eye-slash')
                ->iconButton()
                ->color(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record) ? 'warning' : 'gray')
                ->tooltip(fn (SeoArticle $record): string => static::articleIsSkipSeoAudit($record)
                    ? __('seo-content-ai::filament.article_list.unskip_seo_audit')
                    : __('seo-content-ai::filament.article_list.skip_seo_audit'))
                ->action(function (SeoArticle $record): void {
                    $skipped = static::toggleSkipSeoAudit($record);

                    Notification::make()
                        ->title(
                            $skipped
                                ? __('seo-content-ai::filament.article_list.seo_audit_skipped_on')
                                : __('seo-content-ai::filament.article_list.seo_audit_skipped_off'),
                        )
                        ->success()
                        ->send();
                }),
            static::makeApproveArticleTableAction(),
            Tables\Actions\Action::make('view_content_project_runs')
                ->icon('heroicon-o-folder-open')
                ->iconButton()
                ->color('info')
                ->tooltip(__('seo-content-ai::filament.projects.open_project_items'))
                ->visible(function (SeoArticle $record): bool {
                    return static::articleAssignedContentProjectId($record) !== null;
                })
                ->url(function (SeoArticle $record): ?string {
                    $projectId = static::articleAssignedContentProjectId($record);
                    if ($projectId === null) {
                        return null;
                    }

                    $project = SeoProject::query()->find($projectId);

                    return $project instanceof SeoProject
                        ? SeoProjectResource::getProjectWorkspaceUrl($project)
                        : null;
                }),
            AssignToContentProjectActionFactory::tableRowAction(
                resolvePayload: function (Model $record): array {
                    /** @var SeoArticle $record */
                    $siteId = static::resolveArticleSiteId($record);

                    return AssignToContentProjectContract::articlePayload(
                        source: 'article_table',
                        articleIds: [(int) $record->id],
                        siteId: $siteId,
                        options: [
                            'show_quick_create' => true,
                            'show_article_fields' => true,
                            'show_keyword_override' => true,
                            'show_title_override' => true,
                        ],
                    );
                },
                visible: fn (SeoArticle $record): bool => SeoAccessControl::canMutateInSeoPanel()
                    && ! static::articleIsInContentProject($record)
                    && ! static::articleIsContentArchived($record),
            ),
            Tables\Actions\EditAction::make()
                ->iconButton(),
            Tables\Actions\DeleteAction::make()
                ->iconButton(),
        ];
    }

    public static function runApproveArticleAction(SeoArticle $record): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $service = app(ArticleReviewService::class);
        $actions = $service->availableActions($record, $user);
        $next = $actions[0] ?? null;

        if ($next === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_review.errors.invalid_transition'))
                ->warning()
                ->send();

            return;
        }

        $actionType = ArticleReviewActionType::from($next['type']);

        try {
            $review = $service->performAction($record, $user, $actionType);
        } catch (ArticleReviewException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title((string) __('seo-content-ai::filament.article_review.success.'.$review->action_type))
            ->success()
            ->send();
    }

    public static function makeApproveArticleTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve_article')
            ->icon('heroicon-o-check-badge')
            ->iconButton()
            ->color(fn (SeoArticle $record): string => app(ArticleReviewService::class)->resolveStatus($record) === ArticleReviewStatus::Draft
                ? 'gray'
                : 'success')
            ->tooltip(function (SeoArticle $record): string {
                $user = auth()->user();
                $next = $user instanceof User
                    ? (app(ArticleReviewService::class)->availableActions($record, $user)[0] ?? null)
                    : null;

                if ($next !== null) {
                    return (string) $next['quick_label'];
                }

                $status = app(ArticleReviewService::class)->resolveStatus($record);

                return (string) __('seo-content-ai::filament.article_review.badge.'.$status->value);
            })
            ->visible(function (SeoArticle $record): bool {
                $user = auth()->user();

                return $user instanceof User
                    && app(ArticleReviewService::class)->availableActions($record, $user) !== [];
            })
            ->requiresConfirmation()
            ->modalDescription(function (SeoArticle $record): string {
                $user = auth()->user();
                $next = $user instanceof User
                    ? (app(ArticleReviewService::class)->availableActions($record, $user)[0] ?? null)
                    : null;

                return $next !== null ? (string) $next['label'] : '';
            })
            ->action(function (SeoArticle $record, Pages\ListArticles|Pages\ListArticleSyncQueue|null $livewire = null): void {
                static::runApproveArticleAction($record);

                if ($livewire instanceof Pages\ListArticles || $livewire instanceof Pages\ListArticleSyncQueue) {
                    $livewire->resetTable();
                }
            });
    }

    public static function submitStaffEditingComplete(SeoArticle $article, ?User $user = null): void
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $reviewService = app(ArticleReviewService::class);
        $alreadySubmitted = $reviewService->resolveStatus($article) === ArticleReviewStatus::Approved;

        try {
            $result = app(\Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher::class)->dispatch(
                'article.approve',
                [
                    'article_id' => (int) $article->id,
                    'actor_user_id' => (int) $user->id,
                ],
                \Omnichannel\Addons\Agent\Automation\Data\ActionContext::fromArray([
                    'origin' => 'filament.article_resource',
                    'actor_id' => (int) $user->id,
                    'site_id' => (int) ($article->site_id ?? 0) ?: null,
                ]),
            );
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.staff_submit_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result->success) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.staff_submit_failed'))
                ->body((string) ($result->error['message'] ?? 'Approval failed.'))
                ->danger()
                ->send();

            return;
        }

        $alreadySubmitted = (bool) ($result->output['already_approved'] ?? $alreadySubmitted);
        $projectName = (string) ($result->output['project_name'] ?? '');
        if ($projectName === '') {
            $projectId = (int) ($result->output['project_id'] ?? 0);
            $projectName = $projectId > 0
                ? (string) (SeoProject::query()->whereKey($projectId)->value('name') ?? $projectId)
                : '';
        }

        if ($alreadySubmitted) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.staff_mark_editing_done_already'))
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.staff_mark_editing_done_success'))
            ->body(__('seo-content-ai::filament.article_list.staff_mark_editing_done_success_body', [
                'title' => (string) $article->title,
                'project' => $projectName,
            ]))
            ->success()
            ->send();
    }

    /**
     * Bật/tắt bỏ qua SEO Audit + ẩn khỏi Article list. Trả về true nếu sau thao tác đang skip.
     */
    public static function toggleSkipSeoAudit(SeoArticle $article): bool
    {
        if (static::articleIsSkipSeoAudit($article)) {
            $article->articleMetas()
                ->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->delete();
            $article->unsetRelation('articleMetas');

            return false;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SKIP_SEO_AUDIT],
            ['meta_value' => '1'],
        );
        $article->unsetRelation('articleMetas');

        return true;
    }

    public static function articleIsSkipSeoAudit(SeoArticle $article): bool
    {
        if ($article->relationLoaded('articleMetas')) {
            return $article->articleMetas
                ->contains(static function ($meta): bool {
                    if ((string) ($meta->meta_key ?? '') !== self::META_SKIP_SEO_AUDIT) {
                        return false;
                    }

                    $value = strtolower(trim((string) ($meta->meta_value ?? '')));

                    return in_array($value, ['1', 'true'], true);
                });
        }

        return $article->articleMetas()
            ->where('meta_key', self::META_SKIP_SEO_AUDIT)
            ->where(function (Builder $valueQuery): void {
                $valueQuery
                    ->where('meta_value', '1')
                    ->orWhere('meta_value', 1)
                    ->orWhere('meta_value', 'true');
            })
            ->exists();
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyExcludeSkipSeoAuditScope(Builder $query): Builder
    {
        return $query->whereDoesntHave('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->where(function (Builder $valueQuery): void {
                    $valueQuery
                        ->where('meta_value', '1')
                        ->orWhere('meta_value', 1)
                        ->orWhere('meta_value', 'true');
                });
        });
    }

    /**
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applyOnlySkipSeoAuditScope(Builder $query): Builder
    {
        return $query->whereHas('articleMetas', static function (Builder $meta): void {
            $meta->where('meta_key', self::META_SKIP_SEO_AUDIT)
                ->where(function (Builder $valueQuery): void {
                    $valueQuery
                        ->where('meta_value', '1')
                        ->orWhere('meta_value', 1)
                        ->orWhere('meta_value', 'true');
                });
        });
    }

    /**
     * @deprecated Dùng toggleSkipSeoAudit — giữ để tương thích chỗ còn gọi cũ.
     */
    public static function toggleSkipSeoScore(SeoArticle $article): bool
    {
        return static::toggleSkipSeoAudit($article);
    }

    public static function deleteLocalMediaForArticle(SeoArticle $article): int
    {
        $mediaRows = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->get(['id', 'path']);

        $mediaIds = [];
        foreach ($mediaRows as $media) {
            $mediaIds[] = (int) $media->id;
            $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
            if ($path !== '' && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        if ($mediaIds !== []) {
            SeoMediaProcessingHistory::query()
                ->whereIn('media_ref_id', $mediaIds)
                ->where('source', SeoMediaProcessingHistory::SOURCE_LOCAL)
                ->delete();

            SeoMedia::query()->whereIn('id', $mediaIds)->delete();
        }

        // Same article cleanup lifecycle: reviewed product reviews (WP already source of truth).
        app(\Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewPendingRepository::class)
            ->deleteReviewedForArticle($article);

        return count($mediaIds);
    }

    public static function resolveArticleSiteId(SeoArticle $article): ?int
    {
        $siteId = (int) ($article->site_id ?? 0);

        if ($siteId > 0) {
            return $siteId;
        }

        return SeoAccessControl::globalSiteId();
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public static function resolveBulkArticlesSiteId(Collection $records): ?int
    {
        $siteIds = $records
            ->filter(static fn (mixed $record): bool => $record instanceof SeoArticle)
            ->map(static fn (SeoArticle $article): ?int => static::resolveArticleSiteId($article))
            ->filter(static fn (?int $siteId): bool => $siteId !== null && $siteId > 0)
            ->unique()
            ->values();

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }

    public static function resolveDirectAssignContentProjectId(?int $recordSiteId = null): ?int
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        $projectId = SeoAccessControl::globalContentProjectId();

        if ($globalSiteId === null || $projectId === null) {
            return null;
        }

        if ($recordSiteId !== null && $recordSiteId > 0 && $recordSiteId !== $globalSiteId) {
            return null;
        }

        return $projectId;
    }

    public static function normalizeAssignTaskType(mixed $value): string
    {
        return SeoProjectTask::normalizeType($value);
    }

    /**
     * @param  SupportCollection<int, SeoArticle>|Collection<int, SeoArticle>  $records
     * @param  array<string, mixed>  $data
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignArticlesFromFormData(
        SupportCollection $records,
        int $projectId,
        array $data,
    ): array {
        return static::assignArticlesToContentProject(
            $records,
            $projectId,
            static::normalizeAssignTaskType($data['type'] ?? null),
            is_string($data['rewrite_mode'] ?? null) ? $data['rewrite_mode'] : null,
            is_string($data['rewrite_notes'] ?? null) ? $data['rewrite_notes'] : null,
            is_string($data['focus_keyword'] ?? null)
                ? $data['focus_keyword']
                : (is_string($data['keyword'] ?? null) ? $data['keyword'] : null),
            is_string($data['title'] ?? null) ? $data['title'] : null,
            (bool) ($data['ignore_monthly_capacity'] ?? false),
        );
    }

    public static function quickCreateContentProject(int $siteId, ?int $userId = null): SeoProject
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_domain'));
        }

        $userId = (int) ($userId ?: auth()->id());
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('seo-content-ai::filament.article_list.quick_create_content_project_no_user'));
        }

        $currentMonth = Carbon::now()->startOfMonth();
        $targetMonth = $currentMonth->copy();

        $existingProject = SeoProject::query()
            ->where('site_id', $siteId)
            ->where('user_id', $userId)
            ->whereDate('month', $currentMonth->format('Y-m-d'))
            ->exists();

        if ($existingProject) {
            $targetMonth = $currentMonth->copy()->addMonth();
        }

        return SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth($targetMonth),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $targetMonth->format('Y-m-d'),
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'description' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function contentProjectOptions(?int $siteId = null): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        $query = SeoProject::query()
            ->with(['site', 'user'])
            ->orderByDesc('month')
            ->orderBy('id')
            ->where('site_id', $siteId)
            ->where(function ($builder): void {
                $builder->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            });

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        }

        return $query
            ->get()
            ->filter(fn (SeoProject $project): bool => $project->canRegisterMoreTasks())
            ->mapWithKeys(function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
                $domain = trim((string) ($project->site?->domain ?? ''));
                $writer = $project->user instanceof User
                    ? SeoProjectResource::formatUserSelectLabel($project->user)
                    : '';

                return [
                    (int) $project->id => sprintf(
                        '%s · %s · %s (%s, còn %d)',
                        (string) $project->name,
                        $domain !== '' ? $domain : '—',
                        $writer !== '' ? $writer : '—',
                        $project->monthCarbon()->format('m/Y'),
                        $remaining,
                    ),
                ];
            })
            ->all();
    }

    /**
     * Vocabulary Plan: soft-full projects stay selectable; archived / hard-closed stay out.
     *
     * @return array<int, string>
     */
    public static function contentProjectOptionsForVocabularyPlanning(
        ?int $siteId = null,
        ?int $includeSelectedProjectId = null,
    ): array {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return [];
        }

        $query = SeoProject::query()
            ->with(['site', 'user'])
            ->orderByDesc('month')
            ->orderBy('id')
            ->where('site_id', $siteId)
            ->whereNull('archived_at')
            ->where(function ($builder): void {
                $builder->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            });

        if (SeoAccessControl::isContentManager()) {
            $query->where('user_id', (int) auth()->id());
        }

        $options = $query
            ->get()
            ->mapWithKeys(function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
                $domain = trim((string) ($project->site?->domain ?? ''));
                $capacityLabel = $remaining > 0
                    ? sprintf('còn %d', $remaining)
                    : 'đầy';

                return [
                    (int) $project->id => sprintf(
                        '%s · %s (%s, %s)',
                        (string) $project->name,
                        $domain !== '' ? $domain : '—',
                        $project->monthCarbon()->format('m/Y'),
                        $capacityLabel,
                    ),
                ];
            })
            ->all();

        if (
            $includeSelectedProjectId !== null
            && $includeSelectedProjectId > 0
            && ! array_key_exists($includeSelectedProjectId, $options)
        ) {
            $selected = SeoProject::query()
                ->with(['site'])
                ->whereKey($includeSelectedProjectId)
                ->where('site_id', $siteId)
                ->whereNull('archived_at')
                ->first();
            if ($selected instanceof SeoProject) {
                $domain = trim((string) ($selected->site?->domain ?? ''));
                $remaining = $selected->remainingTaskCapacity();
                $options[(int) $selected->id] = sprintf(
                    '%s · %s (%s, %s)',
                    (string) $selected->name,
                    $domain !== '' ? $domain : '—',
                    $selected->monthCarbon()->format('m/Y'),
                    $remaining > 0 ? sprintf('còn %d', $remaining) : 'đầy',
                );
            }
        }

        return $options;
    }

    public static function resolveArticleProjectSourceContent(SeoArticle $article): string
    {
        $sourceContent = trim((string) ($article->title ?? ''));
        if ($sourceContent === '') {
            return 'Article #'.(int) $article->id;
        }

        return $sourceContent;
    }

    public static function resolveAssignSourceContent(SeoArticle $article, string $taskType): string
    {
        if (static::normalizeAssignTaskType($taskType) === SeoProjectTask::TYPE_NEW_KEYWORD) {
            $keyword = trim((string) (app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article) ?? ''));
            if ($keyword !== '') {
                return $keyword;
            }
        }

        return static::resolveArticleProjectSourceContent($article);
    }

    /**
     * SEO Audit candidates only: exclude reviewed + bài đã gắn Content Project (`article_id`)
     * + bài đã archive khỏi content project (`content_archived_at`)
     * + bài có meta skip_seo_audit.
     *
     * @param  Builder<SeoArticle>  $query
     * @return Builder<SeoArticle>
     */
    public static function applySeoAuditCandidateScope(Builder $query): Builder
    {
        static::applyNotApprovedForPublishScope($query);

        $query->notContentArchived();

        // Chỉ match article_id — assign rewrite/improve luôn set cột này.
        // Không OR theo title: correlated scan trên cả domain dễ timeout → scan_failed.
        $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('seo_project_tasks')
                ->whereColumn('seo_project_tasks.article_id', 'articles.id');
        });

        return static::applyExcludeSkipSeoAuditScope($query);
    }

    public static function articleIsContentArchived(SeoArticle $article): bool
    {
        // Shared across list rows (raw-selected `content_archived_at` alias) and
        // plain-loaded article instances (no alias) — check raw attribute first.
        if (array_key_exists('content_archived_at', $article->getAttributes())) {
            return $article->getAttributes()['content_archived_at'] !== null;
        }

        return $article->relationLoaded('contentArchiveItem')
            ? $article->contentArchiveItem?->archived_at !== null
            : $article->contentArchiveItem()->exists();
    }

    public static function articleAssignedContentProjectId(SeoArticle $article): ?int
    {
        // Active Content Project only — archived project association is historical/reporting.
        $directProjectId = SeoProjectTask::query()
            ->active()
            ->where('article_id', (int) $article->id)
            ->whereHas('project', static function (Builder $query): void {
                $query->whereNull('archived_at');
            })
            ->value('project_id');
        if ($directProjectId !== null) {
            return (int) $directProjectId;
        }

        $needle = mb_strtolower(trim(static::resolveArticleProjectSourceContent($article)));
        $articleSiteId = static::resolveArticleSiteId($article) ?? 0;

        $query = SeoProjectTask::query()
            ->active()
            ->whereIn('type', [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE])
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->whereHas('project', static function (Builder $builder): void {
                $builder->whereNull('archived_at');
            });

        if ($articleSiteId > 0) {
            $query->where(function (Builder $builder) use ($articleSiteId): void {
                $builder
                    ->where('site_id', $articleSiteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public static function articleIsInContentProject(SeoArticle $article): bool
    {
        return static::articleAssignedContentProjectId($article) !== null;
    }

    public static function articleContentProjectUrl(SeoArticle $article): ?string
    {
        $projectId = static::articleAssignedContentProjectId($article);
        if ($projectId === null) {
            return null;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return null;
        }

        if (! SeoProjectResource::canView($project)) {
            return null;
        }

        return SeoProjectResource::projectRecordUrl($project);
    }

    /**
     * @param  SupportCollection<int, SeoArticle>|Collection<int, SeoArticle>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignArticlesToContentProject(
        SupportCollection $records,
        int $projectId,
        string $taskType = SeoProjectTask::TYPE_REWRITE,
        ?string $rewriteMode = null,
        ?string $rewriteNotes = null,
        ?string $keywordOverride = null,
        ?string $titleOverride = null,
        bool $ignoreMonthlyCapacity = false,
    ): array {
        return app(\Omnichannel\Addons\Agent\Automation\Migration\AssignmentCallerBridge::class)
            ->assignArticlesToContentProject(
                $records,
                $projectId,
                $taskType,
                $rewriteMode,
                $rewriteNotes,
                auth()->id() !== null ? (int) auth()->id() : null,
                $keywordOverride,
                $titleOverride,
                $ignoreMonthlyCapacity,
            );
    }

    /**
     * @param  array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project?:int}  $summary
     */
    public static function buildAssignContentProjectBody(array $summary): string
    {
        return app(SeoIssueProjectTaskAssignmentService::class)->buildSummaryMessage($summary);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessContentFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    /**
     * @return array<int, \Filament\Navigation\NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation()) {
            return [];
        }

        $parentLabel = static::getNavigationLabel();

        return [
            \Filament\Navigation\NavigationItem::make($parentLabel)
                ->icon(static::getNavigationIcon())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.resources.articles.index')
                    && in_array(request()->query('tab', Pages\ListArticles::TAB_POSTS), [
                        Pages\ListArticles::TAB_POSTS,
                        '',
                    ], true))
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index', ['tab' => Pages\ListArticles::TAB_POSTS])),
            \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.article_list.tab_posts'))
                ->icon('heroicon-o-document-text')
                ->group(null)
                ->parentItem($parentLabel)
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.resources.articles.index')
                    && request()->query('tab', Pages\ListArticles::TAB_POSTS) === Pages\ListArticles::TAB_POSTS)
                ->sort(1)
                ->url(static::getUrl('index', ['tab' => Pages\ListArticles::TAB_POSTS])),
            \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.article_list.tab_categories'))
                ->icon('heroicon-o-folder')
                ->group(null)
                ->parentItem($parentLabel)
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.resources.articles.index')
                    && request()->query('tab') === Pages\ListArticles::TAB_CATEGORIES)
                ->sort(2)
                ->url(static::getUrl('index', ['tab' => Pages\ListArticles::TAB_CATEGORIES])),
            \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.article_list.tab_reviewed'))
                ->icon('heroicon-o-check-badge')
                ->group(null)
                ->parentItem($parentLabel)
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.resources.articles.index')
                    && request()->query('tab') === Pages\ListArticles::TAB_REVIEWED)
                ->sort(4)
                ->url(static::getUrl('index', ['tab' => Pages\ListArticles::TAB_REVIEWED])),
            \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.article_list.tab_skipped'))
                ->icon('heroicon-o-no-symbol')
                ->group(null)
                ->parentItem($parentLabel)
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.seo.resources.articles.index')
                    && request()->query('tab') === Pages\ListArticles::TAB_SKIPPED)
                ->sort(5)
                ->url(static::getUrl('index', ['tab' => Pages\ListArticles::TAB_SKIPPED])),
        ];
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.article');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'queue' => Pages\ListArticleSyncQueue::route('/queue'),
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'domain-mismatch' => Pages\ArticleDomainMismatch::route('/{record}/domain-mismatch'),
            'access-denied' => Pages\ArticleAccessDenied::route('/{record}/access-denied'),
            'prompts' => Pages\ViewArticlePrompts::route('/{record}/prompts'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
