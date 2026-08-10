@php
    $chart = $chart ?? [];
    $metric = $chart['metric'] ?? 'clicks';
    $status = $chart['status'] ?? 'empty';
    $hasData = ($chart['has_data'] ?? false) === true;
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
                    wire:target="setGscChartMetric"
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

    <div wire:loading wire:target="setGscChartMetric,syncGscData" class="performance-hub-chart-skeleton" aria-hidden="true">
        <div class="performance-hub-chart-skeleton__bar"></div>
        <div class="performance-hub-chart-skeleton__bar"></div>
        <div class="performance-hub-chart-skeleton__bar"></div>
    </div>

    <div wire:loading.remove wire:target="setGscChartMetric,syncGscData">
        @if ($hasData)
            <input type="hidden" id="gsc-chart-payload" value='@json($chart)' wire:key="gsc-chart-payload-{{ md5(json_encode($chart)) }}">
            <div wire:ignore id="performance-hub-gsc-chart" class="performance-hub-chart-canvas" style="min-height: 280px;"></div>
        @elseif ($status === 'failed')
            <div class="performance-hub-empty-state performance-hub-empty-state--warn">
                <p>{{ __('seo-content-ai::filament.performance_hub.gsc_chart_failed') }}</p>
                <button type="button" wire:click="syncGscData" wire:loading.attr="disabled" wire:target="syncGscData" class="performance-hub-action-btn">
                    <span wire:loading.remove wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.retry_sync') }}</span>
                    <span wire:loading wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.syncing_gsc') }}</span>
                </button>
            </div>
        @else
            <div class="performance-hub-empty-state">
                <p>{{ __('seo-content-ai::filament.performance_hub.gsc_chart_empty') }}</p>
            </div>
        @endif
    </div>
</section>

@once
    @vite(['addons/search-intelligence/resources/js/performance-hub-gsc-chart.js'])
@endonce
