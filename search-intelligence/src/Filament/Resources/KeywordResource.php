<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewHistory;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordDebugRescrapeService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\Seo\Services\SeoNotificationService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoRankKeywordGroupService;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;

class KeywordResource extends SeoPanelResource
{
    public const LINK_ROLE_MAIN = 'main';

    public const LINK_ROLE_INTERNAL_ANCHOR = 'internal_anchor';

    protected static ?string $model = Keyword::class;

    protected static ?string $slug = 'keywords';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Keywords';

    protected static ?string $modelLabel = 'Keyword';

    protected static ?string $pluralModelLabel = 'Keywords';

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_KEYWORDS;

    /**
     * Sidebar: Từ khóa → dictionary / focus / clusters / cannibalization / link triage.
     */
    protected static bool $shouldRegisterNavigation = true;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return \Omnichannel\Addons\Seo\Support\SeoUserNavigation::moduleKeywords();
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl('index');
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
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index'))
                ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsModule())
                ->childItems([
                    \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.keyword.workspace_nav_dictionary'))
                        ->url(static::getUrl('index'))
                        ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsDictionaryNav()),
                    \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.keyword.workspace_nav_focus'))
                        ->url(static::getUrl('focus'))
                        ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsFocusNav()),
                    \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.keyword.workspace_nav_two'))
                        ->url(static::getUrl('clusters'))
                        ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsClustersNav()),
                    \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.keyword.cannibalization_nav'))
                        ->url(static::getUrl('cannibalization'))
                        ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsCannibalizationNav()),
                    \Filament\Navigation\NavigationItem::make(__('seo-content-ai::filament.keyword.workspace_nav_anchor_audit'))
                        ->url(static::getUrl('anchor-audit'))
                        ->isActiveWhen(fn (): bool => SeoPanelRoutes::isKeywordsBrokenLinksNav()),
                ]),
        ];
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
            && ! static::isKeywordLockedByActiveJobs($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
            && ! static::isKeywordLockedByActiveJobs($record)
            && static::isUnused($record);
    }

    public static function canMutateKeywordVisibility(Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures()
            && $record instanceof Keyword
            && ! static::isKeywordLockedByActiveJobs($record);
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.keyword');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.keywords');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                    ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                    ->searchable()
                    ->preload()
                    ->required(fn (): bool => ! SeoAccessControl::hasGlobalSiteScope())
                    ->native(false),

                Forms\Components\TextInput::make('phrase')
                    ->label(__('seo-content-ai::filament.keyword.phrase'))
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: Keyword::class,
                        column: 'phrase',
                        ignoreRecord: true,
                    )
                    ->rule(fn (Get $get): array => $get('type') === Keyword::TYPE_NORMAL
                        ? [function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase((string) $value)) {
                                $fail(__('seo-content-ai::filament.keyword.anchor_text_invalid'));
                            }
                        }]
                        : [])
                    ->columnSpanFull(),

                Forms\Components\Hidden::make('type')
                    ->default(Keyword::TYPE_NORMAL),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ViewColumn::make('keyword_item')
                    ->label(__('seo-content-ai::filament.keyword.phrase_short'))
                    ->view('seo-content-ai::filament.tables.columns.keyword-item')
                    ->disabledClick()
                    ->extraHeaderAttributes(['class' => 'keyword-item-table-header'])
                    ->extraCellAttributes(['class' => 'keyword-item-table-cell p-0 align-top'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return static::applyInsensitivePhraseSearch($query, $search);
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('phrase', $direction)),

                Tables\Columns\ViewColumn::make('destinations')
                    ->label(__('seo-content-ai::filament.keyword.target_destinations'))
                    ->view('seo-content-ai::filament.resources.keywords.columns.destinations')
                    ->disabledClick()
                    ->extraCellAttributes(['class' => 'py-2 whitespace-normal'])
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('phrase')
            ->filters([
                Tables\Filters\TernaryFilter::make('seo_hidden')
                    ->label(__('seo-content-ai::filament.keyword.hide_filter_label'))
                    ->placeholder(__('seo-content-ai::filament.keyword.hide_filter_active'))
                    ->trueLabel(__('seo-content-ai::filament.keyword.hide_filter_hidden'))
                    ->falseLabel(__('seo-content-ai::filament.keyword.hide_filter_visible'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'metas',
                            static fn (Builder $meta): Builder => $meta
                                ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                                ->where('meta_value', '1'),
                        ),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave(
                            'metas',
                            static fn (Builder $meta): Builder => $meta
                                ->where('meta_key', KeywordMetaKey::SeoHidden->value)
                                ->where('meta_value', '1'),
                        ),
                        // Blank = full Dictionary (includes Exclude from SEO); do not hide excluded.
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Tables\Filters\SelectFilter::make('site_id')
                    ->label(__('seo-content-ai::filament.keyword.domain'))
                    ->options(fn (): array => static::siteSelectOptions())
                    ->hidden()
                    ->placeholder(__('seo-content-ai::filament.keyword.domain_filter_all'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        $siteId = (int) ($data['value'] ?? 0);

                        return $siteId > 0 ? $query->forSite($siteId) : $query;
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $siteId = (int) ($data['value'] ?? 0);
                        if ($siteId <= 0) {
                            return null;
                        }

                        $domain = static::siteSelectOptions()[$siteId] ?? null;

                        return is_string($domain) && $domain !== ''
                            ? __('seo-content-ai::filament.keyword.domain').': '.$domain
                            : null;
                    }),
                Tables\Filters\Filter::make('operational_tags')
                    ->label(__('seo-content-ai::filament.keyword.operational_tags'))
                    ->form([
                        Forms\Components\Select::make('tags')
                            ->label(__('seo-content-ai::filament.keyword.operational_tags'))
                            ->options(KeywordTag::filterOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return app(KeywordTagQuery::class)->apply($query, $data['tags'] ?? []);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = collect($data['tags'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->map(static fn (string $tag): string => KeywordTag::label($tag))
                            ->values()
                            ->all();

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.operational_tags').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('seo_classification')
                    ->label(__('seo-content-ai::filament.keyword.advanced_classification'))
                    ->form([
                        Forms\Components\Select::make('kinds')
                            ->label(__('seo-content-ai::filament.keyword.advanced_classification'))
                            ->options(KeywordClassificationVisibility::filterOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return KeywordClassificationVisibility::applyKindFilter($query, $data['kinds'] ?? []);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = collect($data['kinds'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->map(static fn (string $kind): string => KeywordClassificationVisibility::label($kind))
                            ->values()
                            ->all();

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.advanced_classification').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('seo_intent')
                    ->label(__('seo-content-ai::filament.keyword.advanced_intent'))
                    ->form([
                        Forms\Components\Select::make('intents')
                            ->label(__('seo-content-ai::filament.keyword.advanced_intent'))
                            ->options(KeywordRuleClassifier::intentFilterOptions())
                            ->multiple()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $allowed = KeywordRuleClassifier::intents();
                        $intents = collect($data['intents'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
                            ->values()
                            ->all();
                        if ($intents === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'seoClassification',
                            static fn (Builder $classification): Builder => $classification->whereIn('seo_intent', $intents),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = collect($data['intents'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->map(static fn (string $intent): string => KeywordRuleClassifier::intentLabel($intent))
                            ->filter(static fn (string $label): bool => $label !== '')
                            ->values()
                            ->all();

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.advanced_intent').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('source_kind')
                    ->label(__('seo-content-ai::filament.keyword.advanced_source'))
                    ->form([
                        Forms\Components\Select::make('sources')
                            ->label(__('seo-content-ai::filament.keyword.advanced_source'))
                            ->options(KeywordSourceNormalizer::filterOptions())
                            ->multiple()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $allowed = KeywordSourceNormalizer::all();
                        $sources = collect($data['sources'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
                            ->values()
                            ->all();
                        if ($sources === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'seoClassification',
                            static fn (Builder $classification): Builder => $classification->whereIn('source_kind', $sources),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = collect($data['sources'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->map(static fn (string $source): string => KeywordSourceNormalizer::label($source))
                            ->filter(static fn (string $label): bool => $label !== '')
                            ->values()
                            ->all();

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.advanced_source').': '.implode(', ', $labels);
                    }),
                Tables\Filters\Filter::make('keyword_type')
                    ->label(__('seo-content-ai::filament.keyword.legacy_type'))
                    ->form([
                        Forms\Components\Select::make('types')
                            ->label(__('seo-content-ai::filament.keyword.legacy_type'))
                            ->options(static::keywordTypeFilterOptions())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        // Dictionary filter options only (Normal/Free). TYPE_SUGGEST stays staging — never filter UI.
                        $allowed = array_keys(static::keywordTypeFilterOptions());
                        $types = collect($data['types'] ?? [])
                            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
                            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
                            ->values()
                            ->all();

                        if ($types === []) {
                            return $query;
                        }

                        return $query->whereIn('type', $types);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $labels = static::resolveKeywordTypeFilterLabels($data['types'] ?? []);

                        return $labels === []
                            ? null
                            : __('seo-content-ai::filament.keyword.legacy_type').': '.implode(', ', $labels);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->persistFiltersInSession()
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip(fn (Keyword $record): string => static::isKeywordLockedByActiveJobs($record)
                        ? __('seo-content-ai::filament.keyword.keyword_locked_by_active_jobs')
                        : __('seo-content-ai::filament.keyword.edit'))
                    ->modalHeading(__('seo-content-ai::filament.keyword.edit'))
                    ->form(fn (Keyword $record): array => static::editKeywordFormSchema($record))
                    ->visible(fn (Keyword $record): bool => static::canEdit($record))
                    ->mutateFormDataUsing(fn (array $data, Keyword $record): array => static::mutateKeywordFormDataForFill($data, $record))
                    ->using(fn (Keyword $record, array $data): Keyword => static::saveKeywordFromFormData($record, $data)),
                AssignToContentProjectActionFactory::tableRowAction(
                    resolvePayload: function (Model $record): array {
                        /** @var Keyword $record */
                        $siteId = static::resolveKeywordSiteId($record);

                        return AssignToContentProjectContract::keywordPayload(
                            source: 'keyword_table',
                            keywordIds: [(int) $record->id],
                            siteIds: $siteId !== null && $siteId > 0 ? [(int) $siteId] : [],
                        );
                    },
                    visible: fn (Keyword $record): bool => static::canAssignKeywordToContentProject($record),
                ),
                Tables\Actions\Action::make('add_to_rank_group')
                    ->label(__('seo-content-ai::filament.rank_group.add_to_group'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->iconButton()
                    ->tooltip(__('seo-content-ai::filament.rank_group.add_to_group'))
                    ->visible(fn (): bool => SeoAccessControl::canAccessPlannerFeatures() && SeoAccessControl::canMutateInSeoPanel())
                    ->form(fn (): array => static::addToRankGroupFormSchema())
                    ->modalHeading(__('seo-content-ai::filament.rank_group.add_to_group_heading'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.rank_group.add_to_group'))
                    ->action(function (Keyword $record, array $data): void {
                        $groupIds = collect($data['group_ids'] ?? [])
                            ->filter(static fn (mixed $id): bool => is_numeric($id))
                            ->map(static fn (mixed $id): int => (int) $id)
                            ->values()
                            ->all();

                        $summary = app(SeoRankKeywordGroupService::class)->addKeywordsToGroups(
                            [(int) $record->id],
                            $groupIds,
                            (int) auth()->id(),
                        );

                        Notification::make()
                            ->title(__('seo-content-ai::filament.rank_group.add_completed', [
                                'added' => $summary['added'],
                                'skipped' => $summary['skipped'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('debug_rescrape')
                    ->label(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->icon('heroicon-o-bug-ant')
                    ->iconButton()
                    ->tooltip(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.keyword.debug_rescrape'))
                    ->modalDescription(fn (Keyword $record): string => __('seo-content-ai::filament.keyword.debug_rescrape_confirm', [
                        'phrase' => (string) $record->phrase,
                    ]))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.debug_rescrape_submit'))
                    ->action(function (Keyword $record): void {
                        $summary = app(KeywordDebugRescrapeService::class)->deleteAndRescrapeLinkedArticles($record);

                        $bodyKey = ($summary['content_still_contains_phrase'] ?? 0) > 0
                            ? 'debug_rescrape_body_stale_content'
                            : 'debug_rescrape_body';

                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.debug_rescrape_completed'))
                            ->body(__('seo-content-ai::filament.keyword.'.$bodyKey, [
                                'phrase' => $summary['phrase'],
                                'articles' => count($summary['linked_article_ids']),
                                'rescanned' => $summary['rescanned'],
                                'skipped' => $summary['skipped'],
                                'stale_articles' => $summary['content_still_contains_phrase'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('hide_keyword')
                    ->label(__('seo-content-ai::filament.keyword.hide_action'))
                    ->icon('heroicon-o-eye-slash')
                    ->iconButton()
                    ->color('warning')
                    ->tooltip(__('seo-content-ai::filament.keyword.hide_action'))
                    ->requiresConfirmation()
                    ->modalHeading(__('seo-content-ai::filament.keyword.hide_confirm_heading'))
                    ->modalDescription(__('seo-content-ai::filament.keyword.hide_confirm_body'))
                    ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.hide_action'))
                    ->visible(fn (Keyword $record): bool => static::canMutateKeywordVisibility($record)
                        && ! app(HideKeywordFromSeoService::class)->isHidden((int) $record->id))
                    ->action(function (Keyword $record): void {
                        $siteId = static::resolveKeywordSiteId($record);
                        $result = app(HideKeywordFromSeoService::class)->hide((int) $record->id, $siteId);
                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.hide_success_title'))
                            ->body(__('seo-content-ai::filament.keyword.hide_success_body', [
                                'phrase' => $result['phrase'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('restore_hidden_keyword')
                    ->label(__('seo-content-ai::filament.keyword.hide_restore_action'))
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->color('success')
                    ->tooltip(__('seo-content-ai::filament.keyword.hide_restore_action'))
                    ->visible(fn (Keyword $record): bool => static::canMutateKeywordVisibility($record)
                        && app(HideKeywordFromSeoService::class)->isHidden((int) $record->id))
                    ->action(function (Keyword $record): void {
                        $siteId = static::resolveKeywordSiteId($record);
                        $result = app(HideKeywordFromSeoService::class)->restore((int) $record->id, $siteId);
                        Notification::make()
                            ->title(__('seo-content-ai::filament.keyword.hide_restore_success_title'))
                            ->body(__('seo-content-ai::filament.keyword.hide_restore_success_body', [
                                'phrase' => $result['phrase'],
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip(fn (Keyword $record): string => static::isKeywordLockedByActiveJobs($record)
                        ? __('seo-content-ai::filament.keyword.keyword_locked_by_active_jobs')
                        : __('seo-content-ai::filament.keyword.delete'))
                    ->visible(fn (Keyword $record): bool => static::canDelete($record)),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    AssignToContentProjectActionFactory::tableBulkAction(
                        resolvePayload: function (Collection $records): array {
                            $siteIds = static::resolveBulkKeywordsSiteIds($records);

                            return AssignToContentProjectContract::keywordPayload(
                                source: 'keyword_table_bulk',
                                keywordIds: $records
                                    ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
                                    ->map(static fn (Keyword $keyword): int => (int) $keyword->id)
                                    ->values()
                                    ->all(),
                                siteIds: $siteIds !== []
                                    ? $siteIds
                                    : (SeoAccessControl::globalSiteId() !== null ? [(int) SeoAccessControl::globalSiteId()] : []),
                            );
                        },
                    ),
                    Tables\Actions\BulkAction::make('add_to_rank_group')
                        ->label(__('seo-content-ai::filament.rank_group.add_to_group_bulk'))
                        ->icon('heroicon-o-rectangle-stack')
                        ->visible(fn (): bool => SeoAccessControl::canAccessPlannerFeatures() && SeoAccessControl::canMutateInSeoPanel())
                        ->form(fn (): array => static::addToRankGroupFormSchema(multiple: false))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $groupId = (int) ($data['group_id'] ?? 0);
                            $groupIds = $groupId > 0 ? [$groupId] : [];

                            $keywordIds = $records
                                ->map(static fn (Keyword $keyword): int => (int) $keyword->id)
                                ->values()
                                ->all();

                            $summary = app(SeoRankKeywordGroupService::class)->addKeywordsToGroups(
                                $keywordIds,
                                $groupIds,
                                (int) auth()->id(),
                            );

                            Notification::make()
                                ->title(__('seo-content-ai::filament.rank_group.add_completed', [
                                    'added' => $summary['added'],
                                    'skipped' => $summary['skipped'],
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make()
                        ->label(__('seo-content-ai::filament.keyword.bulk_delete'))
                        ->action(function (Collection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof Keyword) {
                                    continue;
                                }

                                if (! static::canDelete($record)) {
                                    $skipped++;

                                    continue;
                                }

                                $record->delete();
                                $deleted++;
                            }

                            Notification::make()
                                ->title(__('seo-content-ai::filament.keyword.bulk_delete_completed'))
                                ->body(__('seo-content-ai::filament.keyword.bulk_delete_body', [
                                    'deleted' => $deleted,
                                    'skipped' => $skipped,
                                ]))
                                ->success()
                                ->send();
                        }),
                ]),
            ]));
    }

    /**
     * @return array<int|string, string>
     */
    public static function siteSelectOptions(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('domain', 'id')->all();
    }

    public static function keywordHasSiteLinks(Keyword $record): bool
    {
        if ((int) ($record->site_links_count ?? 0) > 0) {
            return true;
        }

        if ($record->relationLoaded('linkMaps')) {
            return $record->linkMaps->isNotEmpty();
        }

        return $record->linkMaps()->exists();
    }

    public static function resolvePrimarySiteLink(Keyword $record, ?int $preferredSiteId = null): ?SeoLinkMap
    {
        $preferredSiteId ??= SeoAccessControl::globalSiteId();

        $maps = $record->relationLoaded('linkMaps')
            ? $record->linkMaps
            : $record->linkMaps()->with('sourceArticle:id,site_id')->orderBy('id')->get();

        if ($preferredSiteId !== null && (int) $preferredSiteId > 0) {
            $scoped = $maps->first(
                static fn (SeoLinkMap $map): bool => (int) ($map->sourceArticle?->site_id ?? 0) === (int) $preferredSiteId,
            );

            if ($scoped instanceof SeoLinkMap) {
                return $scoped;
            }
        }

        return $maps->first();
    }

    public static function resolveKeywordSiteLinkUrl(Keyword $record, ?int $preferredSiteId = null): ?string
    {
        $map = static::resolvePrimarySiteLink($record, $preferredSiteId);
        if (! $map instanceof SeoLinkMap) {
            return null;
        }

        $siteId = (int) ($map->sourceArticle?->site_id ?? $preferredSiteId ?? 0);

        return static::resolveLinkMapDestinationUrl($map, $siteId);
    }

    public static function resolveLinkMapDestinationUrl(SeoLinkMap $map, int $siteId, ?string $domain = null): string
    {
        if ((int) ($map->target_article_id ?? 0) > 0) {
            $target = $map->relationLoaded('targetArticle')
                ? $map->targetArticle
                : $map->targetArticle()->first(['id', 'site_id', 'title', 'slug']);

            if ($target instanceof SeoArticle) {
                $url = app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($target);
                if (is_string($url) && trim($url) !== '') {
                    return trim($url);
                }
            }
        }

        $external = trim((string) ($map->target_external_url ?? ''));
        if ($external === '') {
            return '';
        }

        if ($domain === null) {
            $domain = trim((string) (static::siteSelectOptions()[$siteId] ?? ''));
        }

        return static::buildAbsoluteLinkUrl($external, $siteId, $domain !== '' ? $domain : null);
    }

    /**
     * @return array{domain: string, url: string}|null
     */
    public static function resolveFocusMainArticlePresentation(Keyword $record): ?array
    {
        if (! Keyword::isNormalType($record->type)) {
            return null;
        }

        if ((int) ($record->main_articles_count ?? 0) < 1 && ! $record->relationLoaded('mainArticles')) {
            if (! $record->mainArticles()->exists()) {
                return null;
            }
        }

        $articles = $record->relationLoaded('mainArticles')
            ? $record->mainArticles
            : $record->mainArticles()->with('site')->orderBy('articles.id')->get();

        if ($articles->isEmpty()) {
            return null;
        }

        $preferredSiteId = SeoAccessControl::globalSiteId();
        $article = null;

        if ($preferredSiteId !== null && (int) $preferredSiteId > 0) {
            $article = $articles->first(
                static fn (SeoArticle $item): bool => (int) ($item->site_id ?? 0) === (int) $preferredSiteId,
            );
        }

        $article ??= $articles->first();
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $url = trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($article) ?? ''));
        if ($url === '') {
            return null;
        }

        $article->loadMissing('site');
        $site = $article->site;
        $domain = $site instanceof Site ? trim((string) $site->domain) : '';
        if ($domain === '') {
            $host = parse_url($url, PHP_URL_HOST);

            $domain = is_string($host) && $host !== '' ? $host : $url;
        }

        return [
            'domain' => $domain,
            'url' => $url,
        ];
    }

    public static function buildRootKeywordsUrl(): string
    {
        return static::getUrl('index');
    }

    public static function buildOperationalTagFilterUrl(string $tag): string
    {
        $base = static::getUrl('index');
        $query = http_build_query([
            'tableFilters' => [
                'operational_tags' => [
                    'tags' => [$tag],
                ],
            ],
        ]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    public static function buildIncludeTagFilterUrl(int $tagId): string
    {
        if ($tagId <= 0) {
            return static::getUrl('index');
        }

        $base = static::getUrl('index');
        $query = http_build_query([
            'tableFilters' => [
                'include_tags' => [
                    'tag_ids' => [(string) $tagId],
                ],
            ],
        ]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * @return array<int|string, string>
     */
    public static function tagFilterOptions(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Active Dictionary filter only — staging Suggest is SEO Audit, not inventory.
     *
     * @return array<int|string, string>
     */
    public static function keywordTypeFilterOptions(): array
    {
        return [
            Keyword::TYPE_NORMAL => __('seo-content-ai::filament.keyword.normal_short'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free_short'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function qualityFlagFilterOptions(): array
    {
        return [
            'danger' => __('seo-content-ai::filament.keyword.quality_flag_danger'),
            'warning' => __('seo-content-ai::filament.keyword.quality_flag_warning'),
            'clean' => __('seo-content-ai::filament.keyword.quality_flag_clean'),
        ];
    }

    /**
     * @param  list<mixed>  $flags
     * @return list<string>
     */
    public static function resolveQualityFlagFilterLabels(array $flags): array
    {
        $options = static::qualityFlagFilterOptions();

        return collect($flags)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(static fn (string $value): string => $options[$value] ?? $value)
            ->filter(static fn (string $label): bool => $label !== '')
            ->values()
            ->all();
    }

    /**
     * Create/edit inventory types only (no staging Suggest).
     *
     * @return array<int|string, string>
     */
    public static function keywordTypeSelectOptions(): array
    {
        return [
            Keyword::TYPE_NORMAL => __('seo-content-ai::filament.keyword.normal'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free'),
        ];
    }

    public static function keywordTypeBadgeColor(string $state): string
    {
        return match ($state) {
            Keyword::TYPE_NORMAL, 'focus', 'internal' => 'success',
            Keyword::TYPE_SUGGEST => 'info',
            Keyword::TYPE_FREE => 'gray',
            default => 'gray',
        };
    }

    public static function keywordTypeShortLabel(string $state): string
    {
        return match ($state) {
            Keyword::TYPE_NORMAL, 'focus', 'internal' => __('seo-content-ai::filament.keyword.normal_short'),
            Keyword::TYPE_SUGGEST => __('seo-content-ai::filament.keyword.suggest_short'),
            Keyword::TYPE_FREE => __('seo-content-ai::filament.keyword.free_short'),
            default => $state,
        };
    }

    public static function resolveDictionaryStatusKey(Keyword $record): string
    {
        $reviewStatus = KeywordReviewStatus::tryFrom((string) ($record->review_status ?? ''));
        if ($reviewStatus?->isManualError() === true) {
            return 'error';
        }

        $hasBroken = $record->relationLoaded('linkMaps')
            ? $record->linkMaps->contains(static fn (SeoLinkMap $map): bool => $map->status === SeoLinkMapStatus::Broken)
            : $record->linkMaps()->where('status', SeoLinkMapStatus::Broken)->exists();

        if ($hasBroken) {
            return 'error';
        }

        if ((int) ($record->main_articles_count ?? 0) > 0 || (int) ($record->linked_articles_count ?? 0) > 0) {
            return 'active';
        }

        return 'active';
    }

    public static function resolveDictionaryStatusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => __('seo-content-ai::filament.keyword.stat_active'),
            'needs_optimization' => __('seo-content-ai::filament.keyword.stat_needs_optimization'),
            'error' => __('seo-content-ai::filament.keyword.stat_errors'),
            default => $statusKey,
        };
    }

    public static function resolveDictionaryStatusBadgeColor(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => 'success',
            'needs_optimization' => 'warning',
            'error' => 'danger',
            default => 'gray',
        };
    }

    public static function resolveDictionaryStatusBadgeClass(string $statusKey): string
    {
        return match ($statusKey) {
            'active' => 'ws-badge--success',
            'needs_optimization' => 'ws-badge--warning',
            'error' => 'ws-badge--danger',
            default => 'ws-badge--gray',
        };
    }

    public static function isKeywordLockedByActiveJobs(Keyword $keyword): bool
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        if ($needle === '') {
            return false;
        }

        return SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->whereHas(
                'project',
                static fn (Builder $query): Builder => $query->whereIn('status', [
                    SeoProject::STATUS_PENDING,
                    SeoProject::STATUS_MANUAL,
                    SeoProject::STATUS_RUNNING,
                ]),
            )
            ->exists();
    }

    /**
     * @return list<array{domain: string, url: string, site_id: int, role: string}>
     */
    public static function resolveLinkDestinationPresentations(Keyword $record): array
    {
        return collect(static::resolveLinkDestinationGroups($record))
            ->flatMap(static function (array $group): Collection {
                $items = collect($group['main_links'] ?? [])
                    ->merge($group['internal_links'] ?? [])
                    ->map(static fn (array $link): array => [
                        'domain' => (string) $group['domain'],
                        'url' => (string) $link['url'],
                        'site_id' => (int) $group['site_id'],
                        'role' => (string) $link['role'],
                    ]);

                return $items;
            })
            ->unique(static fn (array $item): string => $item['site_id'].'|'.$item['url'].'|'.$item['role'])
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     domain: string,
     *     site_id: int,
     *     main_links: list<array{url: string, role: string, link_id: int}>,
     *     internal_links: list<array{
     *         url: string,
     *         destination_url: string,
     *         source_url: string|null,
     *         role: string,
     *         link_id: int,
     *         source_label: string|null
     *     }>
     * }>
     */
    public static function resolveLinkDestinationGroups(Keyword $record): array
    {
        $domainMap = static::siteSelectOptions();
        $maps = $record->relationLoaded('linkMaps')
            ? $record->linkMaps
            : $record->linkMaps()
                ->orderBy('id')
                ->with([
                    'sourceArticle:id,site_id,title,slug',
                    'targetArticle:id,site_id,title,slug',
                ])
                ->get();

        /** @var array<int, array{domain: string, site_id: int, main_links: list<array<string, mixed>>, internal_links: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap) {
                continue;
            }

            $sourceArticle = $map->relationLoaded('sourceArticle')
                ? $map->sourceArticle
                : $map->sourceArticle()->first(['id', 'site_id', 'title', 'slug']);

            $siteId = (int) ($sourceArticle?->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $domain = trim((string) ($domainMap[$siteId] ?? ''));
            if ($domain === '') {
                $domain = '#'.$siteId;
            }

            if (! array_key_exists($siteId, $groups)) {
                $groups[$siteId] = [
                    'domain' => $domain,
                    'site_id' => $siteId,
                    'main_links' => [],
                    'internal_links' => [],
                ];
            }

            $destinationUrl = static::resolveLinkMapDestinationUrl($map, $siteId, $domain);
            if ($destinationUrl === '') {
                continue;
            }

            $sourceUrl = $sourceArticle instanceof SeoArticle
                ? app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($sourceArticle)
                : null;

            $linkPayload = [
                'url' => $destinationUrl,
                'role' => static::LINK_ROLE_INTERNAL_ANCHOR,
                'link_id' => (int) $map->id,
                'destination_url' => $destinationUrl,
                'source_url' => is_string($sourceUrl) ? trim($sourceUrl) : null,
                'source_label' => static::resolveLinkMapSourceLabel($sourceArticle),
                'source_article_id' => (int) ($map->source_article_id ?? 0),
            ];

            $dedupeKey = $destinationUrl.'|'.((int) ($map->source_article_id ?? 0));
            $existingKeys = collect($groups[$siteId]['internal_links'])
                ->map(static fn (array $item): string => ($item['destination_url'] ?? $item['url'] ?? '').'|'.((int) ($item['source_article_id'] ?? 0)))
                ->all();

            if (in_array($dedupeKey, $existingKeys, true)) {
                continue;
            }

            $groups[$siteId]['internal_links'][] = $linkPayload;
        }

        $mainArticles = $record->relationLoaded('mainArticles')
            ? $record->mainArticles
            : $record->mainArticles()->with('site')->get();

        foreach ($mainArticles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $siteId = (int) ($article->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $domain = trim((string) ($domainMap[$siteId] ?? ''));
            if ($domain === '') {
                $domain = '#'.$siteId;
            }

            if (! array_key_exists($siteId, $groups)) {
                $groups[$siteId] = [
                    'domain' => $domain,
                    'site_id' => $siteId,
                    'main_links' => [],
                    'internal_links' => [],
                ];
            }

            $absoluteUrl = trim((string) (app(KeywordLinkTargetResolver::class)->resolveArticlePublicUrl($article) ?? ''));
            if ($absoluteUrl === '') {
                continue;
            }

            $groups[$siteId]['main_links'][] = [
                'url' => $absoluteUrl,
                'role' => static::LINK_ROLE_MAIN,
                'link_id' => (int) $article->id,
                'target_article_id' => (int) $article->id,
            ];
        }

        return static::enrichLinkDestinationGroups(
            collect($groups)
                ->sortBy('domain')
                ->values()
                ->all(),
            $record,
        );
    }

    public static function resolveLinkMapSourceLabel(?SeoArticle $sourceArticle): ?string
    {
        if (! $sourceArticle instanceof SeoArticle) {
            return null;
        }

        $title = trim((string) ($sourceArticle->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        $slug = trim((string) ($sourceArticle->slug ?? ''));

        return $slug !== '' ? $slug : null;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function enrichLinkDestinationGroups(array $groups, ?Keyword $record = null): array
    {
        return array_map(static function (array $group) use ($record): array {
            $siteId = (int) ($group['site_id'] ?? 0);

            $group['main_links'] = array_map(static function (array $link) use ($record, $siteId): array {
                $url = (string) ($link['url'] ?? '');
                $link['shorthand'] = static::formatLinkShorthand($url);
                $link['display'] = $url;
                $articleId = (int) ($link['target_article_id'] ?? 0);

                if ($articleId <= 0 && $record instanceof Keyword) {
                    $articleId = (int) ($record->mainArticleId() ?? 0);

                    if ($articleId <= 0) {
                        $articleId = (int) ($record->mainArticles()
                            ->where('articles.site_id', $siteId)
                            ->orderBy('articles.id')
                            ->value('articles.id') ?? 0);
                    }
                }

                $link['edit_url'] = $articleId > 0
                    ? ArticleResource::getUrl('edit', ['record' => $articleId])
                    : $url;
                $link['is_edit_link'] = $articleId > 0;

                return $link;
            }, $group['main_links'] ?? []);

            $group['internal_links'] = array_map(static function (array $link): array {
                $destinationUrl = (string) ($link['destination_url'] ?? $link['url'] ?? '');
                $sourceUrl = (string) ($link['source_url'] ?? '');
                $sourceLabel = trim((string) ($link['source_label'] ?? ''));
                $sourceArticleId = (int) ($link['source_article_id'] ?? 0);

                $link['destination_shorthand'] = static::formatLinkShorthand($destinationUrl);
                $link['source_shorthand'] = $sourceUrl !== ''
                    ? static::formatLinkShorthand($sourceUrl)
                    : ($sourceLabel !== '' ? $sourceLabel : '—');
                $link['source_display'] = $sourceLabel !== '' ? $sourceLabel : $sourceUrl;
                $link['destination_display'] = $destinationUrl;
                $link['source_edit_url'] = $sourceArticleId > 0
                    ? ArticleResource::getUrl('edit', ['record' => $sourceArticleId])
                    : ($sourceUrl !== '' ? $sourceUrl : null);
                $link['source_is_edit_link'] = $sourceArticleId > 0;

                return $link;
            }, $group['internal_links'] ?? []);

            $mainCount = count($group['main_links'] ?? []);
            $internalCount = count($group['internal_links'] ?? []);
            $hasInternal = $internalCount > 0;

            $group['badge'] = [
                'variant' => $hasInternal ? 'gray' : 'success',
                'icon' => $hasInternal ? 'heroicon-m-link' : 'heroicon-m-bookmark-square',
                'emoji' => $hasInternal ? '🔗' : '🎯',
                'count' => $mainCount + $internalCount,
            ];

            return $group;
        }, $groups);
    }

    public static function formatLinkShorthand(string $url, int $maxLength = 36): string
    {
        $url = trim($url);
        if ($url === '') {
            return '—';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '' && $path !== '/') {
            $label = ltrim($path, '/');
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            $label = is_string($host) && $host !== '' ? $host : $url;
        }

        if (mb_strlen($label) <= $maxLength) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, max(1, $maxLength - 1))).'…';
    }

    public static function buildAbsoluteLinkUrl(string $url, int $siteId, ?string $domain = null): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $domain ??= trim((string) (static::siteSelectOptions()[$siteId] ?? ''));
        if ($domain === '') {
            return $url;
        }

        if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
            $domain = 'https://'.$domain;
        }

        $domain = rtrim($domain, '/');

        return str_starts_with($url, '/') ? $domain.$url : $domain.'/'.$url;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return list<int>
     */
    public static function resolveBulkKeywordsSiteIds(Collection $records): array
    {
        return $records
            ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
            ->flatMap(static function (Keyword $keyword): Collection {
                if ($keyword->relationLoaded('linkMaps')) {
                    return $keyword->linkMaps
                        ->map(static fn (SeoLinkMap $map): int => (int) ($map->sourceArticle?->site_id ?? 0));
                }

                return $keyword->linkMaps()
                    ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
                    ->pluck('articles.site_id');
            })
            ->map(static fn (mixed $siteId): int => (int) $siteId)
            ->filter(static fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|list<mixed>  $types
     * @return list<string>
     */
    public static function resolveKeywordTypeFilterLabels(array $types): array
    {
        $options = static::keywordTypeFilterOptions();

        return collect($types)
            ->filter(static fn (mixed $type): bool => is_string($type) && $type !== '')
            ->filter(static fn (string $type): bool => isset($options[$type]))
            ->map(static fn (string $type): string => $options[$type])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|list<mixed>  $tagIds
     * @return list<string>
     */
    public static function resolveTagFilterLabels(array $tagIds): array
    {
        $ids = collect($tagIds)
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(KeywordTagResolver::tableEagerLoad())
            ->selectRaw('keywords.*, '.static::wordCountExpression().' as word_count')
            ->withCount([
                'mainArticles as main_articles_count',
                ...Keyword::linkMapCountRelations(),
                'linkMaps as inbound_links_count',
                            ]);

        $query = static::excludeStagingSuggestTypes($query);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = SeoAccessControl::accessibleSiteIds();
            $query->forSites($siteIds);
        }

        return static::applyMinimumKeywordWordCount(
            InternalAnchorKeywordFilter::applyExcludeLinkLikePhrases($query),
        );
    }

    /**
     * Query từ điển cho tab Cần tối ưu / Không hiệu quả — không lọc word-count, anchor giả, hay bắt buộc link map.
     */
    public static function getReviewedDictionaryQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(array_merge(KeywordTagResolver::tableEagerLoad(), [
                'reviewReason:id,name,default_severity',
            ]))
            ->selectRaw('keywords.*, '.static::wordCountExpression().' as word_count')
            ->withCount([
                'mainArticles as main_articles_count',
                ...Keyword::linkMapCountRelations(),
                'linkMaps as inbound_links_count',
                            ])
            ->whereIn('review_status', [
                KeywordReviewStatus::Danger->value,
                KeywordReviewStatus::Warning->value,
            ]);

        $query = static::excludeStagingSuggestTypes($query);

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $siteIds = SeoAccessControl::accessibleSiteIds();
            $query->forSites($siteIds);
        }

        return $query;
    }

    /**
     * Keyword Dictionary = active SEO inventory only.
     * TYPE_SUGGEST is SEO Audit staging (Vocabulary Suggest / MCP Suggest) — never list here.
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function excludeStagingSuggestTypes(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('type')
                ->orWhere('type', '!=', Keyword::TYPE_SUGGEST);
        });
    }

    public static function applyReviewedDictionarySiteScope(Builder $query, int $siteId): Builder
    {
        if ($siteId <= 0) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($siteId): void {
            $builder
                ->where(function (Builder $siteScope) use ($siteId): void {
                    $siteScope->forSite($siteId);
                })
                ->orWhereIn('keywords.id', KeywordReviewHistory::query()
                    ->select('keyword_id')
                    ->whereIn('article_id', SeoArticle::query()
                        ->where('site_id', $siteId)
                        ->select('id')));
        });
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function editKeywordFormSchema(Keyword $record): array
    {
        return [
            Forms\Components\Hidden::make('type'),

            Forms\Components\TextInput::make('phrase')
                ->label(__('seo-content-ai::filament.keyword.phrase'))
                ->required()
                ->maxLength(255)
                ->unique(
                    table: Keyword::class,
                    column: 'phrase',
                    ignoreRecord: true,
                )
                ->rule(fn (Get $get): array => ($get('type') ?? $record->type) === Keyword::TYPE_NORMAL
                    ? [function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase((string) $value)) {
                            $fail(__('seo-content-ai::filament.keyword.anchor_text_invalid'));
                        }
                    }]
                    : []),
        ];
    }

    public static function isUnused(Keyword $keyword): bool
    {
        $attributes = $keyword->getAttributes();
        if (
            ! array_key_exists('inbound_links_count', $attributes)
                        || ! array_key_exists('main_articles_count', $attributes)
            || ! array_key_exists('linked_articles_count', $attributes)
        ) {
            $keyword->loadCount([
                'mainArticles',
                ...Keyword::linkMapCountRelations(),
                'linkMaps as inbound_links_count',
            ]);
        }

        if ((int) $keyword->inbound_links_count > 0) {
            return false;
        }

        if ($keyword->type === Keyword::TYPE_SUGGEST) {
            return (int) $keyword->main_articles_count === 0;
        }

        if (Keyword::isNormalType($keyword->type)) {
            return (int) $keyword->main_articles_count === 0
                && (int) $keyword->linked_articles_count === 0;
        }

        return (int) $keyword->linked_articles_count === 0;
    }

    /**
     * Word count SQL tương thích MySQL 5.7 / MariaDB (không dùng REGEXP_REPLACE).
     * Chuẩn hóa tab/CR/LF → space, gộp khoảng trắng kép vài vòng, rồi đếm theo space.
     */
    private static function wordCountExpression(): string
    {
        $normalized = "TRIM(REPLACE(REPLACE(REPLACE(`phrase`, CHAR(9), ' '), CHAR(10), ' '), CHAR(13), ' '))";
        // Gộp '  ' → ' ' (4 vòng đủ cho phrase keyword thông thường).
        $collapsed = "REPLACE(REPLACE(REPLACE(REPLACE({$normalized}, '  ', ' '), '  ', ' '), '  ', ' '), '  ', ' ')";

        return "CASE WHEN {$collapsed} = '' THEN 0 ELSE "
            ."LENGTH({$collapsed}) - LENGTH(REPLACE({$collapsed}, ' ', '')) + 1 END";
    }

    public static function applyMinimumKeywordWordCount(Builder $query): Builder
    {
        return $query->whereRaw(static::wordCountExpression().' >= 2');
    }

    public static function canAssignKeywordToContentProject(Keyword $keyword): bool
    {
        return app(KeywordProjectAssignmentService::class)->canAssignKeyword($keyword);
    }

    public static function resolveKeywordSiteId(Keyword $keyword): ?int
    {
        return $keyword->resolveSiteId(SeoAccessControl::globalSiteId());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveKeywordDirectAssignData(?int $siteId = null): ?array
    {
        $globalSiteId = SeoAccessControl::globalSiteId();
        $targetSiteId = $siteId ?? $globalSiteId;
        if ($targetSiteId === null || (int) $targetSiteId <= 0) {
            return null;
        }

        $projectId = ArticleResource::resolveDirectAssignContentProjectId((int) $targetSiteId);
        if ($projectId === null) {
            return null;
        }

        $targetSiteId = (int) $targetSiteId;

        return [
            'site_ids' => [$targetSiteId],
            'project_id_'.$targetSiteId => $projectId,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $records
     */
    public static function resolveBulkKeywordsSiteId(Collection $records): ?int
    {
        $siteIds = $records
            ->filter(static fn (mixed $record): bool => $record instanceof Keyword)
            ->map(static fn (Keyword $keyword): ?int => static::resolveKeywordSiteId($keyword))
            ->filter(static fn (?int $siteId): bool => $siteId !== null && $siteId > 0)
            ->unique()
            ->values();

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }

    public static function keywordAssignedContentProjectId(Keyword $keyword): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        $siteId = static::resolveKeywordSiteId($keyword) ?? 0;

        $query = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle]);

        if ($siteId > 0) {
            $query->where(function (Builder $builder) use ($siteId): void {
                $builder
                    ->where('site_id', $siteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public static function keywordIsInContentProject(Keyword $keyword): bool
    {
        return static::keywordAssignedContentProjectId($keyword) !== null;
    }

    /**
     * @return list<Forms\Components\Component>
     */
    public static function addToRankGroupFormSchema(bool $multiple = true): array
    {
        $options = app(SeoRankKeywordGroupService::class)
            ->listOptionsForUser((int) auth()->id());

        $selectOptions = collect($options)
            ->mapWithKeys(static fn (array $row): array => [(int) $row['id'] => (string) $row['label']])
            ->all();

        if (! $multiple) {
            return [
                Forms\Components\Select::make('group_id')
                    ->label(__('seo-content-ai::filament.rank_group.select_groups'))
                    ->options($selectOptions)
                    ->searchable()
                    ->required(),
            ];
        }

        return [
            Forms\Components\Select::make('group_ids')
                ->label(__('seo-content-ai::filament.rank_group.select_groups'))
                ->options($selectOptions)
                ->searchable()
                ->required()
                ->multiple()
                ->minItems(1),
        ];
    }

    /**
     * @param  Collection<int, Keyword>|Collection<int, mixed>  $records
     * @param  array<string, mixed>  $data
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function executeAssignKeywordsToContentProjects(Collection $records, array $data): array
    {
        $summary = [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ];

        $siteIds = collect($data['site_ids'] ?? [])
            ->filter(static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(static fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();

        foreach ($siteIds as $siteId) {
            $projectId = (int) ($data['project_id_'.$siteId] ?? 0);
            if ($projectId <= 0) {
                continue;
            }

            $result = static::assignKeywordsToContentProject($records, $projectId, $siteId);
            foreach ($summary as $key => $value) {
                $summary[$key] = $value + (int) ($result[$key] ?? 0);
            }
        }

        return $summary;
    }

    public static function keywordAssignedContentProjectIdForSite(Keyword $keyword, int $siteId): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        if ($needle === '' || $siteId <= 0) {
            return null;
        }

        $projectId = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    /**
     * @param  Collection<int, Keyword>|Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public static function assignKeywordsToContentProject(Collection $records, int $projectId, int $targetSiteId): array
    {
        return app(\Omnichannel\Addons\Agent\Automation\Migration\AssignmentCallerBridge::class)
            ->assignKeywordsToContentProject(
                $records,
                $projectId,
                $targetSiteId,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
    }

    /**
     * @param  callable(Get, ?Keyword): int  $resolveSiteId
     */
    public static function tagsSelectField(
        callable $resolveSiteId,
        bool $multiple = true,
        string $fieldName = 'tags',
        bool $required = false,
        ?callable $helperTextResolver = null,
        bool $useRelationship = false,
    ): Forms\Components\Select {
        $select = Forms\Components\Select::make($fieldName)
            ->label(__('seo-content-ai::filament.keyword.tags'))
            ->multiple($multiple)
            ->searchable()
            ->preload()
            ->native(false)
            ->required($required)
            ->options(static fn (): array => Tag::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('seo-content-ai::filament.keyword.tag_name'))
                    ->required()
                    ->maxLength(255),
            ])
            ->createOptionUsing(function (array $data): int {
                $name = trim((string) ($data['name'] ?? ''));

                return (int) app(TagPersistenceService::class)
                    ->findOrCreate($name)
                    ->getKey();
            });

        if ($helperTextResolver !== null) {
            $select->helperText(fn (): ?string => $helperTextResolver());
        }

        return $select;
    }

    /**
     * @return list<string>
     */
    public static function resolveTagLabelsForKeyword(Keyword $keyword): array
    {
        $tagIds = $keyword->getTagIdsList();
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', $tagIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateKeywordFormDataForFill(array $data, Keyword $record): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveKeywordFromFormData(Keyword $record, array $data): Keyword
    {
        unset($data['tags']);

        $record->update($data);

        return $record->fresh();
    }

    public static function resolveUniqueTagSlug(string $name): string
    {
        return app(TagPersistenceService::class)->resolveUniqueSlug($name);
    }

    public static function applyInsensitivePhraseSearch(Builder $query, string $search): Builder
    {
        $needle = trim($search);
        if ($needle === '') {
            return $query;
        }

        $norm = app(KeywordNormalizer::class)->normalize($needle);
        $escaped = addcslashes($norm['normalized_text'] !== '' ? $norm['normalized_text'] : mb_strtolower($needle, 'UTF-8'), '%_\\');
        $folded = addcslashes($norm['folded_text'] !== '' ? $norm['folded_text'] : $escaped, '%_\\');
        $like = '%'.$escaped.'%';
        $foldedLike = '%'.$folded.'%';

        return $query->where(function (Builder $inner) use ($like, $foldedLike): void {
            $inner->whereRaw('LOWER(phrase) LIKE ?', [$like])
                ->orWhereHas('seoClassification', function (Builder $classification) use ($like, $foldedLike): void {
                    $classification
                        ->where('normalized_text', 'like', $like)
                        ->orWhere('folded_text', 'like', $foldedLike);
                });
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeywords::route('/'),
            'focus' => Pages\ListFocusKeywords::route('/focus'),
            'anchor-audit' => Pages\AnchorTextAuditWorkspace::route('/anchor-audit'),
            'clusters' => Pages\KeywordTopicClusters::route('/clusters'),
            'cluster' => Pages\KeywordTopicClusterDetail::route('/clusters/{clusterKey}'),
            'workspace-2' => Pages\KeywordWorkspaceTwo::route('/workspace-2'),
            'cannibalization' => Pages\KeywordCannibalizationWorkspace::route('/cannibalization'),
        ];
    }
}
