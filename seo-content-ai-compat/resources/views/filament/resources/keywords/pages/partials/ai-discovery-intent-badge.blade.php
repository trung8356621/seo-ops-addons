@php
    $intent = strtolower(trim($intent ?? 'informational'));
@endphp

<span @class([
    'ai-discovery-intent-badge',
    'ai-discovery-intent-badge--informational' => $intent === 'informational',
    'ai-discovery-intent-badge--commercial' => $intent === 'commercial',
    'ai-discovery-intent-badge--transactional' => $intent === 'transactional',
])>
    @switch($intent)
        @case('commercial')
            {{ __('seo-content-ai::filament.keyword.discovery_intent_commercial') }}
            @break
        @case('transactional')
            {{ __('seo-content-ai::filament.keyword.discovery_intent_transactional') }}
            @break
        @default
            {{ __('seo-content-ai::filament.keyword.discovery_intent_informational') }}
    @endswitch
</span>
