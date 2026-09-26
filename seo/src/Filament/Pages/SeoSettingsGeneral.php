<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\Seo\Services\SeoAnalyticsScopeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Social\Services\SocialSupportedDomainService;
use App\Help\HelpUi;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class SeoSettingsGeneral extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/general';

    protected static bool $shouldRegisterNavigation = false;


    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-general';

    /** @var array<string, mixed> */
    public array $dateTimeSettingsData = [];

    /** @var array<string, mixed> */
    public array $teamChatSettingsData = [];

    /** @var array<string, mixed> */
    public array $socialSettingsData = [];

    public function mount(
        SeoDateTimeSettingsService $dateTimeSettings,
        SeoOverviewSettingsService $overviewSettings,
        SeoContentLanguageSettingsService $contentLanguageSettings,
        ContentProjectWriterCapacitySettingsService $writerCapacitySettings,
        SeoAnalyticsScopeSettingsService $analyticsScopeSettings,
        SocialSupportedDomainService $socialSupportedDomains,
    ): void {
        abort_unless(static::canAccess(), 403);

        $this->dateTimeSettingsData = array_merge(
            $dateTimeSettings->getSettings(),
            $contentLanguageSettings->getSettings(),
            $writerCapacitySettings->getSettings(),
            $analyticsScopeSettings->getSettings(),
        );
        $this->form->fill($this->dateTimeSettingsData);

        $overview = $overviewSettings->getSettings();
        $this->teamChatSettingsData = [
            SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $overviewSettings->extensionsToTextarea(
                $overview[SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS],
            ),
            SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $overview[SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB],
        ];
        $this->teamChatForm->fill($this->teamChatSettingsData);

        $this->socialSettingsData = [
            SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS => $socialSupportedDomains->domainsToTextarea(
                $overview[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS],
            ),
        ];
        $this->socialSettingsForm->fill($this->socialSettingsData);
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'form',
            'teamChatForm',
            'socialSettingsForm',
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_datetime.section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.general.datetime')])
                    ->schema([
                        Forms\Components\Select::make(SeoDateTimeSettingsService::KEY_TIMEZONE)
                            ->label(__('seo-content-ai::filament.settings_datetime.timezone'))
                            ->options(fn (): array => SystemDateTime::timezoneSelectOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->rules([
                                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! is_string($value) || ! SeoDateTimeSettingsService::isValidTimezone($value)) {
                                        $fail(__('seo-content-ai::filament.settings_datetime.timezone_invalid'));
                                    }
                                },
                            ]),
                        Forms\Components\Radio::make(SeoDateTimeSettingsService::KEY_PRESET)
                            ->label(__('seo-content-ai::filament.settings_datetime.preset'))
                            ->options([
                                SeoDateTimeSettingsService::PRESET_VI => __('seo-content-ai::filament.settings_datetime.preset_vi'),
                                SeoDateTimeSettingsService::PRESET_EN => __('seo-content-ai::filament.settings_datetime.preset_en'),
                            ])
                            ->descriptions([
                                SeoDateTimeSettingsService::PRESET_VI => __('seo-content-ai::filament.settings_datetime.preset_vi_preview'),
                                SeoDateTimeSettingsService::PRESET_EN => __('seo-content-ai::filament.settings_datetime.preset_en_preview'),
                            ])
                            ->required()
                            ->live()
                            ->in([SeoDateTimeSettingsService::PRESET_VI, SeoDateTimeSettingsService::PRESET_EN]),
                        Forms\Components\Placeholder::make('datetime_preview')
                            ->label(__('seo-content-ai::filament.settings_datetime.preview_heading'))
                            ->content(function (Get $get): HtmlString {
                                $tz = (string) ($get(SeoDateTimeSettingsService::KEY_TIMEZONE) ?: SystemDateTime::timezone());
                                $preset = (string) ($get(SeoDateTimeSettingsService::KEY_PRESET) ?: SystemDateTime::preset());
                                $snap = SystemDateTime::previewSnapshot($tz, $preset);

                                return new HtmlString(
                                    '<dl class="space-y-2 text-sm">'
                                    .'<div><dt class="text-gray-500 dark:text-gray-400">'.e(__('seo-content-ai::filament.settings_datetime.preview_system')).'</dt>'
                                    .'<dd class="font-semibold text-gray-900 dark:text-gray-100">'.e($snap['system']).'</dd></div>'
                                    .'<div><dt class="text-gray-500 dark:text-gray-400">'.e(__('seo-content-ai::filament.settings_datetime.preview_timezone')).'</dt>'
                                    .'<dd class="font-medium">'.e($snap['timezone_line']).'</dd></div>'
                                    .'<div><dt class="text-gray-500 dark:text-gray-400">'.e(__('seo-content-ai::filament.settings_datetime.preview_utc')).'</dt>'
                                    .'<dd class="font-mono text-xs">'.e($snap['utc']).'</dd></div>'
                                    .'</dl>'
                                );
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_general.default_content_language'))
                    ->headerActions([HelpUi::fieldHintAction('settings.general.content_language')])
                    ->schema([
                        Forms\Components\Select::make(SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE)
                            ->label(__('seo-content-ai::filament.settings_general.default_content_language'))
                            ->options(fn (): array => ContentLanguageRegistry::selectOptions())
                            ->required()
                            ->native(false)
                            ->in(ContentLanguageRegistry::codes()),
                    ])
                    ->columns(1),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_general.writer_capacity_section'))
                    ->schema([
                        Forms\Components\TextInput::make(ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY)
                            ->label(__('seo-content-ai::filament.settings_general.writer_monthly_default_capacity'))
                            ->helperText(__('seo-content-ai::filament.settings_general.writer_monthly_default_capacity_hint'))
                            ->numeric()
                            ->required()
                            ->minValue(ContentProjectWriterCapacitySettingsService::MIN_CAPACITY)
                            ->maxValue(ContentProjectWriterCapacitySettingsService::MAX_CAPACITY)
                            ->integer(),
                    ])
                    ->columns(1),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_general.statistics_section'))
                    ->schema([
                        Forms\Components\Toggle::make(SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS)
                            ->label(__('seo-content-ai::filament.settings_general.exclude_pages_from_statistics'))
                            ->helperText(__('seo-content-ai::filament.settings_general.exclude_pages_from_statistics_hint'))
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(1),
            ])
            ->statePath('dateTimeSettingsData');
    }

    public function teamChatForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_overview.team_chat_section'))
                    ->headerActions([HelpUi::fieldHintAction('settings.general.team_chat')])
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)
                            ->label(__('seo-content-ai::filament.settings_overview.team_chat_extensions_label'))
                            ->rows(6)
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB)
                            ->label(__('seo-content-ai::filament.settings_overview.team_chat_max_size_label'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->suffix('MB'),
                    ])
                    ->columns(2),
            ])
            ->statePath('teamChatSettingsData');
    }

    public function socialSettingsForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_general.social_supports_section'))
                    ->description(__('seo-content-ai::filament.settings_general.social_supports_description'))
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS)
                            ->label(__('seo-content-ai::filament.settings_general.social_supports_label'))
                            ->helperText(__('seo-content-ai::filament.settings_general.social_supports_hint'))
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->statePath('socialSettingsData');
    }

    public function save(
        SeoDateTimeSettingsService $settings,
        SeoContentLanguageSettingsService $contentLanguageSettings,
        ContentProjectWriterCapacitySettingsService $writerCapacitySettings,
        SeoAnalyticsScopeSettingsService $analyticsScopeSettings,
        SeoOverviewSettingsService $overviewSettings,
        SocialSupportedDomainService $socialSupportedDomains,
    ): void {
        [$data, $teamChatData, $socialData] = $this->validatedAllFormStates();

        DB::transaction(function () use (
            $data,
            $teamChatData,
            $socialData,
            $settings,
            $contentLanguageSettings,
            $writerCapacitySettings,
            $analyticsScopeSettings,
            $overviewSettings,
        ): void {
            $settings->save([
                SeoDateTimeSettingsService::KEY_TIMEZONE => (string) ($data[SeoDateTimeSettingsService::KEY_TIMEZONE] ?? ''),
                SeoDateTimeSettingsService::KEY_PRESET => (string) ($data[SeoDateTimeSettingsService::KEY_PRESET] ?? ''),
            ]);

            $contentLanguageSettings->save([
                SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE => (string) (
                    $data[SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE] ?? ''
                ),
            ]);

            $writerCapacitySettings->save([
                ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => (int) (
                    $data[ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY]
                        ?? ContentProjectWriterCapacitySettingsService::DEFAULT_CAPACITY
                ),
            ]);

            $analyticsScopeSettings->save([
                SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS => (bool) (
                    $data[SeoAnalyticsScopeSettingsService::KEY_EXCLUDE_PAGES_FROM_STATISTICS] ?? true
                ),
            ]);

            $overviewSettings->saveTeamChatSettings([
                SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => (string) (
                    $teamChatData[SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] ?? ''
                ),
                SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $teamChatData[SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB]
                    ?? $overviewSettings->getTeamChatMaxFileSizeMb(),
            ]);

            $overviewSettings->saveSocialSupportedDomainsSettings([
                SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS => (string) (
                    $socialData[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS] ?? ''
                ),
            ]);
        });

        $this->dateTimeSettingsData = array_merge(
            $settings->getSettings(),
            $contentLanguageSettings->getSettings(),
            $writerCapacitySettings->getSettings(),
            $analyticsScopeSettings->getSettings(),
        );
        $this->form->fill($this->dateTimeSettingsData);

        $overview = $overviewSettings->getSettings();
        $this->teamChatSettingsData = [
            SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => $overviewSettings->extensionsToTextarea(
                $overview[SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS],
            ),
            SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $overview[SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB],
        ];
        $this->teamChatForm->fill($this->teamChatSettingsData);

        $this->socialSettingsData = [
            SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS => $socialSupportedDomains->domainsToTextarea(
                $overview[SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS],
            ),
        ];
        $this->socialSettingsForm->fill($this->socialSettingsData);

        $this->dispatch('seo-datetime-settings-updated', config: SystemDateTime::frontendConfig());

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_general.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * Validate every section form before any persist so failures never leave partial writes.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function validatedAllFormStates(): array
    {
        $errors = [];
        $states = [];

        foreach (['form', 'teamChatForm', 'socialSettingsForm'] as $formName) {
            try {
                $states[] = $this->{$formName}->getState();
            } catch (ValidationException $exception) {
                $errors = array_merge($errors, $exception->errors());
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$states[0], $states[1], $states[2]];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return class_exists(SeoAccessControl::class) && SeoAccessControl::canAccessManagerFeatures();
    }

    public function getTitle(): string
    {
        return (string) __('seo-content-ai::filament.settings_general.page_title');
    }
}
