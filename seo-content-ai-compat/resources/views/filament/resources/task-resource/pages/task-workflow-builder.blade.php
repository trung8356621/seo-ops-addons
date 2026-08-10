<x-filament-panels::page>
    @php
        $flow = $this->getFlowData();
    @endphp

    <script>
        window.__SEO_PROMPTS__ = @json($this->getPromptsForBuilder());
        window.__SEO_WORKFLOW_ROLES__ = @json(app(\Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleRegistry::class)->builderOptions());
    </script>

    <div
        x-data
        x-on:save-task-flow.window="$wire.saveFlow($event.detail)"
        class="seo-task-builder-page h-full w-full overflow-hidden"
    >
        <script type="application/json" id="seo-task-initial-flow">@json($flow)</script>

        <div
            wire:ignore
            id="seo-task-workflow-builder-root"
            data-task-id="{{ $this->taskId }}"
            data-task-name="{{ $this->getTaskName() }}"
            data-back-url="{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\TaskResource::getUrl('index') }}"
            data-back-label="{{ __('seo-content-ai::filament.task.back_to_tasks') }}"
            class="w-full h-full"
        ></div>
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('addons/content-projects/resources/js/task-builder.jsx')
    @endpush
</x-filament-panels::page>
