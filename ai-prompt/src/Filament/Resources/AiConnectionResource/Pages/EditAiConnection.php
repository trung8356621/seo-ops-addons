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
            ->schema(array_merge(
                ApiConnectionFormSchema::components(operation: 'edit', lockProvider: true),
                $this->modelCapabilitySchema(),
            ))
            ->model($this->getRecord());
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function modelCapabilitySchema(): array
    {
        return [
            \Filament\Forms\Components\Section::make(__('seo-content-ai::filament.ai_connection.models_heading'))
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('model_capabilities')
                        ->label('')
                        ->content(function (): \Illuminate\Support\HtmlString {
                            $record = $this->getRecord();
                            if (! $record instanceof \App\Models\ApiConnection) {
                                return new \Illuminate\Support\HtmlString('');
                            }
                            $models = \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::query()
                                ->where('api_connection_id', $record->id)
                                ->orderByDesc('priority')
                                ->get();
                            if ($models->isEmpty()) {
                                return new \Illuminate\Support\HtmlString(
                                    '<p class="text-sm text-gray-500">'.e(__('seo-content-ai::filament.ai_connection.models_empty')).'</p>'
                                );
                            }
                            $html = '<ul class="space-y-1 text-sm">';
                            $labels = new \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter();
                            foreach ($models as $model) {
                                if ((string) $model->status !== \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::STATUS_ACTIVE) {
                                    continue;
                                }
                                $html .= '<li><span class="font-medium">'.e($labels->normal((string) $model->raw_model_name, (string) ($model->display_name ?: $model->raw_model_name))).'</span></li>';
                            }
                            $html .= '</ul>';

                            return new \Illuminate\Support\HtmlString($html);
                        }),
                ]),
        ];
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
        $provider = (string) ($data['provider'] ?? $this->record->provider ?? '');
        if (ApiConnectionProviders::isAi($provider)) {
            $data['metadata'] = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->sanitizeSubmittedMetadata((int) auth()->id(), $provider, is_array($data['metadata'] ?? null) ? $data['metadata'] : []);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (! empty($meta['base_url_override']) && ! empty($meta['override_base_url'])) {
            return $data;
        }
        $legacy = rtrim(trim((string) ($meta['base_url'] ?? '')), '/');
        if ($legacy !== '') {
            try {
                $resolved = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                    ->resolveForProvider((int) auth()->id(), (string) ($data['provider'] ?? ''), []);
                if (strcasecmp($legacy, $resolved->template->baseUrl) !== 0 && $resolved->template->allowBaseUrlOverride) {
                    $meta['override_base_url'] = true;
                    $meta['base_url_override'] = $legacy;
                    $data['metadata'] = $meta;
                }
            } catch (\Throwable) {
            }
        }

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
