<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Forms\DomainTechnicalSeoForm;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;
use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Help\HelpUi;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DomainResource extends SeoPanelResource
{
    protected static ?string $model = Site::class;

    protected static ?string $slug = 'domains';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Domains';

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_DOMAINS;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('domain')
                    ->label(__('seo-content-ai::filament.domain.domain'))
                    ->placeholder('example.com')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null || $state === '') {
                            return;
                        }
                        if (filter_var($state, FILTER_VALIDATE_URL) || str_contains($state, '://')) {
                            $host = parse_url($state, PHP_URL_HOST);
                            if (is_string($host) && $host !== '') {
                                $set('domain', $host);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('ssl')
                    ->label(__('seo-content-ai::filament.domain.ssl'))
                    ->default(true),

                Forms\Components\Select::make('status')
                    ->label(__('seo-content-ai::filament.domain.status'))
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),

                Forms\Components\Select::make('seo_platform')
                    ->label(__('seo-content-ai::filament.domain.platform'))
                    ->options([
                        'wordpress' => 'WordPress',
                        'shopify' => 'Shopify',
                        'custom' => 'Custom',
                    ])
                    ->required()
                    ->native(false)
                    ->live()
                    ->hintAction(HelpUi::fieldHintAction('domain.platform')),
                Forms\Components\Select::make('seo_domain_type')
                    ->label(__('seo-content-ai::filament.domain.website_type'))
                    ->options(DomainListPresentation::websiteTypeFormOptions())
                    ->required()
                    ->native(false)
                    ->hintAction(HelpUi::fieldHintAction('domain.website_type')),
                Forms\Components\TextInput::make('seo_read_token')
                    ->label('Read token')
                    ->key('seo_read_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Can be copied with Ctrl+C.')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_read_token')
                            ->label('Generate')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_read_token', Str::random(60)))
                    ),
                Forms\Components\TextInput::make('seo_migration_token')
                    ->label('Migration / Write token')
                    ->key('seo_migration_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Used as API WRITE TOKEN in the WordPress plugin (post comment/review).')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_migration_token')
                            ->label('Generate')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_migration_token', Str::random(60)))
                    ),
                Forms\Components\Section::make(__('seo-content-ai::filament.domain.primary_language_section'))
                    ->schema([
                        Forms\Components\Select::make('seo_primary_language')
                            ->label(__('seo-content-ai::filament.domain.primary_language'))
                            ->options(function ($livewire): array {
                                $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                                if (! $record instanceof Site) {
                                    return [];
                                }

                                return app(SitePrimaryLanguageService::class)->formLanguageOptions($record);
                            })
                            ->disabled(function ($livewire): bool {
                                $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                                if (! $record instanceof Site) {
                                    return true;
                                }

                                $svc = app(SitePrimaryLanguageService::class);
                                if ($svc->hasPolylang($record)) {
                                    return $svc->syncedLanguageOptions($record) === [];
                                }

                                return $svc->formLanguageOptions($record) === [];
                            })
                            ->dehydrated(true)
                            ->helperText(function ($livewire): string {
                                $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                                if (! $record instanceof Site) {
                                    return (string) __('seo-content-ai::filament.domain.primary_language_none_synced');
                                }

                                $svc = app(SitePrimaryLanguageService::class);
                                if (! $svc->hasPolylang($record)) {
                                    return (string) __('seo-content-ai::filament.domain.primary_language_no_polylang');
                                }

                                if ($svc->syncedLanguageOptions($record) === []) {
                                    return (string) __('seo-content-ai::filament.domain.primary_language_none_synced');
                                }

                                return (string) __('seo-content-ai::filament.domain.primary_language_hint');
                            })
                            ->native(false)
                            ->nullable()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                ...DomainTechnicalSeoForm::schema(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ViewColumn::make('domain')
                    ->label(__('seo-content-ai::filament.domain.domain'))
                    ->view('seo-content-ai::filament.tables.columns.domain-with-description')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('domain', 'like', '%'.$search.'%');
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('seo_platform')
                    ->label(__('seo-content-ai::filament.domain.platform'))
                    ->getStateUsing(fn (Site $record): string => DomainListPresentation::platformLabel(
                        (string) ($record->getMeta('seo_platform') ?? '')
                    ))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('seo_domain_type')
                    ->label(__('seo-content-ai::filament.domain.website_type'))
                    ->getStateUsing(fn (Site $record): string => DomainListPresentation::websiteTypeLabel(
                        (string) ($record->getMeta('seo_domain_type') ?? '')
                    ))
                    ->toggleable(),
                Tables\Columns\ViewColumn::make('bridge_version')
                    ->label(__('seo-content-ai::filament.domain.bridge_version'))
                    ->view('seo-content-ai::filament.tables.columns.domain-bridge-version')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sync_status')
                    ->label(__('seo-content-ai::filament.domain.sync_status'))
                    ->getStateUsing(function (Site $record): string {
                        return DomainListPresentation::syncSummary($record)['label'];
                    })
                    ->badge()
                    ->color(function (Site $record): string {
                        return match (DomainListPresentation::syncSummary($record)['key']) {
                            'healthy' => 'success',
                            'syncing' => 'info',
                            'failed' => 'danger',
                            'attention' => 'warning',
                            'never' => 'gray',
                            default => 'gray',
                        };
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_sync')
                    ->label(__('seo-content-ai::filament.domain.last_sync'))
                    ->getStateUsing(function (Site $record): string {
                        return DomainListPresentation::syncSummary($record)['last_label'];
                    })
                    ->tooltip(function (Site $record): ?string {
                        return DomainListPresentation::syncSummary($record)['last_title'];
                    })
                    ->toggleable(),
                Tables\Columns\ViewColumn::make('is_main')
                    ->label(__('seo-content-ai::filament.domain.main_short'))
                    ->view('seo-content-ai::filament.tables.columns.domain-main-star'),
            ])
            ->defaultSort('domain')
            ->recordUrl(fn (Site $record): string => static::getUrl('general', ['record' => $record]))
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('overview')
                        ->label(__('seo-content-ai::filament.domain.overview'))
                        ->icon('heroicon-o-chart-bar')
                        ->color('info')
                        ->url(fn (Site $record): string => static::getUrl('general', ['record' => $record])),
                    Tables\Actions\Action::make('set_as_main')
                        ->label(__('seo-content-ai::filament.domain.set_as_main'))
                        ->icon('heroicon-o-star')
                        ->color('warning')
                        ->visible(fn (Site $record): bool => static::allowsSeoPanelMutation()
                            && ! app(SeoMainDomainService::class)->isMain($record))
                        ->requiresConfirmation()
                        ->modalDescription(__('seo-content-ai::filament.domain.set_as_main_confirm'))
                        ->action(function (Site $record): void {
                            app(SeoMainDomainService::class)->setAsMain($record);

                            Notification::make()
                                ->title(__('seo-content-ai::filament.domain.set_as_main_success'))
                                ->body(__('seo-content-ai::filament.domain.set_as_main_success_body', [
                                    'domain' => $record->domain,
                                ]))
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->label('Xóa')
                        ->icon('heroicon-o-trash'),
                ])
                    ->label(__('seo-content-ai::filament.domain.actions'))
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->button()
                    ->size('sm'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('metas');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            return $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessManagerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessManagerFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.domain_list');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'create' => Pages\CreateDomain::route('/create'),
            'edit' => Pages\EditDomain::route('/{record}/edit'),
            'info' => Pages\RedirectDomainInfoToEdit::route('/{record}/info'),
            'general' => Pages\GeneralDomain::route('/{record}/general'),
            'mcp' => Pages\ViewDomainMcp::route('/{record}/mcp'),
            'internal-links' => Pages\ListDomainInternalLinks::route('/{record}/internal-links'),
        ];
    }
}
