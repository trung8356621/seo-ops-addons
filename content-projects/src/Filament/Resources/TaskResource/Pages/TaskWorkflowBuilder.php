<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages;

use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource\Pages\Concerns\InteractsWithTaskWorkflow;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;

class TaskWorkflowBuilder extends Page
{
    use InteractsWithTaskWorkflow;

    protected static string $resource = TaskResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.task-resource.pages.task-workflow-builder';

    protected static ?string $title = 'Create workflow';

    public ?int $taskId = null;

    public function mount(): void
    {
        abort_unless(static::getResource()::canCreate(), 403);
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

    protected function persistTaskFlow(string $taskName, array $flowData): bool
    {
        if ($taskName === '') {
            Notification::make()
                ->title('Please enter workflow name')
                ->danger()
                ->send();

            return false;
        }

        $task = SeoTask::query()->create([
            'user_id' => (int) auth()->id(),
            'name' => $taskName,
            'description' => null,
            'flow_data' => $flowData,
            'is_active' => true,
        ]);

        $this->taskId = (int) $task->id;

        Notification::make()
            ->title('Workflow saved successfully')
            ->success()
            ->send();

        $this->redirect(TaskResource::getUrl('builder', ['record' => $task]));

        return true;
    }

    /**
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}
     */
    public function getFlowData(): array
    {
        return ['nodes' => [], 'edges' => []];
    }

    public function getTaskName(): string
    {
        return 'New SEO workflow';
    }
}
