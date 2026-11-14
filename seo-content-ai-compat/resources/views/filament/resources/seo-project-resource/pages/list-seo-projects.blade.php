@php
    $projectTypeOptions = $this->getProjectTypeOptions();
    $domainChart = $this->getDomainWorkloadChart();
    $writerChart = $this->getWriterWorkloadChart();
    $monthlyPlanning = $this->getMonthlyPlanningMatrix();
    $queueStatus = $this->getCompactQueueStatus();
    $isAllMonths = $this->isAllPlanningMonths();
    // Strip center anchors to selected month, or wall-clock current when "Tất cả".
    $navAnchorMonth = $isAllMonths
        ? \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::current()
        : \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($this->planningMonth);
    $monthCatalog = [];
    foreach (\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::selectOptions(18, 12) as $option) {
        $month = (string) ($option['value'] ?? '');
        if ($month === '') {
            continue;
        }
        $monthCatalog[] = [
            'value' => $month,
            'short' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shortLabel($month),
            'label' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($month),
            'url' => $this->planningMonthUrl($month, $this->projectType),
            'year' => substr($month, 0, 4),
        ];
    }
    $allMonthsUrl = $this->planningMonthUrl('all', $this->projectType);
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
                :active-month="$navAnchorMonth"
                :is-all-months="$isAllMonths"
                :all-months-url="$allMonthsUrl"
                :month-catalog="$monthCatalog"
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
        :planning-matrix="$monthlyPlanning"
    />

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

    @if ($this->canSwitchListViewMode())
        @php
            $listViewMode = $this->effectiveListViewMode();
        @endphp
        <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
            <div
                class="inline-flex rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-white/5"
                role="group"
                aria-label="{{ __('seo-content-ai::filament.projects.list_view_mode_label') }}"
            >
                <button
                    type="button"
                    wire:click="$set('listViewMode', 'generate')"
                    @class([
                        'rounded-md px-2.5 py-1 font-medium transition',
                        'bg-white text-gray-950 shadow-sm dark:bg-white/10 dark:text-white' => $listViewMode === 'generate',
                        'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $listViewMode !== 'generate',
                    ])
                    aria-pressed="{{ $listViewMode === 'generate' ? 'true' : 'false' }}"
                >
                    {{ __('seo-content-ai::filament.projects.list_view_generate') }}
                </button>
                <button
                    type="button"
                    wire:click="$set('listViewMode', 'editorial')"
                    @class([
                        'rounded-md px-2.5 py-1 font-medium transition',
                        'bg-white text-gray-950 shadow-sm dark:bg-white/10 dark:text-white' => $listViewMode === 'editorial',
                        'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $listViewMode !== 'editorial',
                    ])
                    aria-pressed="{{ $listViewMode === 'editorial' ? 'true' : 'false' }}"
                >
                    {{ __('seo-content-ai::filament.projects.list_view_editorial') }}
                </button>
            </div>
        </div>
    @endif

    {{ $this->table }}

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </x-seo-content-ai::list-table-loading-shell>
</x-filament-panels::page>
