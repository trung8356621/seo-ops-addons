@php
    $chart = $chart ?? [];
    $metric = $chart['metric'] ?? 'clicks';
    $status = $chart['status'] ?? 'empty';
    $hasData = ($chart['has_data'] ?? false) === true;
    $chartPayloadJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
@endphp

<section class="performance-hub-panel performance-hub-gsc-chart-panel">
    <div class="performance-hub-panel__head performance-hub-panel__head--split">
        <div>
            <h2>{{ __('seo-content-ai::filament.performance_hub.chart_gsc_performance') }}</h2>
            <p>{{ __('seo-content-ai::filament.performance_hub.chart_gsc_performance_hint') }}</p>
        </div>
        <div class="performance-hub-chart-metric-tabs" role="tablist" aria-label="{{ __('seo-content-ai::filament.performance_hub.chart_metric_label') }}">
            @foreach ([
                'clicks' => __('seo-content-ai::filament.performance_hub.chart_metric_clicks'),
                'impressions' => __('seo-content-ai::filament.performance_hub.chart_metric_impressions'),
                'ctr' => __('seo-content-ai::filament.performance_hub.chart_metric_ctr'),
                'position' => __('seo-content-ai::filament.performance_hub.chart_metric_position'),
            ] as $metricKey => $metricLabel)
                <button
                    type="button"
                    wire:click="setGscChartMetric('{{ $metricKey }}')"
                    wire:loading.attr="disabled"
                    wire:target="setGscChartMetric,syncGscData,setGscMonth,previousGscMonth,nextGscMonth,gscMonth"
                    @class([
                        'performance-hub-chart-metric-tab',
                        'is-active' => $metric === $metricKey,
                    ])
                    role="tab"
                    aria-selected="{{ $metric === $metricKey ? 'true' : 'false' }}"
                >
                    {{ $metricLabel }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="performance-hub-gsc-chart-stage">
        <div
            wire:loading.flex
            wire:target="setGscChartMetric,syncGscData,setGscMonth,previousGscMonth,nextGscMonth,gscMonth"
            class="performance-hub-chart-skeleton performance-hub-chart-skeleton--overlay"
            aria-busy="true"
            aria-label="{{ __('seo-content-ai::filament.performance_hub.chart_loading') }}"
        >
            <div class="performance-hub-chart-skeleton__bar"></div>
            <div class="performance-hub-chart-skeleton__bar"></div>
            <div class="performance-hub-chart-skeleton__bar"></div>
            <p class="performance-hub-chart-skeleton__label">{{ __('seo-content-ai::filament.performance_hub.chart_loading') }}</p>
        </div>

        @if ($hasData)
            {{-- Livewire-updatable payload (outside wire:ignore). --}}
            <textarea
                id="gsc-chart-payload"
                data-gsc-chart-payload
                class="performance-hub-gsc-chart-payload"
                readonly
                hidden
                aria-hidden="true"
            >{{ $chartPayloadJson }}</textarea>

            {{-- Full wire:ignore: Livewire must NOT morph ApexCharts SVG children. --}}
            <div wire:ignore>
                <div
                    id="performance-hub-gsc-chart"
                    class="performance-hub-chart-canvas"
                    data-gsc-chart-root
                    style="min-height: 280px;"
                ></div>
                <p id="gsc-chart-error" class="performance-hub-gsc-chart-error" hidden></p>
            </div>
        @elseif ($status === 'failed')
            <div class="performance-hub-empty-state performance-hub-empty-state--warn">
                <p>{{ __('seo-content-ai::filament.performance_hub.gsc_chart_failed') }}</p>
                <button type="button" wire:click="syncGscData" wire:loading.attr="disabled" wire:target="syncGscData" class="performance-hub-action-btn">
                    <span wire:loading.remove wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.retry_sync') }}</span>
                    <span wire:loading wire:target="syncGscData" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        {{ __('seo-content-ai::filament.performance_hub.syncing_gsc') }}
                    </span>
                </button>
            </div>
        @else
            <div class="performance-hub-empty-state">
                <p>{{ __('seo-content-ai::filament.performance_hub.gsc_chart_empty') }}</p>
            </div>
        @endif
    </div>
</section>
