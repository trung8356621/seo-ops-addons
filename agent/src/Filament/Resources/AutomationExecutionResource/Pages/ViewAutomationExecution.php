<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource\Pages;

use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationExecutionResource;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphExecutionService;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewAutomationExecution extends ViewRecord
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationExecutionResource::class;

    public function mount(int|string $record): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationExecutionResource::getUrl('view', ['record' => $record]))) {
            return;
        }

        parent::mount($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label(__('Cancel'))
                ->color('danger')
                ->visible(fn (): bool => in_array((string) $this->getRecord()->status, ['pending', 'processing', 'scheduled'], true))
                ->action(function (): void {
                    app(AutomationGraphExecutionService::class)->cancelExecution((int) $this->getRecord()->id);
                    Notification::make()->title(__('Cancellation requested'))->success()->send();
                }),
            Actions\Action::make('retry_execution')
                ->label(__('Retry execution'))
                ->visible(fn (): bool => in_array((string) $this->getRecord()->status, ['failed', 'partial'], true))
                ->action(function (): void {
                    $execution = $this->getRecord();
                    if ($execution->rule?->isGraphMode()) {
                        app(AutomationGraphExecutionService::class)->retryExecution((int) $execution->id);
                    } else {
                        app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService::class)
                            ->retry((int) $execution->id);
                    }
                    Notification::make()->title(__('Retry queued'))->success()->send();
                }),
            Actions\Action::make('view_rule')
                ->label(__('View rule'))
                ->icon('heroicon-o-bolt')
                ->url(fn (): ?string => $this->getRecord()->rule
                    ? AutomationRuleResource::getUrl('view', ['record' => $this->getRecord()->rule])
                    : null)
                ->visible(fn (): bool => $this->getRecord()->rule !== null),
        ];
    }
}
