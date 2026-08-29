@php
    $monthOptions = $this->getPlanningMonthOptions();
    $projectTypeOptions = $this->getProjectTypeOptions();
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <x-seo-content-ai::list-table-loading-shell
        preset="filament-table"
        targets="planningMonth,projectType,updatedPlanningMonth,updatedProjectType"
    >
    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <label class="sr-only" for="planning-month">{{ __('seo-content-ai::filament.projects.planning_month') }}</label>
            <x-select
                id="planning-month"
                wire:model.live="planningMonth"
                size="inline"
                class="min-w-[8.5rem] text-sm"
            >
                @foreach ($monthOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-select>

            <label class="sr-only" for="project-type">{{ __('seo-content-ai::filament.projects.project_type') }}</label>
            <x-select
                id="project-type"
                wire:model.live="projectType"
                size="inline"
                class="min-w-[8.5rem] text-sm"
            >
                @foreach ($projectTypeOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-select>
        </div>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

    {{ $this->table }}

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </x-seo-content-ai::list-table-loading-shell>
</x-filament-panels::page>
