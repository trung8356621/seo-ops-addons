<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\AiPrompt\Services\AiModelCatalogFreshnessService;
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

    private ?bool $pendingManualFreeOnly = null;

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
                    $result = app(AiModelCatalogFreshnessService::class)->requestRefresh(
                        $this->record,
                        (int) auth()->id(),
                        forced: true,
                        blocking: true,
                        respectForcedDebounce: false,
                    );
                    $ok = (bool) ($result['ok'] ?? false)
                        || (($result['reason'] ?? '') === 'already_fresh');
                    $autoMapped = 0;
                    if ($ok) {
                        try {
                            app(\Omnichannel\Addons\AiPrompt\Services\AiModelPrimaryTypeClassifier::class)
                                ->classifyForUser((int) auth()->id());
                        } catch (\Throwable) {
                        }
                        try {
                            $mapResult = app(\Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper::class)
                                ->mapForUser((int) auth()->id());
                            $autoMapped = $mapResult->enabled;
                        } catch (\Throwable) {
                        }
                    }

                    if ($ok) {
                        Notification::make()
                            ->title(__('Models synced'))
                            ->body(__('API model list updated. Recommended auto-map +').$autoMapped.'.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Sync failed'))
                        ->body(__('Check API key and provider. Last-known-good catalog was preserved.'))
                        ->danger()
                        ->send();
                }),
            Actions\Action::make('test_connection')
                ->label(__('seo-content-ai::filament.api_connections.test_connection'))
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn (): bool => ApiConnectionProviders::isAi((string) $this->record->provider))
                ->action(function (): void {
                    $stages = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester::class)
                        ->test($this->record);
                    $failed = collect($stages)->first(fn (array $stage): bool => ! ($stage['ok'] ?? false));
                    $lines = [];
                    foreach ($stages as $stage) {
                        $mark = ($stage['ok'] ?? false) ? 'OK' : 'FAIL';
                        $lines[] = $mark.': '.($stage['label'] ?? '').' — '.($stage['detail'] ?? '');
                    }
                    $body = implode("\n", $lines);
                    if (is_array($failed)) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.api_connections.test_failed'))
                            ->body($body)
                            ->danger()
                            ->send();

                        return;
                    }
                    Notification::make()
                        ->title(__('seo-content-ai::filament.api_connections.test_success'))
                        ->body($body."\n".__('seo-content-ai::filament.ai_connection.test_not_generation'))
                        ->success()
                        ->send();
                }),
            Actions\Action::make('test_generation')
                ->label(__('seo-content-ai::filament.ai_connection.test_generation'))
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn (): bool => ApiConnectionProviders::isAi((string) $this->record->provider))
                ->form([
                    \Filament\Forms\Components\TextInput::make('model')
                        ->label(__('seo-content-ai::filament.ai_connection.test_generation_model'))
                        ->required()
                        ->default(fn (): string => (string) (\Omnichannel\Addons\AiPrompt\Models\SeoAiModel::query()
                            ->where('api_connection_id', (int) $this->record->id)
                            ->where('status', \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::STATUS_ACTIVE)
                            ->orderByDesc('priority')
                            ->value('raw_model_name') ?? '')),
                    \Filament\Forms\Components\Textarea::make('prompt')
                        ->label(__('seo-content-ai::filament.ai_connection.test_generation_prompt'))
                        ->rows(2)
                        ->default('Reply with exactly: ok'),
                ])
                ->modalHeading(__('seo-content-ai::filament.ai_connection.test_generation'))
                ->modalDescription(__('seo-content-ai::filament.ai_connection.test_generation_help'))
                ->action(function (array $data): void {
                    $result = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester::class)
                        ->testGeneration(
                            $this->record,
                            trim((string) ($data['model'] ?? '')),
                            trim((string) ($data['prompt'] ?? 'Reply with exactly: ok')),
                        );
                    $body = 'HTTP '.($result['http_status'] ?? '—')."\n".($result['detail'] ?? '');
                    if (! ($result['ok'] ?? false)) {
                        Notification::make()
                            ->title(__('seo-content-ai::filament.ai_connection.test_generation_failed'))
                            ->body($body)
                            ->danger()
                            ->send();

                        return;
                    }
                    Notification::make()
                        ->title(__('seo-content-ai::filament.ai_connection.test_generation_ok'))
                        ->body($body)
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $schema = \Illuminate\Support\Facades\Schema::connection(
            (string) ((new \App\Models\ApiConnection)->getConnectionName() ?? config('database.core_connection', 'mysql')),
        );
        if ($schema->hasColumn('api_connections', 'default_model')) {
            $data['default_model'] = $data['default_model'] ?? null;
        } else {
            unset($data['default_model']);
        }
        $provider = (string) ($data['provider'] ?? $this->record->provider ?? '');
        // Provider identity is immutable on edit (especially custom OpenAI-compatible keys).
        $data['provider'] = (string) $this->record->provider;
        $provider = (string) $this->record->provider;

        if (ApiConnectionProviders::isCustomOpenAiProvider($provider) && is_array($data['custom_provider'] ?? null)) {
            $custom = $data['custom_provider'];
            $custom['provider_key'] = $provider;
            $factory = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderCustomFormFactory::class);
            $store = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateStore::class);
            $template = $factory->buildFromForm($custom);
            if ($template->providerKey !== $provider) {
                throw \Omnichannel\Addons\AiPrompt\Exceptions\AiProviderTemplateException::rejected(
                    'Provider key cannot be changed after the connection is created.',
                );
            }
            $store->persist((int) auth()->id(), $template, builtin: false);
            if ($this->record instanceof \App\Models\ApiConnection) {
                $store->applyToConnection((int) auth()->id(), $this->record, $template);
                if (! (bool) ($custom['model_discovery'] ?? true)) {
                    $this->seedManualModels($this->record, (string) ($custom['manual_models'] ?? ''));
                }
            }
            $data['name'] = trim((string) ($custom['provider_name'] ?? $data['name'] ?? $template->providerName));
            unset($data['custom_provider']);
        }

        if (ApiConnectionProviders::isAi($provider)) {
            $data['metadata'] = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->sanitizeSubmittedMetadata((int) auth()->id(), $provider, is_array($data['metadata'] ?? null) ? $data['metadata'] : []);
            // paid_locked form toggle = manual_free_only intent; never write boolean raw (budget_limited may coexist).
            $this->pendingManualFreeOnly = array_key_exists('paid_locked', $data)
                ? (bool) $data['paid_locked']
                : null;
            unset($data['paid_locked']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $provider = (string) ($data['provider'] ?? '');
        if (ApiConnectionProviders::isCustomOpenAiProvider($provider)) {
            try {
                $resolved = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                    ->resolveForProvider((int) auth()->id(), $provider, []);
                $data['custom_provider'] = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderCustomFormFactory::class)
                    ->formDefaultsFromTemplate($resolved->effectiveTemplate());
            } catch (\Throwable) {
            }
        }

        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (! empty($meta['base_url_override']) && ! empty($meta['override_base_url'])) {
            return $data;
        }
        $legacy = rtrim(trim((string) ($meta['base_url'] ?? '')), '/');
        if ($legacy !== '') {
            try {
                $resolved = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                    ->resolveForProvider((int) auth()->id(), $provider, []);
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

        if ($this->pendingManualFreeOnly !== null) {
            app(\Omnichannel\Addons\AiPrompt\Services\SetAiConnectionFreeOnly::class)
                ->handle($this->record, $this->pendingManualFreeOnly);
            $this->pendingManualFreeOnly = null;
            $this->record->refresh();
        }

        // New credentials invalidate prior connection_locked / budget health locks (not manual_free_only).
        if (\Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential::isUsable($this->record->api_key)) {
            app(\Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService::class)
                ->unlockConnectionForApiConnection((int) $this->record->id);
        }

        app(AiModelCatalogFreshnessService::class)->requestRefresh(
            $this->record,
            (int) auth()->id(),
            forced: true,
            blocking: true,
            respectForcedDebounce: false,
        );
        app(\Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService::class)->forgetCache();
        try {
            app(\Omnichannel\Addons\AiPrompt\Services\AiModelPrimaryTypeClassifier::class)
                ->classifyForUser((int) auth()->id());
        } catch (\Throwable) {
        }
        try {
            app(\Omnichannel\Addons\AiPrompt\Services\AiRecommendedModelMapper::class)
                ->mapForUser((int) auth()->id());
        } catch (\Throwable) {
        }
    }

    private function seedManualModels(\App\Models\ApiConnection $connection, string $raw): void
    {
        $lines = preg_split('/[\r\n,]+/', $raw) ?: [];
        foreach ($lines as $line) {
            $modelId = trim((string) $line);
            if ($modelId === '') {
                continue;
            }
            \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => (int) $connection->id,
                    'raw_model_name' => $modelId,
                ],
                [
                    'display_name' => $modelId,
                    'category' => \Omnichannel\Addons\Seo\Support\AiModelCategory::GEMINI_FLASH,
                    'priority' => 100,
                    'status' => \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => [
                        'source' => (string) $connection->provider,
                        'language_suitability' => 'unknown',
                        'manual' => true,
                    ],
                ],
            );
        }
    }
}
