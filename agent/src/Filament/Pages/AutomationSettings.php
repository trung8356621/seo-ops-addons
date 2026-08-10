<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSettingsService;
use Omnichannel\Addons\Seo\Filament\Concerns\BelongsToAdminAutomationPanel;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AutomationSettings extends Page implements HasForms
{
    use BelongsToAdminAutomationPanel;
    use InteractsWithForms;
    use RedirectsSeoAutomationToAdmin;

    protected static ?string $slug = 'automation/settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = 'Automation Settings';

    protected static string $view = 'seo-content-ai::filament.pages.automation-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
    ): string {
        return parent::getUrl(
            $parameters,
            $isAbsolute,
            $panel ?? self::adminPanelId(),
            $tenant,
        );
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.automation.nav_settings');
    }

    public function mount(AutomationSettingsService $settings): void
    {
        if ($this->redirectSeoAutomationToAdmin(static::getUrl())) {
            return;
        }

        abort_unless(SeoAccessControl::canManageAutomationSettings(), 403);
        $this->form->fill($settings->getSettings());
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canManageAutomationSettings();
    }

    /**
     * @return array<string, Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form($this->makeForm()),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.automation.execution_log_retention'))
                    ->description(__('seo-content-ai::filament.automation.execution_log_retention_help'))
                    ->schema([
                        Forms\Components\Select::make(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION)
                            ->label(__('seo-content-ai::filament.automation.execution_log_retention'))
                            ->options(AutomationSettingsService::retentionOptions())
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make(AutomationSettingsService::KEY_CUSTOM_RETENTION_DAYS)
                            ->label(__('seo-content-ai::filament.automation.custom_retention_days'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->visible(fn (Forms\Get $get): bool => $get(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION) === AutomationSettingsService::RETENTION_CUSTOM)
                            ->required(fn (Forms\Get $get): bool => $get(AutomationSettingsService::KEY_EXECUTION_LOG_RETENTION) === AutomationSettingsService::RETENTION_CUSTOM),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(AutomationSettingsService $settings): void
    {
        SeoAccessControl::guardAutomationClearLogs();

        $settings->saveSettings($this->form->getState());

        Notification::make()
            ->title(__('seo-content-ai::filament.automation.settings_saved'))
            ->success()
            ->send();
    }
}
