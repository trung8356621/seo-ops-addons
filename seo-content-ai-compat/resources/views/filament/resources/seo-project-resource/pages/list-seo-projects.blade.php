@php
    $monthOptions = $this->getPlanningMonthOptions();
    $projectTypeOptions = $this->getProjectTypeOptions();
    $domainChart = $this->getDomainWorkloadChart();
    $writerChart = $this->getWriterWorkloadChart();
    $queueStatus = $this->getCompactQueueStatus();
    $activeMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($this->planningMonth ?: null);
    $nearbyMonths = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::nearbyMonths($activeMonth, 2);
    $monthUrls = [];
    foreach ($nearbyMonths as $month) {
        $monthUrls[$month] = $this->planningMonthUrl($month, $this->projectType);
    }
    $prevUrl = $this->planningMonthUrl(
        \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shift($activeMonth, -1),
        $this->projectType,
    );
    $nextUrl = $this->planningMonthUrl(
        \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shift($activeMonth, 1),
        $this->projectType,
    );
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
    {{-- Active month navigator = page SoT (above charts) --}}
    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
            <x-seo-content-ai::content-project-list-month-nav
                :active-month="$activeMonth"
                :month-urls="$monthUrls"
                :prev-url="$prevUrl"
                :next-url="$nextUrl"
                :all-options="$monthOptions"
                :radius="2"
            />

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

        <div
            class="text-xs {{ ! empty($queueStatus['healthy']) ? 'text-gray-500 dark:text-gray-400' : 'text-warning-600 dark:text-warning-400' }}"
            @if (! empty($queueStatus['detail'])) title="{{ $queueStatus['detail'] }}" @endif
        >
            <span class="font-medium">{{ $queueStatus['label'] }}</span>
            @if (! empty($queueStatus['detail']) && ! empty($queueStatus['healthy']))
                <span class="opacity-80">· {{ $queueStatus['detail'] }}</span>
            @endif
        </div>
    </div>

    <x-seo-content-ai::content-project-month-charts
        :domain-chart="$domainChart"
        :writer-chart="$writerChart"
    />

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

    {{ $this->table }}

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </x-seo-content-ai::list-table-loading-shell>
</x-filament-panels::page>
