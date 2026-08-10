<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordIntelligence;

use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ArchiveKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ListKeywordWorkspaces extends SeoPanelPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'keyword-intelligence';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.keyword-intelligence.list-keyword-workspaces';

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.keyword_intelligence.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.keyword_intelligence.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->workspacesQuery())
            ->heading(__('seo-content-ai::filament.keyword_intelligence.list_heading'))
            ->emptyStateHeading(__('seo-content-ai::filament.keyword_intelligence.empty'))
            ->emptyStateIcon('heroicon-o-magnifying-glass-circle')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_name'))
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('site.name')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_site'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : (string) $state) {
                        'archived' => 'gray',
                        'ready' => 'success',
                        'analyzing' => 'warning',
                        'failed' => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('keyword_count')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_keywords'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cluster_count')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_clusters'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_analyzed_at')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_last_analyzed'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.col_created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->headerActions([
                $this->createWorkspaceAction(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SeoKeywordWorkspace $record): string => ViewKeywordWorkspace::getUrl([
                        'workspace_ref' => (string) $record->public_ref,
                    ])),
                Tables\Actions\Action::make('archive')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.archive'))
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (SeoKeywordWorkspace $record): bool => $record->archived_at === null)
                    ->action(fn (SeoKeywordWorkspace $record) => $this->archiveWorkspace($record)),
            ]);
    }

    private function workspacesQuery(): Builder
    {
        $query = SeoKeywordWorkspace::query()->with('site:id,name');

        $siteIds = SeoAccessControl::accessibleSiteIds();
        if ($siteIds !== []) {
            $query->whereIn('site_id', $siteIds);
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null) {
            $query->where('site_id', $globalSiteId);
        }

        return $query;
    }

    private function createWorkspaceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('createWorkspace')
            ->label(__('seo-content-ai::filament.keyword_intelligence.create'))
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading(__('seo-content-ai::filament.keyword_intelligence.create_heading'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword_intelligence.create'))
            ->form($this->createWorkspaceFormSchema())
            ->action(function (array $data): void {
                $this->persistNewWorkspace($data);
            });
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function createWorkspaceFormSchema(): array
    {
        return [
            Forms\Components\Select::make('site_id')
                ->label(__('seo-content-ai::filament.keyword_intelligence.field_site'))
                ->options(fn (): array => $this->accessibleSiteOptions())
                ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                ->required()
                ->native(false),
            Forms\Components\TextInput::make('name')
                ->label(__('seo-content-ai::filament.keyword_intelligence.field_name'))
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->label(__('seo-content-ai::filament.keyword_intelligence.field_description'))
                ->rows(2)
                ->maxLength(2000),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('language')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.field_language'))
                    ->maxLength(10)
                    ->placeholder('vi'),
                Forms\Components\TextInput::make('country')
                    ->label(__('seo-content-ai::filament.keyword_intelligence.field_country'))
                    ->maxLength(10)
                    ->placeholder('VN'),
            ]),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function accessibleSiteOptions(): array
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $query = Site::query()->orderBy('name');
        if ($siteIds !== []) {
            $query->whereIn('id', $siteIds);
        }

        return $query->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistNewWorkspace(array $data): void
    {
        $siteId = (int) ($data['site_id'] ?? 0);
        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            abort(403);
        }

        $attributes = [
            'site_id' => $siteId,
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'language' => trim((string) ($data['language'] ?? '')) ?: null,
            'country' => trim((string) ($data['country'] ?? '')) ?: null,
        ];

        $command = new CreateKeywordWorkspaceCommand($attributes);
        $result = app(ContentProjectCommandBus::class)->dispatch($command, $this->actorContext($siteId));

        app(ContentProjectActionResultNotifier::class)->send($result);

        if ($result->success) {
            $workspaceRef = (string) ($result->metadata['workspace_ref'] ?? '');
            if ($workspaceRef !== '') {
                $this->redirect(ViewKeywordWorkspace::getUrl(['workspace_ref' => $workspaceRef]));

                return;
            }
        }

        $this->resetTable();
    }

    private function archiveWorkspace(SeoKeywordWorkspace $record): void
    {
        if (! SeoAccessControl::canAccessSite((int) $record->site_id)) {
            abort(403);
        }

        $command = new ArchiveKeywordWorkspaceCommand((string) $record->public_ref);
        $result = app(ContentProjectCommandBus::class)->dispatch($command, $this->actorContext((int) $record->site_id));

        app(ContentProjectActionResultNotifier::class)->send($result);
        $this->resetTable();
    }

    private function actorContext(int $siteId): ActorContext
    {
        return ActorContext::user(
            auth()->id() !== null ? (int) auth()->id() : null,
            $siteId,
        );
    }
}
