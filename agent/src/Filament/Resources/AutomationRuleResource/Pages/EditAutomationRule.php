<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource\Pages;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationWorkflowMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Seo\Filament\Concerns\RedirectsSeoAutomationToAdmin;
use Omnichannel\Addons\Agent\Filament\Pages\AutomationWorkflowBuilder;
use Omnichannel\Addons\Agent\Filament\Resources\AutomationRuleResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Filament\Actions;
use Filament\Notifications\Notification;

final class EditAutomationRule extends SeoEditRecord
{
    use RedirectsSeoAutomationToAdmin;

    protected static string $resource = AutomationRuleResource::class;

    public function mount(int|string $record): void
    {
        if ($this->redirectSeoAutomationToAdmin(AutomationRuleResource::getUrl('edit', ['record' => $record]))) {
            return;
        }

        parent::mount($record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = AutomationRuleResource::mutateFormDataBeforeFill($data);
        $data['actions_data'] = AutomationRuleResource::fillActionsRepeaterFromRecord($data);
        $data['graph_nodes'] = AutomationRuleResource::fillGraphNodesRepeater($data);
        $data['graph_edges'] = AutomationRuleResource::fillGraphEdgesRepeater($data);

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): AutomationRule
    {
        if (! $record instanceof AutomationRule) {
            throw new \InvalidArgumentException('Expected AutomationRule.');
        }

        return AutomationRuleResource::updateRuleFromFormData($record, $data);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Automation rule updated')
            ->success();
    }

    protected function getHeaderActions(): array
    {
        /** @var AutomationRule $record */
        $record = $this->record;

        $actions = [
            Actions\ViewAction::make(),
        ];

        // Linear = fixed pipeline — no Visual Builder.
        if ((string) ($record->workflow_mode ?? 'linear') === AutomationWorkflowMode::Graph->value) {
            array_unshift($actions, Actions\Action::make('visualBuilder')
                ->label('Open Workflow Builder')
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => AutomationWorkflowBuilder::getUrl(['rule' => $record->getKey()])));
        }

        return $actions;
    }
}
