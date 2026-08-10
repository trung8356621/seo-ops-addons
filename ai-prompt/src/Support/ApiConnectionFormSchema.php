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
                ->helperText(fn (Get $get): ?HtmlString => self::providerHelper($get('provider'))),
            Forms\Components\TextInput::make('name')
                ->label(__('seo-content-ai::filament.ai_connection.name'))
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('api_key')
                ->label(__('seo-content-ai::filament.ai_connection.api_key'))
                ->password()
                ->revealable()
                ->visible(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')))
                ->required(fn (Get $get): bool => ApiConnectionProviders::isAi($get('provider')) && $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->maxLength(65535)
                ->helperText(__('seo-content-ai::filament.ai_connection.helper_sync')),
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
            default => null,
        };
    }
}
