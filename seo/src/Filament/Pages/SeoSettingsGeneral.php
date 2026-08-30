<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;
use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Help\HelpUi;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class SeoSettingsGeneral extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/general';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'General';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-general';

    /** @var array<string, mixed> */
    public array $dateTimeSettingsData = [];

    /** @var array<string, mixed> */
    public array $teamChatSettingsData = [];

    public function mount(
        SeoDateTimeSettingsService $dateTimeSettings,
        SeoOverviewSettingsService $overviewSettings,
        SeoContentLanguageSettingsService $contentLanguageSettings,
    ): void {
        abort_unless(static::canAccess(), 403);

        $this->dateTimeSettingsData = array_merge(
            $dateTimeSettings->getSettings(),
            $contentLanguageSettings->getSettings(),
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
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'form',
            'teamChatForm',
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
                            ->hintAction(HelpUi::fieldHintAction('settings.general.datetime', null, 'timezone'))
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
                            ->hintAction(HelpUi::fieldHintAction('settings.general.datetime', null, 'preset'))
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
                            ->hintAction(HelpUi::fieldHintAction('settings.general.content_language', null, 'field'))
                            ->options(fn (): array => ContentLanguageRegistry::selectOptions())
                            ->required()
                            ->native(false)
                            ->in(ContentLanguageRegistry::codes()),
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
                            ->columnSpanFull()
                            ->hintAction(HelpUi::fieldHintAction('settings.general.team_chat', null, 'extensions')),
                        Forms\Components\TextInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB)
                            ->label(__('seo-content-ai::filament.settings_overview.team_chat_max_size_label'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->suffix('MB')
                            ->hintAction(HelpUi::fieldHintAction('settings.general.team_chat', null, 'max_size')),
                    ])
                    ->columns(2),
            ])
            ->statePath('teamChatSettingsData');
    }

    public function save(
        SeoDateTimeSettingsService $settings,
        SeoContentLanguageSettingsService $contentLanguageSettings,
    ): void {
        $data = $this->form->getState();
        $settings->save([
            SeoDateTimeSettingsService::KEY_TIMEZONE => (string) ($data[SeoDateTimeSettingsService::KEY_TIMEZONE] ?? ''),
            SeoDateTimeSettingsService::KEY_PRESET => (string) ($data[SeoDateTimeSettingsService::KEY_PRESET] ?? ''),
        ]);

        $contentLanguageSettings->save([
            SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE => (string) (
                $data[SeoContentLanguageSettingsService::KEY_DEFAULT_CONTENT_LANGUAGE] ?? ''
            ),
        ]);

        $this->dateTimeSettingsData = array_merge(
            $settings->getSettings(),
            $contentLanguageSettings->getSettings(),
        );
        $this->form->fill($this->dateTimeSettingsData);

        $this->dispatch('seo-datetime-settings-updated', config: SystemDateTime::frontendConfig());

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_general.default_content_language_saved'))
            ->success()
            ->send();
    }

    public function saveTeamChatSettings(SeoOverviewSettingsService $overviewSettings): void
    {
        $data = $this->teamChatForm->getState();

        $overviewSettings->saveTeamChatSettings([
            SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => (string) (
                $data[SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS] ?? ''
            ),
            SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB => $data[SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB]
                ?? $overviewSettings->getTeamChatMaxFileSizeMb(),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_overview.team_chat_saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public function getTitle(): string
    {
        return (string) __('seo-content-ai::filament.settings_general.page_title');
    }
}
