<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

final class ApiConnectionFormSchema
{
    /**
     * @return list<Forms\Components\Component>
     */
    public static function components(string $operation = 'create', bool $lockProvider = false): array
    {
        return [
            Forms\Components\Select::make('provider')
                ->label(__('seo-content-ai::filament.ai_connection.provider'))
                ->options(ApiConnectionProviders::options())
                ->default(ApiConnectionProviders::GEMINI)
                ->disabled($lockProvider)
                ->dehydrated()
                ->live()
                ->required()
                ->native(false)
                ->afterStateUpdated(function (?string $state, ?string $old, Forms\Set $set, Get $get): void {
                    $set('metadata.base_url', null);
                    $set('metadata.base_url_override', null);
                    $set('metadata.override_base_url', false);
                    $newLabel = is_string($state) && $state !== '' ? ApiConnectionProviders::label($state) : '';
                    $oldLabel = is_string($old) && $old !== '' ? ApiConnectionProviders::label($old) : '';
                    $name = trim((string) $get('name'));
                    if ($name === '' || $name === $oldLabel) {
                        $set('name', $newLabel);
                    }
                    $currentCode = trim((string) $get('metadata.display_code'));
                    $oldBuiltin = is_string($old) ? (\Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode::builtin($old) ?? '') : '';
                    if ($currentCode === '' || $currentCode === $oldBuiltin) {
                        $set(
                            'metadata.display_code',
                            is_string($state)
                                ? (\Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode::builtin($state) ?? '')
                                : '',
                        );
                    }
                })
                ->helperText(fn (Get $get): ?HtmlString => self::providerHelper($get('provider'))),
            Forms\Components\TextInput::make('name')
                ->label(__('seo-content-ai::filament.ai_connection.name'))
                ->required()
                ->default(fn (): string => ApiConnectionProviders::label(ApiConnectionProviders::GEMINI))
                ->maxLength(255),
            Forms\Components\TextInput::make('metadata.display_code')
                ->label(__('seo-content-ai::filament.ai_connection.display_code'))
                ->helperText(__('seo-content-ai::filament.ai_connection.display_code_help'))
                ->maxLength(8)
                ->default(fn (): string => (string) (\Omnichannel\Addons\AiPrompt\Support\AiConnectionShortCode::builtin(ApiConnectionProviders::GEMINI) ?? ''))
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')))
                ->dehydrated(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')))
                ->extraInputAttributes([
                    'style' => 'text-transform: uppercase',
                    'autocomplete' => 'off',
                ]),
            Forms\Components\TextInput::make('api_key')
                ->label(__('seo-content-ai::filament.ai_connection.api_key'))
                ->password()
                ->revealable()
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')))
                ->required(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')) && $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->maxLength(65535)
                ->helperText(__('seo-content-ai::filament.ai_connection.helper_sync')),
            Forms\Components\Placeholder::make('transport_summary')
                ->label(__('seo-content-ai::filament.ai_connection.transport'))
                ->content(function (Get $get): HtmlString {
                    $provider = (string) $get('provider');
                    if (! self::isTemplateBacked($provider)) {
                        return new HtmlString('');
                    }
                    try {
                        $details = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                            ->technicalDetails((int) auth()->id(), $provider);
                    } catch (\Throwable) {
                        return new HtmlString(e(__('seo-content-ai::filament.ai_connection.transport_missing')));
                    }

                    return new HtmlString(
                        '<div class="text-sm">'
                        .e($details['provider_name']).' API · '
                        .e(__('seo-content-ai::filament.ai_connection.transport_auto'))
                        .'</div>'
                    );
                })
                ->visible(fn (Get $get): bool => self::isTemplateBacked((string) $get('provider'))),
            Forms\Components\Section::make(__('seo-content-ai::filament.ai_connection.technical_details'))
                ->collapsed()
                ->visible(fn (Get $get): bool => self::isTemplateBacked((string) $get('provider')))
                ->schema([
                    Forms\Components\Placeholder::make('tech_protocol')
                        ->label(__('seo-content-ai::filament.ai_connection.protocol'))
                        ->content(fn (Get $get): string => self::technicalField($get, 'protocol')),
                    Forms\Components\Placeholder::make('tech_base_url')
                        ->label(__('seo-content-ai::filament.ai_connection.base_url'))
                        ->content(fn (Get $get): string => self::technicalField($get, 'base_url')),
                    Forms\Components\Placeholder::make('tech_models')
                        ->label(__('seo-content-ai::filament.ai_connection.models_endpoint'))
                        ->content(fn (Get $get): string => self::technicalField($get, 'models')),
                    Forms\Components\Placeholder::make('tech_text')
                        ->label(__('seo-content-ai::filament.ai_connection.text_endpoint'))
                        ->content(fn (Get $get): string => self::technicalField($get, 'text')),
                    Forms\Components\Placeholder::make('tech_source')
                        ->label(__('seo-content-ai::filament.ai_connection.config_source'))
                        ->content(fn (Get $get): string => self::sourceLabel($get)),
                ]),
            Forms\Components\Section::make(__('seo-content-ai::filament.ai_connection.advanced'))
                ->collapsed()
                ->visible(fn (Get $get): bool => self::allowsOverride((string) $get('provider')))
                ->schema([
                    Forms\Components\Toggle::make('metadata.override_base_url')
                        ->label(__('seo-content-ai::filament.ai_connection.override_base_url'))
                        ->live()
                        ->dehydrated(),
                    Forms\Components\TextInput::make('metadata.base_url_override')
                        ->label(__('seo-content-ai::filament.ai_connection.base_url'))
                        ->helperText(__('seo-content-ai::filament.ai_connection.override_warning'))
                        ->autocomplete('off')
                        ->extraInputAttributes([
                            'autocomplete' => 'off',
                            'data-1p-ignore' => 'true',
                            'data-lpignore' => 'true',
                        ])
                        ->visible(fn (Get $get): bool => (bool) $get('metadata.override_base_url'))
                        ->maxLength(255),
                ]),
            Forms\Components\Select::make('status')
                ->label(__('seo-content-ai::filament.ai_connection.status'))
                ->options([
                    'active' => __('seo-content-ai::filament.ai_connection.active'),
                    'inactive' => __('seo-content-ai::filament.ai_connection.inactive'),
                ])
                ->default('active')
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')))
                ->native(false),
            Forms\Components\Section::make(__('seo-content-ai::filament.api_connections.gsc_heading'))
                ->description(__('seo-content-ai::filament.api_connections.gsc_hint'))
                ->visible(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE)
                ->schema([
                    Forms\Components\TextInput::make('gsc_oauth_client_id')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_oauth_client_id'))
                        ->required(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('gsc_oauth_client_secret')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_oauth_client_secret'))
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $operation === 'create'
                            && $get('provider') === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (): ?string => $operation === 'edit'
                            ? __('seo-content-ai::filament.api_connections.gsc_oauth_client_secret_edit_hint')
                            : null)
                        ->hint(fn (Get $get): ?string => $operation === 'edit' && (bool) ($get('gsc_has_oauth_client_secret') ?? false)
                            ? __('seo-content-ai::filament.api_connections.gsc_oauth_client_secret_saved')
                            : null)
                        ->maxLength(65535),
                    Forms\Components\TextInput::make('gsc_oauth_callback_url')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_oauth_callback_url'))
                        ->default(fn (): string => (string) config('services.google_search_console.redirect'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->suffixAction(
                            FormInputAction::make('copy_gsc_oauth_callback_url')
                                ->label(__('seo-content-ai::filament.api_connections.gsc_oauth_callback_copy'))
                                ->icon('heroicon-o-clipboard-document')
                                ->alpineClickHandler(function (): string {
                                    $url = Js::from((string) config('services.google_search_console.redirect'));
                                    $message = Js::from(__('seo-content-ai::filament.api_connections.gsc_oauth_callback_copied'));

                                    return "(async () => { await navigator.clipboard.writeText({$url}); \$tooltip({$message}, { timeout: 1500 }); })()";
                                })
                        )
                        ->visible(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                    Forms\Components\Placeholder::make('gsc_create_connect_hint')
                        ->content(__('seo-content-ai::filament.api_connections.gsc_create_connect_hint'))
                        ->visible(fn (Get $get): bool => $operation === 'create' && $get('provider') === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                    Forms\Components\Placeholder::make('gsc_connection_status')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_connection_status'))
                        ->content(fn (Get $get): string => (string) ($get('gsc_connection_status_label') ?: __('seo-content-ai::filament.api_connections.not_configured')))
                        ->visible(fn (Get $get): bool => $operation === 'edit' && (bool) $get('gsc_has_saved_config')),
                    Forms\Components\TextInput::make('gsc_account_email')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_account_email'))
                        ->email()
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('—')
                        ->visible(fn (Get $get): bool => $operation === 'edit' && (bool) $get('gsc_show_token_details')),
                    Forms\Components\Placeholder::make('gsc_token_expires_at')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_token_expires_at'))
                        ->content(fn (Get $get): string => (string) ($get('gsc_token_expires_at') ?: '—'))
                        ->visible(fn (Get $get): bool => $operation === 'edit' && (bool) $get('gsc_show_token_details')),
                    Forms\Components\Select::make('gsc_property_url')
                        ->label(__('seo-content-ai::filament.api_connections.gsc_property_url'))
                        ->options(fn (Get $get): array => self::gscPropertyOptions($get('gsc_available_properties')))
                        ->placeholder(__('seo-content-ai::filament.api_connections.gsc_property_select_placeholder'))
                        ->helperText(__('seo-content-ai::filament.api_connections.gsc_property_hint'))
                        ->native(false)
                        ->searchable()
                        ->disabled(fn (Get $get): bool => ! (bool) $get('gsc_show_token_details') || self::gscPropertyOptions($get('gsc_available_properties')) === [])
                        ->visible(fn (Get $get): bool => $operation === 'edit' && (bool) $get('gsc_show_token_details')),
                ])
                ->columns(2),
            Forms\Components\Section::make(__('seo-content-ai::filament.api_connections.dataforseo_heading'))
                ->description(__('seo-content-ai::filament.api_connections.dataforseo_hint'))
                ->visible(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::DATAFORSEO)
                ->schema([
                    Forms\Components\TextInput::make('dataforseo_login')
                        ->label(__('seo-content-ai::filament.api_connections.dataforseo_login'))
                        ->required(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::DATAFORSEO)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('dataforseo_password')
                        ->label(__('seo-content-ai::filament.api_connections.dataforseo_password'))
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => $get('provider') === ApiConnectionProviders::DATAFORSEO && $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(65535),
                    Forms\Components\TextInput::make('dataforseo_location')
                        ->label(__('seo-content-ai::filament.api_connections.dataforseo_location'))
                        ->placeholder('Vietnam')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('dataforseo_language')
                        ->label(__('seo-content-ai::filament.api_connections.dataforseo_language'))
                        ->placeholder('Vietnamese')
                        ->maxLength(255),
                ])
                ->columns(2),
            Forms\Components\Section::make(__('seo-content-ai::filament.api_connections.serp_heading'))
                ->description(__('seo-content-ai::filament.api_connections.serp_hint'))
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isSerpProvider($get('provider')))
                ->schema([
                    Forms\Components\TextInput::make('serp_api_key')
                        ->label(__('seo-content-ai::filament.api_connections.serp_api_key'))
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => ApiConnectionProviders::isSerpProvider($get('provider')) && $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (): ?string => $operation === 'edit'
                            ? __('seo-content-ai::filament.api_connections.serp_api_key_edit_hint')
                            : null)
                        ->maxLength(65535),
                    Forms\Components\Select::make('serp_status')
                        ->label(__('seo-content-ai::filament.api_connections.serp_status'))
                        ->options([
                            'active' => __('seo-content-ai::filament.api_connections.status_active'),
                            'inactive' => __('seo-content-ai::filament.api_connections.status_inactive'),
                        ])
                        ->default('inactive')
                        ->native(false),
                    Forms\Components\TextInput::make('serp_default_country')
                        ->label(__('seo-content-ai::filament.api_connections.serp_default_country'))
                        ->placeholder('vn')
                        ->maxLength(8),
                    Forms\Components\TextInput::make('serp_default_language')
                        ->label(__('seo-content-ai::filament.api_connections.serp_default_language'))
                        ->placeholder('vi')
                        ->maxLength(16),
                    Forms\Components\TextInput::make('serp_default_location')
                        ->label(__('seo-content-ai::filament.api_connections.serp_default_location'))
                        ->placeholder('Vietnam')
                        ->maxLength(255),
                    Forms\Components\Select::make('serp_default_device')
                        ->label(__('seo-content-ai::filament.api_connections.serp_default_device'))
                        ->options([
                            'desktop' => __('seo-content-ai::filament.performance_hub.device_desktop'),
                            'mobile' => __('seo-content-ai::filament.performance_hub.device_mobile'),
                            'tablet' => __('seo-content-ai::filament.performance_hub.device_tablet'),
                        ])
                        ->default('desktop')
                        ->native(false),
                    Forms\Components\TextInput::make('serp_result_depth')
                        ->label(__('seo-content-ai::filament.api_connections.serp_result_depth'))
                        ->numeric()
                        ->default(100)
                        ->minValue(1)
                        ->maxValue(100),
                ])
                ->columns(2),
            Forms\Components\Section::make(__('seo-content-ai::filament.api_connections.extended_heading'))
                ->description(__('seo-content-ai::filament.api_connections.extended_hint'))
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isExtendedProvider($get('provider')))
                ->schema([
                    Forms\Components\TextInput::make('extended_api_key')
                        ->label(fn (Get $get): string => $get('provider') === ApiConnectionProviders::SE_RANKING
                            ? __('seo-content-ai::filament.api_connections.seranking_api_token')
                            : __('seo-content-ai::filament.api_connections.extended_api_key'))
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => ApiConnectionProviders::isExtendedProvider($get('provider')) && $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (): ?string => $operation === 'edit'
                            ? __('seo-content-ai::filament.api_connections.extended_api_key_edit_hint')
                            : null)
                        ->maxLength(65535),
                    Forms\Components\Select::make('extended_status')
                        ->label(__('seo-content-ai::filament.api_connections.extended_status'))
                        ->options([
                            'active' => __('seo-content-ai::filament.api_connections.status_active'),
                            'inactive' => __('seo-content-ai::filament.api_connections.status_inactive'),
                        ])
                        ->default('inactive')
                        ->native(false),
                ])
                ->columns(2),
        ];
    }

    /**
     * @param  mixed  $properties
     * @return array<string, string>
     */
    private static function gscPropertyOptions(mixed $properties): array
    {
        if (! is_array($properties)) {
            $properties = [];
        }

        $options = [];
        foreach ($properties as $property) {
            if (! is_string($property)) {
                continue;
            }

            $property = trim($property);
            if ($property === '') {
                continue;
            }

            $options[$property] = $property;
        }

        return $options;
    }

    private static function isTemplateBacked(?string $provider): bool
    {
        if ($provider === null || $provider === '') {
            return false;
        }
        if (ApiConnectionProviders::isExternal($provider) || ApiConnectionProviders::isSeo($provider)) {
            return false;
        }

        return app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
            ->hasTemplate((int) auth()->id(), $provider);
    }

    private static function allowsOverride(?string $provider): bool
    {
        if ($provider === null || $provider === '' || ! self::isTemplateBacked($provider)) {
            return false;
        }
        try {
            $details = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->technicalDetails((int) auth()->id(), $provider);
        } catch (\Throwable) {
            return false;
        }

        return (bool) $details['allow_override'];
    }

    private static function technicalField(Get $get, string $key): string
    {
        try {
            $details = app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                ->technicalDetails((int) auth()->id(), (string) $get('provider'));
        } catch (\Throwable) {
            return '—';
        }

        return (string) ($details[$key] ?? '—');
    }

    private static function sourceLabel(Get $get): string
    {
        $source = self::technicalField($get, 'source');
        $schema = self::technicalField($get, 'schema_version');
        $label = $source === \Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::SOURCE_IMPORTED
            ? __('seo-content-ai::filament.ai_connection.source_imported')
            : __('seo-content-ai::filament.ai_connection.source_builtin');

        return $label.' · '.$schema;
    }

    private static function providerHelper(?string $provider): ?HtmlString
    {
        return match ($provider) {
            ApiConnectionProviders::GEMINI => new HtmlString(
                '<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer" '
                .'class="text-primary-600 hover:underline" style="color:#3b82f6;text-decoration:underline;font-weight:500;">'
                .e('👉 How to get Gemini API key from Google AI Studio')
                .'</a>'
            ),
            ApiConnectionProviders::CLAUDE => new HtmlString(
                '<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer" '
                .'class="text-primary-600 hover:underline" style="color:#3b82f6;text-decoration:underline;font-weight:500;">'
                .e('👉 How to get Claude API key from Anthropic Console')
                .'</a>'
            ),
            ApiConnectionProviders::DEEPSEEK => new HtmlString(
                '<a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener noreferrer" '
                .'class="text-primary-600 hover:underline" style="color:#3b82f6;text-decoration:underline;font-weight:500;">'
                .e('👉 How to get DeepSeek API key')
                .'</a>'
            ),
            ApiConnectionProviders::OPENROUTER => new HtmlString(
                '<a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer" '
                .'class="text-primary-600 hover:underline" style="color:#3b82f6;text-decoration:underline;font-weight:500;">'
                .e('👉 How to get OpenRouter API key')
                .'</a>'
            ),
            default => null,
        };
    }
}
