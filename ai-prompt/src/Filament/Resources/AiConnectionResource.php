<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources;



use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;
use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\AiPrompt\Models\ApiConnectionListRow;
use Omnichannel\Addons\SearchIntelligence\Services\DataForSeoConnectionService;
use Omnichannel\Addons\AiPrompt\Services\SeoExtendedProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\ApiConnection;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiConnectionResource extends SeoPanelResource
{
    protected static ?string $model = ApiConnection::class;

    protected static ?string $slug = 'settings/api';

    protected static ?string $modelLabel = 'API connection';

    protected static ?string $pluralModelLabel = 'API Connections';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return class_exists(SeoAccessControl::class) && SeoAccessControl::canAccessManagerFeatures();
    }

    public static function canCreate(): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        if (class_exists(SeoAccessControl::class)) {
            return static::allowsSeoPanelMutation();
        }

        return true;
    }

    public static function canEdit(Model $record): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        if (class_exists(SeoAccessControl::class) && ! static::allowsSeoPanelMutation()) {
            return false;
        }

        if ($record instanceof ApiConnectionListRow) {
            return ApiConnectionProviders::isExternal((string) $record->getAttribute('provider'));
        }

        return ApiConnectionProviders::isAi((string) $record->getAttribute('provider'));
    }

    public static function resolveEditUrl(Model $record): ?string
    {
        if ($record instanceof ApiConnectionListRow) {
            return static::externalEditUrl(
                (string) $record->getAttribute('provider'),
                (string) $record->getKey(),
            );
        }

        if ($record instanceof ApiConnection && ApiConnectionProviders::isAi((string) $record->getAttribute('provider'))) {
            return static::getUrl('edit', ['record' => $record]);
        }

        return null;
    }

    public static function gscEditUrl(int $recordId, ?string $connectionHash = null): string
    {
        if ($connectionHash !== null && SeoConnectionContext::isValidHashFormat($connectionHash)) {
            return '/seo/'.$connectionHash.'/settings/api/google-search-console/'.$recordId.'/edit';
        }

        return static::getUrl('edit-gsc', ['record' => $recordId]);
    }

    public static function externalEditUrl(string $provider, ?string $listRowId = null): string
    {
        if ($provider === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE) {
            $recordId = ApiConnectionListRow::extractGscId((string) $listRowId);
            if ($recordId !== null) {
                return static::gscEditUrl($recordId);
            }
        }

        return match ($provider) {
            ApiConnectionProviders::DATAFORSEO => static::getUrl('edit-dataforseo'),
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SEARCHAPI => static::getUrl('edit-serp', ['provider' => $provider]),
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING => static::getUrl('edit-extended', ['provider' => $provider]),
            default => static::getUrl('index'),
        };
    }

    public static function canDelete(Model $record): bool
    {
        if (! static::allowsSeoPanelMutation() || ! SeoAccessControl::canAccessManagerFeatures()) {
            return false;
        }

        if ($record instanceof ApiConnection) {
            return ApiConnectionProviders::isAi((string) $record->getAttribute('provider'))
                && ! (bool) $record->getAttribute('is_global');
        }

        if ($record instanceof ApiConnectionListRow) {
            return ApiConnectionProviders::isExternal((string) $record->getAttribute('provider'));
        }

        return false;
    }

    public static function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form->schema(ApiConnectionFormSchema::components());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->emptyStateHeading(__('seo-content-ai::filament.api_connections.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.api_connections.col_connection'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (Model $record): ?string => static::resolveEditUrl($record)),
                Tables\Columns\TextColumn::make('connection_type')
                    ->label(__('seo-content-ai::filament.api_connections.col_type'))
                    ->badge()
                    ->formatStateUsing(function (?string $state, Model $record): string {
                        $type = $state;
                        if (! filled($type) && $record instanceof ApiConnection) {
                            $type = ApiConnectionProviders::connectionType((string) $record->getAttribute('provider'))->value;
                        }

                        return match ($type) {
                            ApiConnectionType::Ai->value => __('seo-content-ai::filament.api_connections.type_ai'),
                            ApiConnectionType::Seo->value => __('seo-content-ai::filament.api_connections.type_seo'),
                            default => (string) $type,
                        };
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        ApiConnectionType::Ai->value => 'info',
                        ApiConnectionType::Seo->value => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('seo-content-ai::filament.api_connections.col_provider'))
                    ->formatStateUsing(
                        function (?string $state, Model $record): string {
                            $label = ApiConnectionProviders::label((string) $state);
                            if (! $record instanceof ApiConnection || ! ApiConnectionProviders::isAi((string) $state)) {
                                return $label;
                            }
                            $code = app(\Omnichannel\Addons\AiPrompt\Services\AiConnectionPresenter::class)
                                ->shortCode($record);

                            return $label.' ['.$code.']';
                        },
                    )
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('seo-content-ai::filament.api_connections.col_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::formatStatusLabel((string) $state))
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn (Model $record): ?string => static::resolveEditUrl($record))
                    ->visible(fn (Model $record): bool => static::canEdit($record)),
                Tables\Actions\DeleteAction::make()
                    ->label(__('seo-content-ai::filament.api_connections.delete_connection'))
                    ->modalHeading(__('seo-content-ai::filament.api_connections.delete_connection'))
                    ->modalDescription(__('seo-content-ai::filament.api_connections.delete_connection_confirm'))
                    ->successNotificationTitle(__('seo-content-ai::filament.api_connections.delete_connection_success'))
                    ->visible(fn (Model $record): bool => static::canDelete($record))
                    ->action(function (Model $record): void {
                        if (! static::deleteRecord($record)) {
                            Notification::make()
                                ->title(__('seo-content-ai::filament.api_connections.delete_connection'))
                                ->body(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('seo-content-ai::filament.api_connections.delete_connection_success'))
                            ->success()
                            ->send();
                    }),
            ])
            ->actionsColumnLabel(__('seo-content-ai::filament.api_connections.table_actions'))
            ->bulkActions([]);
    }

    public static function deleteRecord(Model $record): bool
    {
        $userId = (int) auth()->id();

        if ($record instanceof ApiConnection) {
            if (! ApiConnectionProviders::isAi((string) $record->getAttribute('provider')) || (bool) $record->getAttribute('is_global')) {
                return false;
            }

            $record->delete();

            return true;
        }

        if (! $record instanceof ApiConnectionListRow) {
            return false;
        }

        $provider = (string) $record->getAttribute('provider');
        $rowId = (string) $record->getKey();

        if ($provider === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE) {
            $gscId = ApiConnectionListRow::extractGscId($rowId);

            return $gscId !== null
                && app(GoogleSearchConsoleConnectionService::class)->deleteById($userId, $gscId);
        }

        if ($provider === ApiConnectionProviders::DATAFORSEO) {
            $dfsId = ApiConnectionListRow::extractDfsId($rowId);

            return $dfsId !== null
                && app(DataForSeoConnectionService::class)->deleteById($userId, $dfsId);
        }

        if (ApiConnectionProviders::isSerpProvider($provider)) {
            $serpId = ApiConnectionListRow::extractSerpId($rowId);

            return $serpId !== null
                && app(SeoSerpProviderConnectionService::class)->deleteById($userId, $serpId);
        }

        if (ApiConnectionProviders::isExtendedProvider($provider)) {
            $extId = ApiConnectionListRow::extractExtendedId($rowId);

            return $extId !== null
                && app(SeoExtendedProviderConnectionService::class)->deleteById($userId, $extId);
        }

        return false;
    }

    public static function formatStatusLabel(string $status): string
    {
        return match ($status) {
            'active', 'connected' => __('seo-content-ai::filament.api_connections.status_active'),
            'inactive' => __('seo-content-ai::filament.api_connections.status_inactive'),
            'invalid_credentials' => __('seo-content-ai::filament.api_connections.status_invalid_credentials'),
            'quota_exhausted' => __('seo-content-ai::filament.api_connections.status_quota_exhausted'),
            'sync_required' => __('seo-content-ai::filament.api_connections.sync_required'),
            'mapping_required' => __('seo-content-ai::filament.api_connections.mapping_required'),
            'token_expired' => __('seo-content-ai::filament.api_connections.token_expired'),
            'reauthorization_required' => __('seo-content-ai::filament.api_connections.reauthorization_required'),
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = auth()->id();

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiConnections::route('/'),
            'create' => Pages\CreateAiConnection::route('/create'),
            'gsc-edit-legacy' => Pages\LegacyGscEditRedirect::route('/gsc/edit'),
            'gsc-edit-root-legacy' => Pages\LegacyGscRootEditRedirect::route('/google-search-console/edit'),
            'edit-gsc' => Pages\EditGscApiConnection::route('/google-search-console/{record}/edit'),
            'edit-dataforseo' => Pages\EditDataForSeoApiConnection::route('/dataforseo/edit'),
            'edit-serp' => Pages\EditSerpProviderApiConnection::route('/serp/{provider}/edit'),
            'edit-extended' => Pages\EditExtendedProviderApiConnection::route('/extended/{provider}/edit'),
            'edit' => Pages\EditAiConnection::route('/{record}/edit'),
        ];
    }
}
