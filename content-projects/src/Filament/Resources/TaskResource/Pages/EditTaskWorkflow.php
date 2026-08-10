<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\Concerns\InteractsWithTaskWorkflow;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;

class EditTaskWorkflow extends Page
{
    use InteractsWithRecord;
    use InteractsWithTaskWorkflow;

    protected static string $resource = TaskResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.task-resource.pages.task-workflow-builder';

    protected static ?string $title = 'Workflow Builder';

    public ?int $taskId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->taskId = (int) $this->record->getKey();

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getTitle(): string|Htmlable
    {
        /** @var SeoTask $task */
        $task = $this->getRecord();

        return 'Workflow: '.$task->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('seo-content-ai::filament.task.back_to_tasks'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(TaskResource::getUrl('index')),
            Actions\Action::make('edit_task')
                ->label('Workflow settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(fn (): string => TaskResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    protected function persistTaskFlow(string $taskName, array $flowData): bool
    {
        /** @var SeoTask $task */
        $task = $this->getRecord();

        if ($taskName === '') {
            Notification::make()
                ->title('Please enter workflow name')
                ->danger()
                ->send();

            return false;
        }

        $task->update([
            'name' => $taskName,
            'flow_data' => $flowData,
        ]);

        Notification::make()
            ->title('Workflow saved successfully')
            ->success()
            ->send();

        return true;
    }

    /**
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}
     */
    public function getFlowData(): array
    {
        /** @var SeoTask $task */
        $task = $this->getRecord();

        return $this->normalizeFlowPayload($task->flow_data);
    }

    public function getTaskName(): string
    {
        /** @var SeoTask $task */
        $task = $this->getRecord();

        return (string) $task->name;
    }
}
