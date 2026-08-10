@php
    $bucketMap = [
        'top_3' => '1-3',
        'top_4_10' => '4-10',
        'top_11_20' => '11-20',
        'top_21_50' => '21-50',
        'top_51_100' => '51-100',
    ];
    $activeBucket = $activeBucket ?? '';
@endphp

<section class="performance-hub-panel">
    <div class="performance-hub-panel__head">
        <h2>{{ __('seo-content-ai::filament.performance_hub.distribution_gsc_heading') }}</h2>
        <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.distribution_gsc_hint') }}</p>
    </div>
    <div class="performance-hub-distribution-grid" role="group" aria-label="{{ __('seo-content-ai::filament.performance_hub.distribution_gsc_heading') }}">
        @foreach ([
            'top_3' => '1–3',
            'top_4_10' => '4–10',
            'top_11_20' => '11–20',
            'top_21_50' => '21–50',
            'top_51_100' => '51–100',
        ] as $key => $label)
            @php $bucketValue = $bucketMap[$key]; @endphp
            <button
                type="button"
                wire:click="setPositionBucket('{{ $bucketValue }}')"
                wire:loading.attr="disabled"
                wire:target="setPositionBucket"
                @class([
                    'performance-hub-distribution-item',
                    'performance-hub-distribution-item--active' => $activeBucket === $bucketValue,
                ])
                aria-pressed="{{ $activeBucket === $bucketValue ? 'true' : 'false' }}"
            >
                <span class="performance-hub-distribution-item__range">{{ $label }}</span>
                <strong>{{ (int) ($distribution[$key] ?? 0) }}</strong>
            </button>
        @endforeach
    </div>
</section>
