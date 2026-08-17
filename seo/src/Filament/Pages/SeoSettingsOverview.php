<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsOverview extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/overview';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Overview';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-overview';

    /** @var array<string, mixed> */
    public array $teamChatSettingsData = [];

    public function mount(SeoOverviewSettingsService $overviewSettings): void
    {
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
            'teamChatForm',
        ];
    }

    public function teamChatForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_overview.team_chat_section'))
                    ->description(__('seo-content-ai::filament.settings_overview.team_chat_section_description'))
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)
                            ->label(__('seo-content-ai::filament.settings_overview.team_chat_extensions_label'))
                            ->rows(6)
                            ->required()
                            ->columnSpanFull()
                            ->helperText(__('seo-content-ai::filament.settings_overview.team_chat_extensions_hint')),
                        Forms\Components\TextInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_MAX_FILE_SIZE_MB)
                            ->label(__('seo-content-ai::filament.settings_overview.team_chat_max_size_label'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required()
                            ->suffix('MB')
                            ->helperText(__('seo-content-ai::filament.settings_overview.team_chat_max_size_hint')),
                    ])
                    ->columns(2),
            ])
            ->statePath('teamChatSettingsData');
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
}
