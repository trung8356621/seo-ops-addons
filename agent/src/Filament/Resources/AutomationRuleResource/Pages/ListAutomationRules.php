<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleClassification;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleVisibility;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

final class ListAutomationRules extends ListRecords
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationRuleResource::class;

    public bool $showSystem = false;

    public bool $showSamples = false;

    public bool $showDeprecated = false;

    public function mount(): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationRuleResource::getUrl('index'))) {
            return;
        }

        parent::mount();

        $hasAutoWp = AutomationRule::query()
            ->where('is_enabled', true)
            ->whereNotNull('published_version_id')
            ->whereIn('classification', [
                AutomationRuleClassification::Business->value,
                AutomationRuleClassification::Production->value,
            ])
            ->where(function (Builder $q): void {
                $q->where('visibility', AutomationRuleVisibility::User->value)
                    ->orWhereNull('visibility');
            })
            ->whereHas('actions', static function ($q): void {
                $q->where('action_code', AutomationActionCode::WordpressArticleSync->value)
                    ->where('is_enabled', true);
            })
            ->exists();

        if (! $hasAutoWp) {
            Notification::make()
                ->title(__('seo-content-ai::filament.automation.wp_auto_disabled_title'))
                ->body(__('seo-content-ai::filament.automation.wp_auto_disabled_body'))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleSystem')
                ->label(fn (): string => $this->showSystem ? 'Hide system' : 'Show system rules')
                ->color('gray')
                ->action(function (): void {
                    $this->showSystem = ! $this->showSystem;
                    $this->resetTable();
                }),
            Actions\Action::make('toggleSamples')
                ->label(fn (): string => $this->showSamples ? 'Hide samples' : 'Show samples')
                ->color('gray')
                ->action(function (): void {
                    $this->showSamples = ! $this->showSamples;
                    $this->resetTable();
                }),
            Actions\Action::make('toggleDeprecated')
                ->label(fn (): string => $this->showDeprecated ? 'Hide deprecated' : 'Show deprecated')
                ->color('gray')
                ->action(function (): void {
                    $this->showDeprecated = ! $this->showDeprecated;
                    $this->resetTable();
                }),
            Actions\CreateAction::make()
                ->visible(fn (): bool => AutomationRuleResource::canCreate()),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();
        if ($query === null) {
            return null;
        }

        $allowed = [AutomationRuleClassification::Business->value, AutomationRuleClassification::Production->value];
        if ($this->showSystem) {
            $allowed[] = AutomationRuleClassification::System->value;
            $allowed[] = AutomationRuleClassification::Infrastructure->value;
            $allowed[] = AutomationRuleClassification::Experimental->value;
        }
        if ($this->showSamples) {
            $allowed[] = AutomationRuleClassification::Sample->value;
        }
        if ($this->showDeprecated) {
            $allowed[] = AutomationRuleClassification::Deprecated->value;
        }

        $query->whereIn('classification', $allowed);

        if (! $this->showSystem && ! $this->showDeprecated) {
            $query->where(function (Builder $q): void {
                $q->where('visibility', AutomationRuleVisibility::User->value)
                    ->orWhereNull('visibility')
                    ->orWhere('visibility', '');
            });
        }

        return $query;
    }
}
