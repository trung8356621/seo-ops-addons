@php
    use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;

    $isRankProvider = SerpProviderKeys::isValid($dataSource);
@endphp

<header class="performance-hub-header">
    <div class="performance-hub-header__main">
        <div>
            <h1 class="performance-hub-title">{{ __('seo-content-ai::filament.performance_hub.title') }}</h1>
            <p class="performance-hub-subtitle">{{ __('seo-content-ai::filament.performance_hub.subtitle') }}</p>
        </div>
        @if (! $isRankProvider)
            <p class="performance-hub-toolbar__note">{{ __('seo-content-ai::filament.performance_hub.gsc_date_range_note') }}</p>
        @endif
    </div>
</header>

@if ($isRankProvider)
    @include('seo-content-ai::seo.performance-hub.partials.rank-group-modal')
@endif
