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
        <h2>{{ __('seo-content-ai::filament.performance_hub.distribution_heading') }}</h2>
        @if ($activeBucket !== '')
            <button type="button" wire:click="clearRankPositionBucket" class="performance-hub-link-btn">
                {{ __('seo-content-ai::filament.performance_hub.clear_bucket_filter') }}
            </button>
        @endif
    </div>
    <div class="performance-hub-distribution-grid">
        @foreach ($bucketMap as $key => $label)
            <button
                type="button"
                wire:click="setRankPositionBucket('{{ $label }}')"
                @class([
                    'performance-hub-distribution-item',
                    'is-active' => $activeBucket === $label,
                ])
            >
                <span class="performance-hub-distribution-item__range">{{ $label }}</span>
                <strong>{{ (int) ($distribution[$key] ?? 0) }}</strong>
            </button>
        @endforeach
    </div>
</section>
