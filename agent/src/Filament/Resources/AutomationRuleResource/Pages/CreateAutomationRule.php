<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

final class CreateAutomationRule extends CreateRecord
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationRuleResource::class;

    public function mount(): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationRuleResource::getUrl('create'))) {
            return;
        }

        parent::mount();
    }

    protected function handleRecordCreation(array $data): AutomationRule
    {
        return AutomationRuleResource::createRuleFromFormData($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Automation rule created')
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return AutomationRuleResource::getUrl('index');
    }
}
