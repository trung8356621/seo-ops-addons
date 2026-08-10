<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoCreateRecord;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\SearchIntelligence\Services\DataForSeoConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\AiPrompt\Services\SeoExtendedProviderConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderRegistry;
use Filament\Notifications\Notification;

class CreateAiConnection extends SeoCreateRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-form';

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.api_connections.add_connection');
    }

    public function create(bool $another = false): void
    {
        $this->authorizeAccess();
        $this->callHook('beforeValidate');
        $data = $this->form->getState();
        $this->callHook('afterValidate');

        $provider = (string) ($data['provider'] ?? '');

        if ($provider === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE) {
            $this->createGscConnection($data);

            return;
        }

        if ($provider === ApiConnectionProviders::DATAFORSEO) {
            $this->createDataForSeoConnection($data);

            return;
        }

        if (ApiConnectionProviders::isSerpProvider($provider)) {
            $this->createSerpProviderConnection($provider, $data);

            return;
        }

        if (ApiConnectionProviders::isExtendedProvider($provider)) {
            $this->createExtendedProviderConnection($provider, $data);

            return;
        }

        if (! ApiConnectionProviders::isAi($provider)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.unsupported_provider'))
                ->danger()
                ->send();

            return;
        }

        $this->callHook('beforeCreate');
        $data = $this->mutateFormDataBeforeCreate($data);
        $this->record = $this->handleRecordCreation($data);
        $this->form->model($this->getRecord())->saveRelationships();
        $this->callHook('afterCreate');
        $this->afterCreate();

        Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/create-record.notifications.created.title'))
            ->send();

        if ($another) {
            $this->form->fill();
            $this->redirect(static::getResource()::getUrl('create'));

            return;
        }

        $this->redirect($this->getRedirectUrl());
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $provider = (string) ($data['provider'] ?? '');
        $data['user_id'] = auth()->id();
        $data['is_global'] = $data['is_global'] ?? false;
        $data['default_model'] = null;
        $data['connection_type'] = ApiConnectionProviders::connectionType($provider)->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createGscConnection(array $data): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $gsc = app(GoogleSearchConsoleConnectionService::class);
        $existing = $gsc->resolveForUser((int) auth()->id());
        if ($existing !== null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.gsc_already_exists'))
                ->warning()
                ->send();
            $this->redirect($this->gscEditUrl((int) $existing->id));

            return;
        }

        $connection = $gsc->createForUser((int) auth()->id(), [
            'name' => $data['name'] ?? 'Google Search Console',
            'oauth_client_id' => trim((string) ($data['gsc_oauth_client_id'] ?? '')),
            'oauth_client_secret' => trim((string) ($data['gsc_oauth_client_secret'] ?? '')),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.gsc_saved'))
            ->success()
            ->send();

        $this->redirect($this->gscEditUrl((int) $connection->id));
    }

    private function gscEditUrl(int $recordId): string
    {
        return AiConnectionResource::gscEditUrl($recordId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createDataForSeoConnection(array $data): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $service = app(DataForSeoConnectionService::class);
        if ($service->resolveForUser((int) auth()->id()) !== null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.dataforseo_already_exists'))
                ->warning()
                ->send();
            $this->redirect(EditDataForSeoApiConnection::getUrl(
                parameters: SeoConnectionContext::mergePanelRouteParameters(),
                panel: 'seo',
            ));

            return;
        }

        $service->saveForUser((int) auth()->id(), [
            'login' => $data['dataforseo_login'] ?? $data['name'] ?? '',
            'password' => $data['dataforseo_password'] ?? null,
            'default_location' => $data['dataforseo_location'] ?? null,
            'default_language' => $data['dataforseo_language'] ?? null,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.dataforseo_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSerpProviderConnection(string $provider, array $data): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $service = app(SeoSerpProviderConnectionService::class);
        if ($service->resolveForUser((int) auth()->id(), $provider)?->isConfigured() === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.serp_already_exists', [
                    'provider' => SerpProviderKeys::label($provider),
                ]))
                ->warning()
                ->send();
            $this->redirect(AiConnectionResource::getUrl('edit-serp', ['provider' => $provider]));

            return;
        }

        $service->saveForUser((int) auth()->id(), $provider, [
            'name' => $data['name'] ?? SerpProviderKeys::label($provider),
            'api_key' => $data['serp_api_key'] ?? null,
            'status' => $data['serp_status'] ?? 'inactive',
            'default_country' => $data['serp_default_country'] ?? null,
            'default_language' => $data['serp_default_language'] ?? null,
            'default_location' => $data['serp_default_location'] ?? null,
            'default_device' => $data['serp_default_device'] ?? 'desktop',
            'result_depth' => $data['serp_result_depth'] ?? 100,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.serp_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createExtendedProviderConnection(string $provider, array $data): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            $this->denyMutation();

            return;
        }

        $service = app(SeoExtendedProviderConnectionService::class);
        $registry = app(SeoProviderRegistry::class);
        if ($service->resolveForUser((int) auth()->id(), $provider)?->isConfigured() === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.api_connections.extended_already_exists', [
                    'provider' => $registry->label($provider),
                ]))
                ->warning()
                ->send();
            $this->redirect(AiConnectionResource::getUrl('edit-extended', ['provider' => $provider]));

            return;
        }

        $service->saveForUser((int) auth()->id(), $provider, [
            'name' => $data['name'] ?? $registry->label($provider),
            'api_key' => $data['extended_api_key'] ?? null,
            'status' => $data['extended_status'] ?? 'inactive',
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.api_connections.extended_saved'))
            ->success()
            ->send();

        $this->redirect(AiConnectionResource::getUrl('index'));
    }

    private function denyMutation(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
            ->danger()
            ->send();
    }
}
