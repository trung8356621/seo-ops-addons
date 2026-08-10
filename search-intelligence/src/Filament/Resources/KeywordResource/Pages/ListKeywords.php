<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewSource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\Content\Filament\Resources\TagResource;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewService;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;

class ListKeywords extends ListRecords
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.list-keywords';

    #[Url(as: 'parent_id')]
    public ?int $parentId = null;

    #[Url(as: 'stat')]
    public ?string $dictionaryStatFilter = null;

    /** @var list<int> */
    public array $expandedParentIds = [];

    public ?int $selectedKeywordId = null;

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
    }

    public function getKeywordWorkspaceMode(): string
    {
        return 'dictionary';
    }

    public function onKeywordWorkspaceSiteFilterChanged(): void
    {
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function updatedTableFilters(): void
    {
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'index';
    }

    public function toggleParentExpand(int $parentId): void
    {
        if ($parentId <= 0) {
            return;
        }

        if (in_array($parentId, $this->expandedParentIds, true)) {
            $this->expandedParentIds = array_values(array_filter(
                $this->expandedParentIds,
                static fn (int $id): bool => $id !== $parentId,
            ));
        } else {
            $this->expandedParentIds[] = $parentId;
        }
    }

    public function selectKeyword(string $recordKey): void
    {
        $keywordId = (int) $recordKey;
        if ($keywordId <= 0) {
            return;
        }

        $this->selectedKeywordId = $keywordId;
        $this->dispatch('keyword-detail-open', keywordId: $keywordId);
        $this->skipRender();
    }

    public function selectKeywordForDetail(Keyword $record): void
    {
        $this->selectKeyword((string) $record->getKey());
    }

    public function closeSidebar(): void
    {
        $this->selectedKeywordId = null;
        $this->dispatch('keyword-detail-close');
        $this->skipRender();
    }

    /**
     * @return array{
     *     phrase: string,
     *     html: string,
     *     canEdit: bool,
     *     canDelete: bool,
     *     canMove: bool,
     *     error: string|null,
     * }
     */
    #[Renderless]
    public function loadKeywordDetailPanel(int $keywordId): array
    {
        if ($keywordId <= 0) {
            return [
                'phrase' => '',
                'html' => '',
                'canEdit' => false,
                'canDelete' => false,
                'canMove' => false,
                'error' => __('seo-content-ai::filament.keyword.destinations_modal_not_found'),
            ];
        }

        $keyword = Keyword::query()
            ->withCount([
                'mainArticles as main_articles_count',
                ...Keyword::linkMapCountRelations(),
                'children as children_count',
            ])
            ->with([
                'parent:id,phrase',
                'linkMaps' => static fn ($linkQuery): mixed => $linkQuery
                    ->orderBy('seo_link_maps.id')
                    ->with([
                        'sourceArticle' => static fn ($articleQuery): mixed => $articleQuery
                            ->withTrashed()
                            ->select('id', 'site_id', 'title', 'slug'),
                        'sourceArticle.site:id,domain',
                        'targetArticle:id,site_id,title,slug',
                        'targetArticle.site:id,domain',
                    ]),
                'mainArticles.site:id,domain',
            ])
            ->find($keywordId);

        if ($keyword === null) {
            return [
                'phrase' => '',
                'html' => '',
                'canEdit' => false,
                'canDelete' => false,
                'canMove' => false,
                'error' => __('seo-content-ai::filament.keyword.destinations_modal_not_found'),
            ];
        }

        $siteId = (int) (KeywordResource::resolveKeywordSiteId($keyword) ?? 0);
        $contentAnalysisUrl = $siteId > 0 && (int) ($keyword->linked_articles_count ?? 0) > 0
            ? app(DomainOverviewService::class)->buildArticlesFilterUrlForInternalAnchorKeyword($siteId, (int) $keyword->id)
            : null;

        return [
            'phrase' => (string) $keyword->phrase,
            'html' => view('seo-content-ai::filament.resources.keywords.pages.partials.keyword-dictionary-drawer-content', [
                'record' => $keyword,
            ])->render(),
            'contentAnalysisUrl' => $contentAnalysisUrl,
            'canEdit' => KeywordResource::canEdit($keyword),
            'canDelete' => KeywordResource::canDelete($keyword),
            'canMove' => SeoAccessControl::canMutateInSeoPanel()
                && SeoAccessControl::canAccessPlannerFeatures(),
            'error' => null,
        ];
    }

    public function assignToContentProjectAction(): Action
    {
        return Action::make('assignToContentProject')
            ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
            ->icon('heroicon-o-folder-plus')
            ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
            ->modalDescription(__('seo-content-ai::filament.keyword.workspace_assign_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
            ->form(function (array $arguments): array {
                $keyword = $this->resolveKeywordForMapId((int) ($arguments['mapId'] ?? 0));
                if (! $keyword instanceof Keyword) {
                    return [];
                }

                $siteId = $this->resolveMapSiteId((int) ($arguments['mapId'] ?? 0));

                if (KeywordResource::resolveKeywordDirectAssignData($siteId) !== null) {
                    return [];
                }

                return KeywordResource::assignKeywordContentProjectFormSchema(
                    $siteId !== null && $siteId > 0 ? [$siteId] : [],
                );
            })
            ->requiresConfirmation(function (array $arguments): bool {
                $siteId = $this->resolveMapSiteId((int) ($arguments['mapId'] ?? 0));

                return KeywordResource::resolveKeywordDirectAssignData($siteId) === null;
            })
            ->modalHidden(function (array $arguments): bool {
                $siteId = $this->resolveMapSiteId((int) ($arguments['mapId'] ?? 0));

                return KeywordResource::resolveKeywordDirectAssignData($siteId) !== null;
            })
            ->action(function (array $arguments, array $data): void {
                $mapId = (int) ($arguments['mapId'] ?? 0);
                $keyword = $this->resolveKeywordForMapId($mapId);
                if (! $keyword instanceof Keyword) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.workspace_map_not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                if (! KeywordResource::canAssignKeywordToContentProject($keyword)) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.workspace_assign_denied'))
                        ->warning()
                        ->send();

                    return;
                }

                $siteId = $this->resolveMapSiteId($mapId);
                $assignData = KeywordResource::resolveKeywordDirectAssignData($siteId) ?? $data;
                $summary = KeywordResource::executeAssignKeywordsToContentProjects(
                    Collection::make([$keyword]),
                    $assignData,
                );

                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                    ->body(ArticleResource::buildAssignContentProjectBody($summary))
                    ->success()
                    ->send();
            });
    }

    public function assignArticleToContentProjectAction(): Action
    {
        return Action::make('assignArticleToContentProject')
            ->label(__('seo-content-ai::filament.article_list.assign_to_content_project'))
            ->icon('heroicon-o-folder-plus')
            ->modalHeading(__('seo-content-ai::filament.article_list.assign_to_content_project'))
            ->modalDescription(__('seo-content-ai::filament.article_list.assign_to_content_project_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.article_list.assign'))
            ->form(function (array $arguments): array {
                $article = $this->resolveArticleForAssign((int) ($arguments['articleId'] ?? 0));
                if (! $article instanceof SeoArticle) {
                    return [];
                }

                $siteId = ArticleResource::resolveArticleSiteId($article);

                if (ArticleResource::resolveDirectAssignContentProjectId($siteId) !== null) {
                    return ArticleResource::assignArticleTaskFormFields();
                }

                return ArticleResource::assignContentProjectFormFields(
                    fn (): ?int => $siteId,
                );
            })
            ->action(function (array $arguments, array $data): void {
                $article = $this->resolveArticleForAssign((int) ($arguments['articleId'] ?? 0));
                if (! $article instanceof SeoArticle) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.workspace_article_not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                if (
                    ArticleResource::articleIsInContentProject($article)
                    || ArticleResource::articleIsContentArchived($article)
                ) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                        ->warning()
                        ->send();

                    return;
                }

                $siteId = ArticleResource::resolveArticleSiteId($article);
                $projectId = ArticleResource::resolveDirectAssignContentProjectId($siteId)
                    ?? (int) ($data['project_id'] ?? 0);
                $summary = ArticleResource::assignArticlesFromFormData(
                    Collection::make([$article]),
                    $projectId,
                    $data,
                );

                Notification::make()
                    ->title(__('seo-content-ai::filament.article_list.assign_completed'))
                    ->body(ArticleResource::buildAssignContentProjectBody($summary))
                    ->success()
                    ->send();
            });
    }

    private function resolveArticleForAssign(int $articleId): ?SeoArticle
    {
        if ($articleId <= 0) {
            return null;
        }

        $article = ArticleResource::getEloquentQuery()
            ->whereKey($articleId)
            ->first();

        return $article instanceof SeoArticle ? $article : null;
    }

    private function resolveKeywordForMapId(int $mapId): ?Keyword
    {
        if ($mapId <= 0) {
            return null;
        }

        $map = SeoLinkMap::query()->with('keyword')->find($mapId);
        $keyword = $map?->keyword;

        return $keyword instanceof Keyword ? $keyword : null;
    }

    private function resolveMapSiteId(int $mapId): ?int
    {
        if ($mapId <= 0) {
            return null;
        }

        $siteId = SeoLinkMap::query()
            ->whereKey($mapId)
            ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
            ->value('articles.site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    public function editSelectedKeyword(): void
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        $this->mountTableAction('edit', (string) $this->selectedKeywordId);
    }

    public function deleteSelectedKeyword(): void
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        $this->mountTableAction('delete', (string) $this->selectedKeywordId);
    }

    public function moveSelectedKeyword(): void
    {
        if ($this->selectedKeywordId === null || $this->selectedKeywordId <= 0) {
            return;
        }

        $this->mountTableAction('move_parent', (string) $this->selectedKeywordId);
    }

    public function table(Table $table): Table
    {
        $table = KeywordResource::table($table);

        if ($this->parentId === null) {
            $table
                ->filtersLayout(FiltersLayout::AboveContentCollapsible)
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDictionaryStatScope($query));
        }

        return $table
            ->recordAction('selectKeyword')
            ->actions($this->listPageTableActions());
    }

    public function filterTagsAction(): Action
    {
        return Action::make('filterTags')
            ->label(__('seo-content-ai::filament.keyword.tags_filter_heading'))
            ->modalHeading(__('seo-content-ai::filament.keyword.tags_filter_heading'))
            ->modalDescription(__('seo-content-ai::filament.keyword.tags_filter_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.tags_filter_apply'))
            ->modalWidth('lg')
            ->form([
                Forms\Components\Select::make('include_tag_ids')
                    ->label(__('seo-content-ai::filament.keyword.include_tags'))
                    ->options(fn (): array => KeywordResource::tagFilterOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false),
                Forms\Components\Select::make('exclude_tag_ids')
                    ->label(__('seo-content-ai::filament.keyword.exclude_tags'))
                    ->options(fn (): array => KeywordResource::tagFilterOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->fillForm(function (): array {
                $scope = is_array($this->tableFilters['tags_scope'] ?? null)
                    ? $this->tableFilters['tags_scope']
                    : [];

                return [
                    'include_tag_ids' => $scope['include_tag_ids'] ?? [],
                    'exclude_tag_ids' => $scope['exclude_tag_ids'] ?? [],
                ];
            })
            ->action(function (array $data): void {
                $includeIds = collect($data['include_tag_ids'] ?? [])
                    ->filter(static fn (mixed $id): bool => is_numeric($id))
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                $excludeIds = collect($data['exclude_tag_ids'] ?? [])
                    ->filter(static fn (mixed $id): bool => is_numeric($id))
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                $this->tableFilters['tags_scope'] = [
                    'include_tag_ids' => $includeIds,
                    'exclude_tag_ids' => $excludeIds,
                    'tags_filter_display' => null,
                ];

                $this->updatedTableFilters();
            });
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        if ($this->parentId !== null && $this->parentId > 0) {
            return __('seo-content-ai::filament.nav.keywords');
        }

        return new HtmlString('');
    }

    /**
     * @return array{total: int, active: int, needs_optimization: int, errors: int}
     */
    public function getDictionaryStats(): array
    {
        $query = $this->buildDictionaryListingQuery();

        if (! $query instanceof Builder) {
            return [
                'total' => 0,
                'active' => 0,
                'needs_optimization' => 0,
                'errors' => 0,
            ];
        }

        $reviewScopeQuery = $this->buildDictionaryReviewStatusQuery();
        if (! $reviewScopeQuery instanceof Builder) {
            $reviewScopeQuery = $query;
        }

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where(function (Builder $builder): void {
                $builder
                    ->whereHas('mainArticles')
                    ->orWhereHas(
                        'linkMaps',
                        static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                    );
            })->where('review_status', KeywordReviewStatus::Active->value)->count(),
            'needs_optimization' => (clone $reviewScopeQuery)
                ->where('review_status', KeywordReviewStatus::Warning->value)
                ->count(),
            'errors' => (clone $reviewScopeQuery)
                ->where('review_status', KeywordReviewStatus::Danger->value)
                ->count(),
        ];
    }

    public function applyDictionaryStatFilter(string $statKey): void
    {
        $allowed = ['total', 'active', 'needs_optimization', 'errors'];
        if (! in_array($statKey, $allowed, true)) {
            return;
        }

        $this->dictionaryStatFilter = $this->dictionaryStatFilter === $statKey ? null : $statKey;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function getSubheading(): ?string
    {
        if ($this->parentId === null || $this->parentId <= 0) {
            return null;
        }

        $parentPhrase = Keyword::query()
            ->whereKey($this->parentId)
            ->value('phrase');

        if (! is_string($parentPhrase) || $parentPhrase === '') {
            return __('seo-content-ai::filament.keyword.viewing_children');
        }

        return __('seo-content-ai::filament.keyword.viewing_children_of', [
            'phrase' => $parentPhrase,
        ]);
    }

    /**
     * @return list<Tables\Actions\Action|Tables\Actions\EditAction|Tables\Actions\DeleteAction>
     */
    protected function listPageTableActions(): array
    {
        return [
            KeywordResource::quickCopyTableAction(),
            Tables\Actions\EditAction::make()
                ->modalHeading(__('seo-content-ai::filament.keyword.edit'))
                ->form(fn (Keyword $record): array => KeywordResource::editKeywordFormSchema($record))
                ->mutateFormDataUsing(fn (array $data, Keyword $record): array => KeywordResource::mutateKeywordFormDataForFill($data, $record))
                ->using(fn (Keyword $record, array $data): Keyword => KeywordResource::saveKeywordFromFormData($record, $data))
                ->extraAttributes(['class' => 'keyword-ta-sr-action'])
                ->authorize(fn (Keyword $record): bool => KeywordResource::canEdit($record)),
            Tables\Actions\DeleteAction::make()
                ->extraAttributes(['class' => 'keyword-ta-sr-action'])
                ->authorize(fn (Keyword $record): bool => KeywordResource::canDelete($record))
                ->after(function (): void {
                    $this->selectedKeywordId = null;
                    $this->dispatch('keyword-detail-close');
                }),
            Tables\Actions\Action::make('restore_keyword')
                ->label(__('seo-content-ai::filament.keyword.restore_keyword'))
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (Keyword $record): bool => in_array((string) ($record->review_status ?? ''), [
                    KeywordReviewStatus::Warning->value,
                    KeywordReviewStatus::Danger->value,
                ], true) && SeoAccessControl::canRestoreKeywords())
                ->requiresConfirmation()
                ->modalHeading(__('seo-content-ai::filament.keyword.restore_keyword_heading'))
                ->modalDescription(__('seo-content-ai::filament.keyword.restore_keyword_description'))
                ->action(function (Keyword $record): void {
                    app(KeywordReviewService::class)->restoreKeyword(
                        $record,
                        (int) (auth()->id() ?? 0),
                        KeywordReviewSource::KeywordsTable,
                    );

                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.restore_keyword_success'))
                        ->success()
                        ->send();

                    $this->flushCachedTableRecords();
                }),
            Tables\Actions\Action::make('move_parent')
                ->label(__('seo-content-ai::filament.keyword.drawer_move'))
                ->modalHeading(__('seo-content-ai::filament.keyword.bulk_quick_parent'))
                ->extraAttributes(['class' => 'keyword-ta-sr-action'])
                ->form(fn (Keyword $record): array => [
                    Forms\Components\Select::make('parent_id')
                        ->label(__('seo-content-ai::filament.keyword.parent_keyword'))
                        ->options(fn (): array => KeywordResource::bulkParentOptions(Collection::make([$record])))
                        ->getSearchResultsUsing(
                            fn (string $search, Keyword $record): array => KeywordResource::bulkParentOptions(
                                Collection::make([$record]),
                                $search,
                            ),
                        )
                        ->getOptionLabelUsing(
                            fn (mixed $value): ?string => KeywordResource::parentKeywordOptionLabel($value),
                        )
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText(__('seo-content-ai::filament.keyword.bulk_quick_parent_hint')),
                ])
                ->action(function (Keyword $record, array $data): void {
                    $parentId = (int) ($data['parent_id'] ?? 0);
                    if ($parentId <= 0) {
                        return;
                    }

                    $parent = Keyword::query()->find($parentId);
                    if (! $parent instanceof Keyword || $parent->parent_id !== null) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.bulk_quick_parent_failed'))
                            ->body(__('seo-content-ai::filament.keyword.bulk_quick_parent_invalid_parent'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if (
                        (int) $record->id === $parentId
                        || KeywordResource::resolveKeywordSiteId($record) !== KeywordResource::resolveKeywordSiteId($parent)
                    ) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.bulk_quick_parent_failed'))
                            ->body(__('seo-content-ai::filament.keyword.bulk_quick_parent_body', [
                                'updated' => 0,
                                'skipped' => 1,
                            ]))
                            ->warning()
                            ->send();

                        return;
                    }

                    if ((int) $record->parent_id !== $parentId) {
                        $record->update(['parent_id' => $parentId]);
                    }

                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.bulk_quick_parent_completed'))
                        ->body(__('seo-content-ai::filament.keyword.bulk_quick_parent_body', [
                            'updated' => 1,
                            'skipped' => 0,
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Global keyword dictionary: GlobalSeoBar domain must not filter this listing.
     */
    protected function getTableQuery(): ?Builder
    {
        if ($this->parentId !== null && $this->parentId > 0) {
            return $this->buildDictionaryListingQuery();
        }

        if (! $this->dictionaryListingRequiresLinkedScope()) {
            return $this->buildDictionaryReviewStatusQuery();
        }

        return $this->buildDictionaryListingQuery();
    }

    protected function dictionaryListingRequiresLinkedScope(): bool
    {
        return ! in_array((string) ($this->dictionaryStatFilter ?? ''), [
            'needs_optimization',
            'errors',
        ], true);
    }

    protected function buildDictionaryReviewStatusQuery(): ?Builder
    {
        $query = KeywordResource::getReviewedDictionaryQuery();

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId > 0) {
            $query = KeywordResource::applyReviewedDictionarySiteScope($query, $siteId);
        }

        return $query->orderByDesc('reviewed_at')->orderBy('phrase');
    }

    protected function buildDictionaryListingQuery(?bool $requireLinkedScope = null): ?Builder
    {
        $query = parent::getTableQuery();

        if (! $query instanceof Builder) {
            return $query;
        }

        if ($requireLinkedScope === null) {
            $requireLinkedScope = $this->dictionaryListingRequiresLinkedScope();
        }

        if ($this->parentId !== null && $this->parentId > 0) {
            return KeywordResource::applyParentScopeToQuery($query, $this->parentId);
        }

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);

        if ($siteId > 0) {
            $query->forSite($siteId);
        }

        if ($requireLinkedScope && $this->parentId === null) {
            if ($this->getKeywordWorkspaceMode() === 'focus') {
                $query->whereHas('mainArticles');
            } else {
                $query->whereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                );
            }
        }

        $expanded = array_values(array_filter(
            $this->expandedParentIds,
            static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        ));

        if ($expanded === []) {
            return $query->whereNull('parent_id');
        }

        return $query
            ->where(function (Builder $builder) use ($expanded): void {
                $builder
                    ->whereNull('parent_id')
                    ->orWhereIn('parent_id', $expanded);
            })
            ->orderByRaw('COALESCE(parent_id, id) ASC')
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('phrase');
    }

    protected function applyDictionaryStatScope(Builder $query): Builder
    {
        $statKey = $this->dictionaryStatFilter;

        if ($statKey === null || $statKey === '' || $statKey === 'total') {
            return $query;
        }

        return match ($statKey) {
            'active' => $query->where(function (Builder $builder): void {
                $builder
                    ->whereHas('mainArticles')
                    ->orWhereHas(
                        'linkMaps',
                        static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                    );
            }),
            'needs_optimization' => $query->where('review_status', KeywordReviewStatus::Warning->value),
            'errors' => $query->where('review_status', KeywordReviewStatus::Danger->value),
            default => $query,
        };
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->parentId !== null && $this->parentId > 0) {
            $actions[] = Actions\Action::make('back_to_roots')
                ->label(__('seo-content-ai::filament.keyword.back_to_parents'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(KeywordResource::buildRootKeywordsUrl());
        }

        $actions[] = Actions\Action::make('manage_tags')
            ->label(__('seo-content-ai::filament.keyword.manage_tags'))
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->url(TagResource::getUrl('index'));

        $actions[] = Actions\Action::make('add_keywords')
            ->label(__('seo-content-ai::filament.keyword.add_keyword'))
            ->icon('heroicon-o-plus')
            ->form([
                Forms\Components\Select::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => KeywordResource::siteSelectOptions())
                    ->default(fn (): ?int => $this->resolveKeywordWorkspaceSiteId())
                    ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),
                Forms\Components\Textarea::make('phrases')
                    ->label('Keywords')
                    ->helperText('Mỗi dòng là một keyword free (type=free), không tự gắn vào bài viết.')
                    ->rows(12)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $siteId = (int) ($data['site_id'] ?? $this->resolveKeywordWorkspaceSiteId() ?? 0);
                $phrases = collect(preg_split('/\R/u', (string) ($data['phrases'] ?? '')) ?: [])
                    ->map(static fn (string $phrase): string => trim($phrase))
                    ->filter()
                    ->unique(static fn (string $phrase): string => mb_strtolower($phrase))
                    ->values();

                $created = 0;
                $invalid = 0;
                $blocked = 0;
                foreach ($phrases as $phrase) {
                    $decodedPhrase = Keyword::decodePhrase($phrase);
                    if ($decodedPhrase === '') {
                        $invalid++;

                        continue;
                    }

                    if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase($decodedPhrase)) {
                        $invalid++;

                        continue;
                    }

                    if (app(CtaKeywordBlacklistFilter::class)->isBlocked($decodedPhrase)) {
                        $blocked++;

                        continue;
                    }

                    $alreadyExists = Keyword::query()
                        ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$decodedPhrase])
                        ->exists();

                    app(KeywordPersistenceService::class)->upsert(
                        $decodedPhrase,
                        Keyword::TYPE_FREE,
                        $siteId,
                    );

                    if (! $alreadyExists) {
                        $created++;
                    }
                }

                Notification::make()
                    ->title("Đã thêm {$created} keyword free")
                    ->body(collect([
                        $invalid > 0 ? "Bỏ qua {$invalid} dòng không hợp lệ." : null,
                        $blocked > 0 ? "Bỏ qua {$blocked} dòng thuộc CTA blacklist." : null,
                    ])->filter()->implode(' '))
                    ->success()
                    ->send();
            });

        return $actions;
    }
}
