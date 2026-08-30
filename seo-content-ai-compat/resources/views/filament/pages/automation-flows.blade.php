<x-filament-panels::page>
    @vite([
        'addons/content-projects/resources/js/automation-workflow-viewer.jsx',
        'addons/content-projects/resources/css/automation-workflow-viewer.css',
    ])

    <div
        class="space-y-4"
        x-data
        x-on:automation-flows:open-components.window="$wire.setViewMode('components')"
    >
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('seo-content-ai::filament.automation.flows.intro_v2') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500">
                <span>{{ __('seo-content-ai::filament.automation.flows.summary_workflows', ['count' => $summary['workflows'] ?? 0]) }}</span>
                <span>·</span>
                <span>{{ __('seo-content-ai::filament.automation.flows.summary_components', ['count' => $summary['components'] ?? 0]) }}</span>
                <span>·</span>
                <span>{{ __('seo-content-ai::filament.automation.flows.summary_mapped', ['count' => $summary['mapped'] ?? 0]) }}</span>
                <span>·</span>
                <span>{{ __('seo-content-ai::filament.automation.flows.summary_unmapped', ['count' => $summary['unmapped'] ?? 0]) }}</span>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ([
                    'workflows' => __('seo-content-ai::filament.automation.flows.tab_workflows'),
                    'components' => __('seo-content-ai::filament.automation.flows.tab_components'),
                    'unmapped' => __('seo-content-ai::filament.automation.flows.tab_unmapped'),
                ] as $mode => $label)
                    <button
                        type="button"
                        wire:click="setViewMode(@js($mode))"
                        wire:loading.attr="disabled"
                        wire:target="setViewMode"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                            'bg-primary-500 text-white' => $viewMode === $mode,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200' => $viewMode !== $mode,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($viewMode !== 'unmapped')
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.automation.flows.filter_category') }}</label>
                        <x-select wire:model.live="category" class="w-full">
                            <option value="">{{ __('seo-content-ai::filament.automation.flows.filter_all') }}</option>
                            @foreach ($categoryOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    @if ($viewMode === 'workflows')
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.automation.flows.filter_mapping') }}</label>
                            <x-select wire:model.live="mapping" class="w-full">
                                <option value="">{{ __('seo-content-ai::filament.automation.flows.filter_all') }}</option>
                                <option value="complete">{{ __('seo-content-ai::filament.automation.flows.mapping.mapped') }}</option>
                                <option value="partial">{{ __('seo-content-ai::filament.automation.flows.mapping.partial') }}</option>
                            </x-select>
                        </div>
                    @else
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.automation.flows.filter_event') }}</label>
                            <x-select wire:model.live="eventName" class="w-full">
                                <option value="">{{ __('seo-content-ai::filament.automation.flows.filter_all') }}</option>
                                @foreach ($eventOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.automation.flows.filter_health') }}</label>
                        <x-select wire:model.live="health" class="w-full">
                            <option value="">{{ __('seo-content-ai::filament.automation.flows.filter_all') }}</option>
                            <option value="never">{{ __('seo-content-ai::filament.automation.flows.status.never') }}</option>
                            <option value="has_runs">{{ __('seo-content-ai::filament.automation.flows.filter_has_runs') }}</option>
                            <option value="failed">{{ __('seo-content-ai::filament.automation.flows.status.failed') }}</option>
                            <option value="processing">{{ __('seo-content-ai::filament.automation.flows.status.processing') }}</option>
                        </x-select>
                    </div>
                </div>
            @endif
        </div>

        @if ($viewMode === 'workflows')
            @php $filtered = $this->filteredWorkflows(); @endphp
            <div class="flex flex-col gap-4 lg:flex-row lg:items-stretch">
                <div class="awv-list-col space-y-3 shrink-0">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.automation.flows.search_workflows') }}</label>
                        <form wire:submit="applySearch" class="w-full">
                            <input
                                type="search"
                                wire:model="searchInput"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
                                placeholder="{{ __('seo-content-ai::filament.automation.flows.search_placeholder') }}"
                                autocomplete="off"
                            />
                        </form>
                    </div>

                    {{-- Mobile: dropdown instead of long list --}}
                    <div class="lg:hidden">
                        <x-select wire:model.live="workflowId" class="w-full">
                            <option value="">{{ __('seo-content-ai::filament.automation.flows.select_workflow_prompt') }}</option>
                            @foreach ($filtered as $workflow)
                                <option value="{{ $workflow['id'] }}">{{ $workflow['name'] ?? $workflow['id'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div class="hidden space-y-2 lg:block">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.automation.flows.workflows_heading', ['count' => count($filtered)]) }}
                        </h2>
                        @forelse ($filtered as $workflow)
                            <button
                                type="button"
                                wire:click="selectWorkflow(@js($workflow['id']))"
                                wire:loading.attr="disabled"
                                wire:target="selectWorkflow"
                                @class([
                                    'w-full rounded-xl border p-3 text-left transition',
                                    'border-primary-500 bg-primary-50 dark:bg-primary-500/10' => $workflowId === ($workflow['id'] ?? null),
                                    'border-gray-200 bg-white hover:border-gray-300 dark:border-white/10 dark:bg-gray-900' => $workflowId !== ($workflow['id'] ?? null),
                                ])
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ $workflow['name'] ?? '' }}
                                        </div>
                                        <div class="mt-0.5 text-[11px] text-gray-500">
                                            {{ $workflow['category_label'] ?? '' }}
                                        </div>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">
                                        {{ $workflow['status_label'] ?? __('seo-content-ai::filament.automation.flows.status.never') }}
                                    </span>
                                </div>
                                <div class="mt-2 text-[11px] text-gray-500">
                                    {{ __('seo-content-ai::filament.automation.flows.steps_count', ['count' => (int) ($workflow['step_count'] ?? 0)]) }}
                                    · {{ $workflow['mapping_label'] ?? '' }}
                                </div>
                            </button>
                        @empty
                            <div class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-white/10">
                                {{ __('seo-content-ai::filament.automation.flows.empty_workflows') }}
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    @if (filled($workflowId) && is_array($viewerWorkflow) && ($viewerWorkflow['id'] ?? null))
                        <div
                            wire:key="awv-{{ $workflowId }}"
                            wire:ignore.self
                            data-automation-workflow-viewer
                            data-props='@json($this->viewerProps(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
                            class="min-h-[calc(100vh-16rem)] rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
                        >
                            <div class="flex min-h-[calc(100vh-16rem)] items-center justify-center p-8 text-sm text-gray-500" data-awv-fallback>
                                {{ __('seo-content-ai::filament.automation.flows.viewer_loading') }}
                            </div>
                        </div>
                    @elseif (filled($workflowId))
                        <div class="flex min-h-[360px] items-center justify-center rounded-xl border border-dashed border-rose-300 bg-rose-50 p-8 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                            {{ __('seo-content-ai::filament.automation.flows.viewer_load_failed') }}
                            <span class="ms-2 font-mono text-xs">{{ $workflowId }}</span>
                        </div>
                    @else
                        <div class="flex min-h-[360px] items-center justify-center rounded-xl border border-dashed border-gray-300 p-8 text-sm text-gray-500 dark:border-white/10">
                            {{ __('seo-content-ai::filament.automation.flows.select_workflow_prompt') }}
                        </div>
                    @endif
                </div>
            </div>
        @elseif ($viewMode === 'components')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold dark:border-white/10">
                    {{ __('seo-content-ai::filament.automation.flows.components_heading', ['count' => count($components)]) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="awv-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_identifier') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_type') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_registry') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($components as $flow)
                                <tr>
                                    <td>
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $flow['name'] ?? $flow['code'] ?? '' }}</div>
                                        <div class="font-mono text-[11px] text-gray-500">{{ $flow['code'] ?? $flow['id'] ?? '' }}</div>
                                    </td>
                                    <td>{{ $flow['category_label'] ?? $flow['category'] ?? '' }}</td>
                                    <td class="font-mono text-[11px]">{{ $flow['source'] ?? '' }}</td>
                                    <td>{{ $flow['status_label'] ?? '' }}</td>
                                    <td>
                                        <button type="button" class="text-xs font-semibold text-primary-600" wire:click="selectFlow(@js($flow['id']))">
                                            {{ __('seo-content-ai::filament.automation.flows.open_details') }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-gray-500">{{ __('seo-content-ai::filament.automation.flows.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($selectedFlow)
                    <div class="border-t border-gray-100 p-4 dark:border-white/10">
                        @include('seo-content-ai::filament.pages.partials.automation-component-detail', ['flow' => $selectedFlow])
                    </div>
                @endif
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold dark:border-white/10">
                    {{ __('seo-content-ai::filament.automation.flows.unmapped_heading', ['count' => count($unmapped)]) }}
                </div>
                <p class="px-4 py-2 text-sm text-gray-500">{{ __('seo-content-ai::filament.automation.flows.unmapped_intro') }}</p>
                <div class="overflow-x-auto">
                    <table class="awv-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_identifier') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_type') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_registry') }}</th>
                                <th>{{ __('seo-content-ai::filament.automation.flows.table_reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($unmapped as $item)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $item['name'] ?? $item['code'] ?? '' }}</div>
                                        <div class="font-mono text-[11px] text-gray-500">{{ $item['code'] ?? $item['id'] ?? '' }}</div>
                                    </td>
                                    <td>{{ $item['category_label'] ?? $item['category'] ?? '' }}</td>
                                    <td class="font-mono text-[11px]">{{ $item['source'] ?? '' }}</td>
                                    <td class="text-amber-700 dark:text-amber-300">{{ $item['unmapped_reason'] ?? '' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-emerald-700">{{ __('seo-content-ai::filament.automation.flows.unmapped_empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
