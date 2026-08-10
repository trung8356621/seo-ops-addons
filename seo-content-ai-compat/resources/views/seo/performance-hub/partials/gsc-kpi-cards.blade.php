@php
    $cards = [
        'total_clicks' => __('seo-content-ai::filament.performance_hub.kpi_clicks'),
        'total_impressions' => __('seo-content-ai::filament.performance_hub.kpi_impressions'),
        'avg_ctr' => __('seo-content-ai::filament.performance_hub.kpi_ctr'),
        'avg_position' => __('seo-content-ai::filament.performance_hub.kpi_position'),
        'total_queries' => __('seo-content-ai::filament.performance_hub.kpi_total_queries'),
    ];
@endphp

<section class="performance-hub-kpi-grid">
    @foreach ($cards as $key => $label)
        @php
            $item = $kpis[$key] ?? ['value' => null, 'label' => 'not_synced'];
            $value = $item['value'] ?? null;
            $emptyLabel = $item['label'] ?? null;
        @endphp
        <article class="performance-hub-kpi-card">
            <p class="performance-hub-kpi-card__label">{{ $label }}</p>
            <p class="performance-hub-kpi-card__value">
                @if ($value !== null)
                    @if ($key === 'avg_ctr')
                        {{ number_format((float) $value, 2) }}%
                    @elseif (is_float($value))
                        {{ number_format($value, 1) }}
                    @else
                        {{ number_format((float) $value) }}
                    @endif
                @else
                    <span class="performance-hub-empty-value">{{ __('seo-content-ai::filament.performance_hub.empty_'.$emptyLabel) }}</span>
                @endif
            </p>
        </article>
    @endforeach
</section>
