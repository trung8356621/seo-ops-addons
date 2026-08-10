@php
    $tooltip = match ($cell) {
        'available' => __('seo-content-ai::filament.api_connections.capability_cell_available', [
            'provider' => $provider,
            'capability' => $capability,
        ]),
        'vendor_only' => __('seo-content-ai::filament.api_connections.capability_cell_vendor_only', [
            'provider' => $provider,
            'capability' => $capability,
        ]),
        'not_configured' => __('seo-content-ai::filament.api_connections.capability_cell_not_configured', [
            'provider' => $provider,
            'capability' => $capability,
        ]),
        default => __('seo-content-ai::filament.api_connections.capability_cell_unsupported', [
            'provider' => $provider,
            'capability' => $capability,
        ]),
    };

    $aria = $legend ?? false ? $capability : $tooltip;
@endphp

<span
    @class([
        'seo-capability-cell',
        'seo-capability-cell--'.$cell,
        'seo-capability-cell--legend' => $legend ?? false,
    ])
    title="{{ $tooltip }}"
    aria-label="{{ $aria }}"
>
    @switch($cell)
        @case('available')
            <x-heroicon-s-check-circle class="h-4 w-4" />
            @break
        @case('vendor_only')
            <x-heroicon-s-clock class="h-4 w-4" />
            @break
        @case('not_configured')
            <x-heroicon-s-lock-closed class="h-4 w-4" />
            @break
        @default
            <x-heroicon-s-minus-circle class="h-4 w-4" />
    @endswitch
</span>
