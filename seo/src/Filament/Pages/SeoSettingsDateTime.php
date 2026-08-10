<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Services\SeoDateTimeSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class SeoSettingsDateTime extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/date-time';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Date & Time';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-date-time';

    /** @var array<string, mixed> */
    public array $dateTimeSettingsData = [];

    public function mount(SeoDateTimeSettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $this->dateTimeSettingsData = $settings->getSettings();
        $this->form->fill($this->dateTimeSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_datetime.section'))
                    ->description(__('seo-content-ai::filament.settings_datetime.section_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoDateTimeSettingsService::KEY_TIMEZONE)
                            ->label(__('seo-content-ai::filament.settings_datetime.timezone'))
                            ->helperText(__('seo-content-ai::filament.settings_datetime.timezone_hint'))
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
                            ->helperText(__('seo-content-ai::filament.settings_datetime.preset_hint'))
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
            ])
            ->statePath('dateTimeSettingsData');
    }

    public function save(SeoDateTimeSettingsService $settings): void
    {
        $data = $this->form->getState();
        $settings->save([
            SeoDateTimeSettingsService::KEY_TIMEZONE => (string) ($data[SeoDateTimeSettingsService::KEY_TIMEZONE] ?? ''),
            SeoDateTimeSettingsService::KEY_PRESET => (string) ($data[SeoDateTimeSettingsService::KEY_PRESET] ?? ''),
        ]);

        $this->dateTimeSettingsData = $settings->getSettings();
        $this->form->fill($this->dateTimeSettingsData);

        $this->dispatch('seo-datetime-settings-updated', config: SystemDateTime::frontendConfig());
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
