<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\ExecutionCleanupService;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListAutomationExecutions extends ListRecords
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationExecutionResource::class;

    public function mount(): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationExecutionResource::getUrl('index'))) {
            return;
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label(__('seo-content-ai::filament.automation.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->resetTable()),
            Actions\ActionGroup::make([
                $this->clearLogsAction('clearCompleted', __('seo-content-ai::filament.automation.clear_completed'), 'clearCompleted'),
                $this->clearLogsAction('clearFailed', __('seo-content-ai::filament.automation.clear_failed'), 'clearFailed'),
                $this->clearLogsAction('clearPartial', __('seo-content-ai::filament.automation.clear_partial'), 'clearPartial'),
                $this->clearLogsAction('clearAll', __('seo-content-ai::filament.automation.clear_all'), 'clearAll')
                    ->color('danger'),
            ])
                ->label(__('seo-content-ai::filament.automation.clear_logs'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->button()
                ->visible(fn (): bool => SeoAccessControl::canClearAutomationLogs()),
        ];
    }

    private function clearLogsAction(string $name, string $label, string $method): Actions\Action
    {
        return Actions\Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.automation.clear_execution_logs_title'))
            ->modalDescription(__('seo-content-ai::filament.automation.clear_execution_logs_body'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.automation.clear_delete'))
            ->action(function () use ($method): void {
                SeoAccessControl::guardAutomationClearLogs();

                /** @var ExecutionCleanupService $cleanup */
                $cleanup = app(ExecutionCleanupService::class);
                $cleanup->{$method}();

                Notification::make()
                    ->title(__('seo-content-ai::filament.automation.clear_logs_success'))
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }
}
