<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionFormSchema;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Notifications\Notification;

class EditAiConnection extends SeoEditRecord
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-form';

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.api_connections.edit_ai');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(ApiConnectionFormSchema::components(operation: 'edit', lockProvider: true))
            ->model($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_models')
                ->label(__('seo-content-ai::filament.api_connections.sync_models'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => ApiConnectionProviders::isAi((string) $this->record->provider))
                ->action(function (): void {
                    $ok = app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);

                    if ($ok) {
                        Notification::make()
                            ->title('Models synced')
                            ->body('API model list has been updated in seo_ai_models.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sync failed')
                        ->body('Check API key and provider (Gemini / Claude).')
                        ->danger()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_model'] = null;

        return $data;
    }

    protected function afterSave(): void
    {
        if (! ApiConnectionProviders::isAi((string) $this->record->provider)) {
            return;
        }

        app(AiModelRouterService::class)->syncModelsForConnection((int) $this->record->id);
    }
}
