@php
    $cards = [
        'tracked_keywords' => __('seo-content-ai::filament.performance_hub.kpi_tracked_keywords'),
        'top_3' => __('seo-content-ai::filament.performance_hub.kpi_top_3'),
        'top_10' => __('seo-content-ai::filament.performance_hub.kpi_top_10'),
        'avg_position' => __('seo-content-ai::filament.performance_hub.kpi_position'),
        'search_volume' => __('seo-content-ai::filament.performance_hub.kpi_search_volume'),
        'visibility' => __('seo-content-ai::filament.performance_hub.kpi_visibility'),
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
                    {{ is_float($value) ? number_format($value, 1) : number_format((float) $value) }}
                @else
                    <span class="performance-hub-empty-value">{{ __('seo-content-ai::filament.performance_hub.empty_'.$emptyLabel) }}</span>
                @endif
            </p>
        </article>
    @endforeach
</section>
