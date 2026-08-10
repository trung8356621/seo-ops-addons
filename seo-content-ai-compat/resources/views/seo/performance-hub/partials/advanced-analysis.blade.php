@php
    $advanced = $advancedAnalysis ?? [];
    $organic = $advanced['organic_visibility'] ?? [];
    $comparison = $advanced['provider_comparison'] ?? [];
@endphp

@if (($advanced['has_any'] ?? false) === true)
    <div
        class="performance-hub-advanced"
        x-data="{ expanded: false }"
        x-cloak
    >
        <button
            type="button"
            x-show="! expanded"
            x-on:click="expanded = true"
            class="performance-hub-advanced-toggle"
        >
            {{ __('seo-content-ai::filament.performance_hub.advanced_show') }}
        </button>

        <div x-show="expanded" x-cloak class="performance-hub-advanced__panel">
            <div class="performance-hub-advanced__head">
                <h2 class="performance-hub-advanced__title">{{ __('seo-content-ai::filament.performance_hub.advanced_heading') }}</h2>
                <button
                    type="button"
                    x-on:click="expanded = false"
                    class="performance-hub-advanced-collapse"
                >
                    {{ __('seo-content-ai::filament.performance_hub.advanced_collapse') }}
                </button>
            </div>

            <div class="performance-hub-advanced__body space-y-6">
                @if (($organic['eligible'] ?? false) === true)
                    @include('seo-content-ai::seo.performance-hub.partials.visibility-chart', [
                        'chart' => $organic['data'] ?? [],
                    ])
                @endif

                @if (($comparison['eligible'] ?? false) === true)
                    @include('seo-content-ai::seo.performance-hub.partials.provider-comparison', [
                        'rows' => $comparison['data'] ?? [],
                    ])
                @endif
            </div>
        </div>
    </div>
@endif
