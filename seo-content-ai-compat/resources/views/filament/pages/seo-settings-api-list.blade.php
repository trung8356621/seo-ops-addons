<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'api'])

            <div class="seo-settings-main">
                <header class="seo-settings-header seo-settings-header--with-actions">
                    <div>
                        <h1>{{ __('seo-content-ai::filament.api_connections.title') }}</h1>
                        <p>{{ __('seo-content-ai::filament.api_connections.subtitle') }}</p>
                    </div>
                    <button
                        type="button"
                        class="seo-capability-matrix-trigger"
                        x-data
                        @click="$dispatch('open-capability-matrix')"
                        title="{{ __('seo-content-ai::filament.api_connections.capability_matrix_tooltip') }}"
                        aria-label="{{ __('seo-content-ai::filament.api_connections.capability_matrix_tooltip') }}"
                    >
                        <x-heroicon-o-question-mark-circle class="h-5 w-5" />
                    </button>
                </header>

                <div class="seo-api-type-filters" role="group" aria-label="{{ __('seo-content-ai::filament.api_connections.type_filter_label') }}">
                    @foreach ([
                        'all' => __('seo-content-ai::filament.api_connections.type_filter_all'),
                        'ai' => __('seo-content-ai::filament.api_connections.type_ai'),
                        'seo' => __('seo-content-ai::filament.api_connections.type_seo'),
                    ] as $filterKey => $filterLabel)
                        <button
                            type="button"
                            wire:click="setConnectionTypeFilter('{{ $filterKey }}')"
                            wire:loading.attr="disabled"
                            wire:target="setConnectionTypeFilter"
                            @class(['seo-api-type-filter', 'is-active' => $connectionTypeFilter === $filterKey])
                        >
                            {{ $filterLabel }}
                        </button>
                    @endforeach
                </div>

                @if (count($this->getCachedHeaderActions()))
                    <div class="mb-4 flex justify-end">
                        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
                    </div>
                @endif

                <div class="seo-settings-ai-table">
                    {{ $this->table }}
                </div>
            </div>
        </div>

        @include('seo-content-ai::filament.pages.partials.api-capability-matrix-modal', [
            'rows' => $this->capabilityMatrixRows(),
            'columns' => $this->capabilityMatrixColumns(),
        ])
    </x-filament-panels::page>
</div>
