@php
    $cssPath = base_path('addons/search-intelligence/resources/css/performance-hub.css');
    $dataSource = $this->dataSource;
    $activeTab = $this->activeTab;
    $isRankProvider = $this->dashboard->hasRankProvider($dataSource);
    $isKeywordMetrics = $this->dashboard->isKeywordMetricsSource($dataSource);
@endphp

<x-filament-panels::page class="performance-hub-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    @vite(['addons/search-intelligence/resources/js/performance-hub-gsc-chart.js'])

    <div class="performance-hub-shell space-y-6">
        @if ($isRankProvider)
            @php $rankState = $this->rankDashboardState; @endphp
        @endif
        @include('seo-content-ai::seo.performance-hub.partials.header', [
            'dataSource' => $dataSource,
            'rankState' => $rankState ?? [],
        ])
        @include('seo-content-ai::seo.performance-hub.partials.source-tabs', ['dataSource' => $dataSource])

        @if ($dataSource === 'gsc')
            @php $gscState = $this->gscDashboardState; @endphp
            <div class="performance-hub-gsc-stack">
            @include('seo-content-ai::seo.performance-hub.partials.gsc-month-header', ['gscState' => $gscState])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-connection-strip', [
                'connection' => $gscState['connection'] ?? [],
                'settingsUrl' => $gscState['settings_url'] ?? '#',
                'periodLabel' => $gscState['period_label'] ?? '',
            ])

            @if (($gscState['has_data'] ?? false) !== true)
                <div class="performance-hub-empty-state">
                    <p>{{ __('seo-content-ai::filament.performance_hub.gsc_month_empty', ['month' => $gscState['period_label'] ?? '']) }}</p>
                    @if (($gscState['connection']['configured'] ?? false) === true)
                        <button
                            type="button"
                            wire:click="syncGscData"
                            wire:loading.attr="disabled"
                            wire:target="syncGscData"
                            class="performance-hub-action-btn mt-3"
                        >
                            <span wire:loading.remove wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.sync_gsc_month', ['month' => $gscState['period_label'] ?? '']) }}</span>
                            <span wire:loading wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.syncing_gsc') }}</span>
                        </button>
                    @endif
                </div>
            @else
            @include('seo-content-ai::seo.performance-hub.partials.gsc-kpi-cards', ['kpis' => $gscState['kpis'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-chart', ['chart' => $gscState['chart'] ?? []])
            @include('seo-content-ai::seo.performance-hub.partials.gsc-distribution', [
                'distribution' => $gscState['distribution'] ?? [],
                'activeBucket' => $gscState['position_bucket'] ?? '',
            ])
            @endif
            </div>

            @if ($this->gscBulkSyncResult)
                @include('seo-content-ai::seo.performance-hub.partials.gsc-bulk-sync-summary', [
                    'result' => $this->gscBulkSyncResult,
                ])
            @endif

            @include('seo-content-ai::seo.performance-hub.partials.gsc-intelligence-panel')

            <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.tabs_label') }}">
                @foreach ([
                    'queries' => __('seo-content-ai::filament.performance_hub.tab_queries'),
                    'quick-wins' => __('seo-content-ai::filament.performance_hub.tab_quick_wins'),
                ] as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:click="setActiveTab('{{ $tabKey }}')"
                        wire:loading.attr="disabled"
                        wire:target="setActiveTab"
                        @class(['performance-hub-tab', 'is-active' => $activeTab === $tabKey])
                        aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                    >
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </nav>

            @if ($activeTab === 'queries')
                @include('seo-content-ai::seo.performance-hub.partials.gsc-queries-table', [
                    'queries' => $gscState['queries'] ?? [],
                    'hasData' => ($gscState['has_data'] ?? false) === true,
                    'pagination' => $gscState['queries_pagination'] ?? [],
                    'totalFiltered' => $gscState['queries_total_filtered'] ?? 0,
                    'totalSource' => $gscState['queries_total_source'] ?? 0,
                    'activeBucket' => $gscState['position_bucket'] ?? '',
                ])
            @endif

            @if ($activeTab === 'quick-wins' && ($gscState['has_data'] ?? false) === true)
                @include('seo-content-ai::seo.performance-hub.partials.quick-wins-table', ['rows' => $gscState['quick_wins'] ?? []])
            @endif
        @endif

        @if ($isRankProvider)
            @php
                $rankState = $this->rankDashboardState;
                $sections = $rankState['dashboard_sections'] ?? [];
                $hasSection = static fn (string $key): bool => in_array($key, $sections, true);
            @endphp
            @if ($hasSection('rank_kpis') || $hasSection('rank_distribution') || $hasSection('rankings_table'))
                @include('seo-content-ai::seo.performance-hub.partials.rank-connection-strip', [
                    'connections' => $rankState['connections'] ?? [],
                ])
            @endif
            @if ($hasSection('rank_kpis'))
                @include('seo-content-ai::seo.performance-hub.partials.rank-kpi-cards', ['kpis' => $rankState['kpis'] ?? []])
            @endif
            @if ($hasSection('rank_distribution'))
                @include('seo-content-ai::seo.performance-hub.partials.ranking-distribution', [
                    'distribution' => $rankState['distribution'] ?? [],
                    'activeBucket' => $rankState['position_bucket'] ?? '',
                ])
            @endif

            <div class="performance-hub-tabs-row">
                <nav class="performance-hub-tabs" aria-label="{{ __('seo-content-ai::filament.performance_hub.tabs_label') }}">
                    @foreach ([
                        'rankings' => __('seo-content-ai::filament.performance_hub.tab_rankings'),
                        'serp-changes' => __('seo-content-ai::filament.performance_hub.tab_serp_changes'),
                    ] as $tabKey => $tabLabel)
                        @if (($tabKey === 'rankings' && $hasSection('rankings_table')) || ($tabKey === 'serp-changes' && $hasSection('serp_changes')))
                            <button
                                type="button"
                                wire:click="setActiveTab('{{ $tabKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="setActiveTab"
                                @class(['performance-hub-tab', 'is-active' => $activeTab === $tabKey])
                                aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                            >
                                {{ $tabLabel }}
                            </button>
                        @endif
                    @endforeach
                </nav>

                @include('seo-content-ai::seo.performance-hub.partials.rank-toolbar', [
                    'rankState' => $rankState,
                ])
            </div>

            @if ($activeTab === 'rankings' && $hasSection('rankings_table'))
                @include('seo-content-ai::seo.performance-hub.partials.rankings-table', ['rows' => $rankState['ranking_rows'] ?? []])
            @endif

            @if ($activeTab === 'serp-changes' && $hasSection('serp_changes'))
                @include('seo-content-ai::seo.performance-hub.partials.serp-changes-table', ['rows' => $rankState['serp_changes'] ?? []])
            @endif

            @if ($hasSection('organic_visibility') || $hasSection('provider_comparison'))
                @include('seo-content-ai::seo.performance-hub.partials.advanced-analysis', [
                    'advancedAnalysis' => $rankState['advanced_analysis'] ?? [],
                ])
            @endif
        @endif

        @if ($isKeywordMetrics)
            @php $metricsState = $this->keywordMetricsDashboardState; @endphp
            @include('seo-content-ai::seo.performance-hub.partials.rank-connection-strip', [
                'connections' => $metricsState['connections'] ?? [],
            ])
            @include('seo-content-ai::seo.performance-hub.partials.keyword-metrics-toolbar', [
                'metricsState' => $metricsState,
            ])
            @include('seo-content-ai::seo.performance-hub.partials.integration-state', [
                'state' => $metricsState['integration_state'] ?? [],
            ])
        @endif
    </div>
</x-filament-panels::page>
