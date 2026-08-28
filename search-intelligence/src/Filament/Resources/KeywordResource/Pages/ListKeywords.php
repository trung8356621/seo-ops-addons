<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewSource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordDetailDrawer;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordItemActions;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use App\Core\Operations\LongRunningProgress;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListKeywords extends ListRecords
{
    use HasKeywordWorkspaceNavigation;
    use InteractsWithKeywordDetailDrawer;
    use InteractsWithKeywordItemActions;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.list-keywords';

    #[Url(as: 'stat')]
    public ?string $dictionaryStatFilter = null;

    #[Url(as: 'cluster')]
    public ?string $clusterKeyFilter = null;

    /** @var array<int, list<string>> */
    public array $dictionaryKeywordDnaMap = [];

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

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);
        $ids = collect($paginator->items())
            ->map(static fn (Keyword $keyword): int => (int) $keyword->getKey())
            ->all();
        $this->dictionaryKeywordDnaMap = $ids === []
            ? []
            : app(KeywordDnaService::class)->displayValuesForKeywords($ids);

        return $paginator;
    }

    public function assignToContentProjectAction(): Actions\Action
    {
        return AssignToContentProjectActionFactory::pageAction(
            resolvePayload: function (array $arguments): array {
                $mapId = (int) ($arguments['mapId'] ?? 0);
                $keyword = $this->resolveKeywordForMapId($mapId);
                $siteId = $this->resolveMapSiteId($mapId);

                return AssignToContentProjectContract::keywordPayload(
                    source: 'keyword_detail_link_map',
                    keywordIds: $keyword instanceof Keyword ? [(int) $keyword->id] : [],
                    siteIds: $siteId !== null && $siteId > 0 ? [$siteId] : [],
                    mapId: $mapId > 0 ? $mapId : null,
                );
            },
            name: 'assignToContentProject',
        );
    }

    public function assignArticleToContentProjectAction(): Actions\Action
    {
        return AssignToContentProjectActionFactory::pageAction(
            resolvePayload: function (array $arguments): array {
                $article = $this->resolveArticleForAssign((int) ($arguments['articleId'] ?? 0));
                $siteId = $article instanceof SeoArticle
                    ? ArticleResource::resolveArticleSiteId($article)
                    : null;

                return AssignToContentProjectContract::articlePayload(
                    source: 'keyword_dictionary_drawer',
                    articleIds: $article instanceof SeoArticle ? [(int) $article->id] : [],
                    siteId: $siteId,
                    options: [
                        'show_quick_create' => true,
                        'show_article_fields' => true,
                        'show_keyword_override' => true,
                        'show_title_override' => true,
                    ],
                );
            },
            name: 'assignArticleToContentProject',
        );
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

    public function table(Table $table): Table
    {
        $table = KeywordResource::table($table);

        $table
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applyDictionaryStatScope($query));

        return $table
            ->actions($this->listPageTableActions());
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return new HtmlString('');
    }

    /**
     * @return array{total: int, active: int, errors: int}
     */
    public function getDictionaryStats(): array
    {
        $query = $this->buildDictionaryListingQuery();

        if (! $query instanceof Builder) {
            return [
                'total' => 0,
                'active' => 0,
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
            'errors' => (clone $reviewScopeQuery)
                ->whereIn('review_status', [
                    KeywordReviewStatus::Danger->value,
                    KeywordReviewStatus::Warning->value,
                ])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getClassificationSummary(): array
    {
        return KeywordClassificationVisibility::summarize($this->resolveKeywordWorkspaceSiteId());
    }

    /**
     * @return array{visible: bool, running: bool, label: string, counts: string}|null
     */
    public function getKeywordIntelligenceProgress(): ?array
    {
        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        $progress = app(KeywordClassificationService::class)->readProgress($siteId);
        if (! $progress instanceof LongRunningProgress) {
            return null;
        }

        $running = in_array($progress->status, ['queued', 'running'], true);
        if (! $running) {
            return null;
        }

        $current = (int) $progress->current;
        $total = max(1, (int) $progress->total);
        $pct = (int) round(($current / $total) * 100);

        return [
            'visible' => true,
            'running' => true,
            'label' => __('seo-content-ai::filament.keyword.classification_running'),
            'counts' => __('seo-content-ai::filament.keyword.classification_progress_counts', [
                'current' => number_format($current),
                'total' => number_format($total),
                'pct' => $pct,
            ]),
        ];
    }

    public function applyDictionaryStatFilter(string $statKey): void
    {
        $allowed = ['total', 'active', 'errors'];
        if ($statKey === 'needs_optimization') {
            $statKey = 'errors';
        }
        if (! in_array($statKey, $allowed, true)) {
            return;
        }

        $this->dictionaryStatFilter = $this->dictionaryStatFilter === $statKey ? null : $statKey;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function getSubheading(): ?string
    {
        return null;
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
        ];
    }

    /**
     * Keyword dictionary is domain-scoped via GlobalSeoBar (default: first accessible domain).
     */
    protected function getTableQuery(): ?Builder
    {
        if (! $this->dictionaryListingRequiresLinkedScope()) {
            return $this->buildDictionaryReviewStatusQuery();
        }

        return $this->buildDictionaryListingQuery();
    }

    protected function dictionaryListingRequiresLinkedScope(): bool
    {
        return ! in_array((string) ($this->dictionaryStatFilter ?? ''), [
            'errors',
            'needs_optimization',
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

        $siteId = (int) ($this->resolveKeywordWorkspaceSiteId() ?? 0);
        if ($siteId > 0) {
            $query->forSite($siteId);
        }

        if ($requireLinkedScope) {
            if ($this->getKeywordWorkspaceMode() === 'focus') {
                $query->whereHas('mainArticles');
            } else {
                $query->whereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                );
            }
        }

        // Flat dictionary: phrase ordering only (no hierarchy expand/nest).
        return $query->orderBy('phrase');
    }

    protected function applyDictionaryStatScope(Builder $query): Builder
    {
        $statKey = $this->dictionaryStatFilter;

        if ($statKey === null || $statKey === '' || $statKey === 'total') {
            $query = $this->applyClusterKeyScope($query);

            return $query;
        }

        $query = match ($statKey) {
            'active' => $query->where(function (Builder $builder): void {
                $builder
                    ->whereHas('mainArticles')
                    ->orWhereHas(
                        'linkMaps',
                        static fn (Builder $mapQuery): Builder => $mapQuery->whereNotNull('source_article_id'),
                    );
            }),
            'errors', 'needs_optimization' => $query->whereIn('review_status', [
                KeywordReviewStatus::Danger->value,
                KeywordReviewStatus::Warning->value,
            ]),
            default => $query,
        };

        return $this->applyClusterKeyScope($query);
    }

    protected function applyClusterKeyScope(Builder $query): Builder
    {
        $key = trim((string) ($this->clusterKeyFilter ?? ''));
        if ($key === '') {
            return $query;
        }
        if ($key === '_none') {
            return app(KeywordClusterEligibility::class)->applyUnclusteredSeoKeywordScope($query);
        }

        return $query->whereHas(
            'seoClassification',
            static fn (Builder $classification): Builder => $classification->where('cluster_key', $key),
        );
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

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
