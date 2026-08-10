<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\Seo\Filament\Concerns\HidesFilamentPageHeader;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleBulkSyncService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page as ResourcePage;

class EditGscApiConnection extends ResourcePage implements HasForms
{
    use HidesFilamentPageHeader;
    use InteractsWithForms;

    protected static string $resource = AiConnectionResource::class;

    protected static ?string $slug = 'google-search-console/{record}/edit';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-form';

    protected static bool $shouldRegisterNavigation = false;

    public int $gscConnectionId = 0;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $gscBulkSyncResult = null;

    public bool $isGscBulkSyncing = false;

    private GoogleSearchConsoleConnectionService $gscConnection;

    private GoogleSearchConsoleSyncService $gscSync;

    private GoogleSearchConsoleBulkSyncService $gscBulkSync;

    public function boot(
        GoogleSearchConsoleConnectionService $gscConnection,
        GoogleSearchConsoleSyncService $gscSync,
        GoogleSearchConsoleBulkSyncService $gscBulkSync,
    ): void {
        $this->gscConnection = $gscConnection;
        $this->gscSync = $gscSync;
        $this->gscBulkSync = $gscBulkSync;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function mount(int|string $record): void
    {
        $this->gscConnectionId = (int) $record;
        $connection = $this->resolveConnectionRecord();
        if ($connection === null) {
            abort(404);
        }

        $this->hydratePropertiesIfNeeded($connection);

        $this->fillFormFromConnection($connection->fresh() ?? $connection);
        $this->notifyOAuthFlash();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(ApiConnectionFormSchema::components(operation: 'edit', lockProvider: true))
            ->statePath('data');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.api_connections.edit_gsc');
    }

    protected function getHeaderActions(): array
    {
        $hash = SeoConnectionContext::hash();
        $connection = $this->resolveConnectionRecord();
        $status = $connection !== null
            ? $this->gscConnection->resolveEffectiveStatus($connection)
            : 'not_configured';
        $canConnect = $connection !== null && $this->gscConnection->hasOAuthAppCredentials($connection);

        $actions = [];

        if ($hash !== null && $this->gscConnectionId > 0) {
            if (in_array($status, ['not_configured', 'reauthorization_required'], true)) {
                $actions[] = Action::make('connect')
                    ->label(__('seo-content-ai::filament.api_connections.gsc_connect'))
                    ->icon('heroicon-o-link')
                    ->disabled(! $canConnect)
                    ->tooltip($canConnect ? null : __('seo-content-ai::filament.api_connections.gsc_oauth_app_required'))
                    ->url($canConnect ? route('seo.gsc.oauth.redirect', [
                        'connection_hash' => $hash,
                        'record' => $this->gscConnectionId,
                        'reconnect' => $status === 'reauthorization_required' ? 1 : 0,
                    ]) : null);
            } else {
                $actions[] = Action::make('reconnect')
                    ->label(__('seo-content-ai::filament.api_connections.gsc_reconnect'))
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(! $canConnect)
                    ->tooltip($canConnect ? null : __('seo-content-ai::filament.api_connections.gsc_oauth_app_required'))
                    ->url($canConnect ? route('seo.gsc.oauth.redirect', [
                        'connection_hash' => $hash,
                        'record' => $this->gscConnectionId,
                        'reconnect' => 1,
                    ]) : null);
                $actions[] = Action::make('disconnect')
                    ->label(__('seo-content-ai::filament.api_connections.gsc_disconnect'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action('disconnectConnection');
                $actions[] = Action::make('refreshProperties')
                    ->label(__('seo-content-ai::filament.api_connections.gsc_refresh_properties'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action('refreshProperties');
                $actions[] = Action::make('autoMapAndSyncAll')
                    ->label(__('seo-content-ai::filament.api_connections.gsc_auto_map_sync_all'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->disabled(fn (): bool => $this->isGscBulkSyncing)
                    ->action('autoMapAndSyncAll');
            }
        }

        if (in_array($status, ['connected', 'token_expired'], true)) {
            $actions[] = Action::make('test')
                ->label(__('seo-content-ai::filament.api_connections.test_connection'))
                ->action('testConnection');
            $actions[] = Action::make('sync')
                ->label(__('seo-content-ai::filament.api_connections.sync_gsc'))
                ->action('syncCurrentSite');
        }

        return $actions;
    }

    public function save(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $connection = $this->resolveConnectionRecord();
        if ($connection === null) {
            abort(404);
        }

        $data = $this->form->getState();
        $connection = $this->gscConnection->saveMasterConnection((int) auth()->id(), [
            'name' => $data['name'] ?? 'Google Search Console',
            'oauth_client_id' => trim((string) ($data['gsc_oauth_client_id'] ?? '')),
            'oauth_client_secret' => array_key_exists('gsc_oauth_client_secret', $data)
                ? trim((string) ($data['gsc_oauth_client_secret'] ?? ''))
                : null,
        ], $this->gscConnectionId);

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
        $propertyUrl = trim((string) ($data['gsc_property_url'] ?? ''));
        if ($siteId > 0 && $propertyUrl !== '') {
            try {
                $this->gscConnection->mapSiteProperty($connection, $siteId, $propertyUrl);
            } catch (\InvalidArgumentException $exception) {
                Notification::make()
                    ->title($exception->getMessage())
                    ->danger()
                    ->send();

                return;
            }

            $syncResult = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
            if (! $syncResult['ok']) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.api_connections.gsc_saved'))
                    ->body($syncResult['message'])
                    ->warning()
                    ->send();

                $this->redirect(AiConnectionResource::getUrl());

                return;
            }
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.gsc_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    public function testConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $connection = $this->resolveConnectionRecord();
        if ($connection === null || ! $this->gscConnection->canCallGscApi($connection)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_missing_credentials'))
                ->danger()
                ->send();

            return;
        }

        $result = $this->gscConnection->testConnection($connection);
        $this->fillFormFromConnection($connection->fresh() ?? $connection);

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.test_success')
                : __('seo-content-ai::filament.api_connections.test_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    public function refreshProperties(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $connection = $this->resolveConnectionRecord();
        if ($connection === null || ! $this->gscConnection->canCallGscApi($connection)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_missing_credentials'))
                ->danger()
                ->send();

            return;
        }

        try {
            $properties = $this->gscConnection->syncPropertiesMetadata($connection);
            $this->fillFormFromConnection($connection->fresh() ?? $connection);

            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_properties_synced'))
                ->body((string) count($properties))
                ->success()
                ->send();
        } catch (\Throwable) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_properties_sync_failed'))
                ->danger()
                ->send();
        }
    }

    public function autoMapAndSyncAll(): void
    {
        if ($this->isGscBulkSyncing) {
            return;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $this->isGscBulkSyncing = true;

        $result = $this->gscBulkSync->autoMapAndSyncAll((int) auth()->id(), $this->gscConnectionId, queueSync: false);
        $this->gscBulkSyncResult = $result;
        $this->isGscBulkSyncing = false;

        $connection = $this->resolveConnectionRecord();
        if ($connection !== null) {
            $this->fillFormFromConnection($connection->fresh() ?? $connection);
        }

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_bulk_sync_complete')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'] ?? '')
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    public function retryGscSyncForSite(int $siteId): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel() || ! SeoAccessControl::canAccessSite($siteId)) {
            return;
        }

        $result = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
        if ($this->gscBulkSyncResult !== null && is_array($this->gscBulkSyncResult['rows'] ?? null)) {
            foreach ($this->gscBulkSyncResult['rows'] as $index => $row) {
                if ((int) ($row['site_id'] ?? 0) !== $siteId) {
                    continue;
                }

                $this->gscBulkSyncResult['rows'][$index]['sync_status'] = $result['ok']
                    ? (($result['query_count'] ?? 0) === 0 ? 'empty_success' : 'synced')
                    : 'failed';
                $this->gscBulkSyncResult['rows'][$index]['error'] = $result['ok'] ? null : $result['message'];
            }
        }

        Notification::make()
            ->title($result['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    /** @deprecated Use refreshProperties() */
    public function syncProperties(): void
    {
        $this->refreshProperties();
    }

    public function disconnectConnection(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $this->gscConnection->disconnectById((int) auth()->id(), $this->gscConnectionId);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.gsc_disconnected'))
            ->success()
            ->send();

        $connection = $this->resolveConnectionRecord();
        if ($connection !== null) {
            $this->fillFormFromConnection($connection);
        }
    }

    public function syncCurrentSite(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $synced = $this->gscSync->syncSiteWithDetails($siteId, (int) auth()->id());
        Notification::make()
            ->title($synced['ok']
                ? __('seo-content-ai::filament.api_connections.gsc_sync_success')
                : __('seo-content-ai::filament.api_connections.gsc_sync_failed'))
            ->body($synced['message'])
            ->{$synced['ok'] ? 'success' : 'warning'}()
            ->send();
    }

    private function resolveConnectionRecord(): ?SeoGscMasterConnection
    {
        if ($this->gscConnectionId <= 0) {
            return null;
        }

        return $this->gscConnection->resolveByIdForUser((int) auth()->id(), $this->gscConnectionId);
    }

    private function hydratePropertiesIfNeeded(SeoGscMasterConnection $connection): void
    {
        if (! $this->gscConnection->canCallGscApi($connection)) {
            return;
        }

        if ($this->gscConnection->availableProperties($connection) !== []) {
            return;
        }

        try {
            $this->gscConnection->syncPropertiesMetadata($connection);
        } catch (\Throwable) {
            // Keep edit page usable even when Google API is temporarily unavailable.
        }
    }

    private function fillFormFromConnection(SeoGscMasterConnection $connection): void
    {
        $siteId = SeoAccessControl::globalSiteId();
        $propertyUrl = '';

        if ($siteId !== null) {
            $mapping = $connection->propertyMappings()->where('site_id', $siteId)->first();
            $propertyUrl = (string) ($mapping?->property_url ?? '');
        }

        $effectiveStatus = $this->gscConnection->resolveEffectiveStatus($connection);
        $propertyOptions = $this->gscConnection->propertyOptionsForForm($connection);
        $mappedProperty = '';
        if ($propertyUrl !== '' && isset($propertyOptions[$propertyUrl])) {
            $mappedProperty = $propertyUrl;
        }

        $this->form->fill([
            'provider' => ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
            'name' => (string) ($connection->name ?: 'Google Search Console'),
            'gsc_oauth_client_id' => (string) ($connection->oauth_client_id ?? ''),
            'gsc_oauth_client_secret' => '',
            'gsc_has_saved_config' => true,
            'gsc_has_oauth_app_credentials' => $this->gscConnection->hasOAuthAppCredentials($connection),
            'gsc_has_oauth_client_secret' => filled($connection->oauth_client_secret),
            'gsc_is_connected' => $effectiveStatus === 'connected',
            'gsc_show_token_details' => in_array($effectiveStatus, ['connected', 'token_expired'], true),
            'gsc_connection_status_label' => $this->gscConnection->statusForSite($siteId, $connection)['label'],
            'gsc_account_email' => (string) ($connection->account_email ?? ''),
            'gsc_token_expires_at' => $this->formatTokenExpiry($this->gscConnection->tokenExpiresAt($connection)),
            'gsc_available_properties' => array_keys($propertyOptions),
            'gsc_oauth_callback_url' => (string) config('services.google_search_console.redirect'),
            'gsc_property_url' => $mappedProperty,
        ]);
    }

    private function notifyOAuthFlash(): void
    {
        $success = session()->pull('gsc_oauth_success');
        if (is_string($success) && $success !== '') {
            Notification::make()
                ->title($success)
                ->success()
                ->send();
        }

        $error = session()->pull('gsc_oauth_error');
        if (is_string($error) && $error !== '') {
            Notification::make()
                ->title($error)
                ->danger()
                ->send();
        }
    }

    private function formatTokenExpiry(?string $expiresAt): string
    {
        if ($expiresAt === null || $expiresAt === '') {
            return '—';
        }

        try {
            return \Carbon\Carbon::parse($expiresAt)->toDateTimeString();
        } catch (\Throwable) {
            return $expiresAt;
        }
    }

    private function denyMutation(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
            ->danger()
            ->send();
    }
}
