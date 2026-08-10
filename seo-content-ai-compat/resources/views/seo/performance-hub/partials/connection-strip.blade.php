@php
    $gsc = $connections['gsc'] ?? [];
    $dataforseo = $connections['dataforseo'] ?? [];
    $settingsUrl = $connections['settings_url'] ?? '#';
@endphp

<section class="performance-hub-connection-strip">
    <div class="performance-hub-connection-card">
        <span class="performance-hub-connection-card__label">Google Search Console</span>
        <span class="performance-hub-connection-card__status">{{ $gsc['label'] ?? __('seo-content-ai::filament.api_connections.not_configured') }}</span>
        @if (! empty($gsc['property_url']))
            <span class="performance-hub-connection-card__meta">{{ $gsc['property_url'] }}</span>
        @endif
        @if (! empty($gsc['last_synced_at']))
            <span class="performance-hub-connection-card__meta">{{ __('seo-content-ai::filament.performance_hub.gsc_last_synced', ['time' => $gsc['last_synced_at']]) }}</span>
        @elseif (($gsc['configured'] ?? false) === true && ($gsc['has_snapshot'] ?? false) !== true)
            <span class="performance-hub-connection-card__meta performance-hub-connection-card__meta--warn">{{ __('seo-content-ai::filament.performance_hub.gsc_snapshot_missing') }}</span>
        @endif
        @if (($gsc['configured'] ?? false) === true)
            <button
                type="button"
                wire:click="syncGscData"
                wire:loading.attr="disabled"
                wire:target="syncGscData"
                class="performance-hub-connection-card__action"
            >
                <span wire:loading.remove wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.sync_gsc') }}</span>
                <span wire:loading wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.syncing_gsc') }}</span>
            </button>
        @endif
    </div>
    <div class="performance-hub-connection-card">
        <span class="performance-hub-connection-card__label">DataForSEO</span>
        <span class="performance-hub-connection-card__status">{{ $dataforseo['label'] ?? __('seo-content-ai::filament.api_connections.not_configured') }}</span>
        @if (($dataforseo['balance'] ?? null) !== null)
            <span class="performance-hub-connection-card__meta">{{ __('seo-content-ai::filament.api_connections.balance') }}: {{ number_format((float) $dataforseo['balance'], 2) }}</span>
        @endif
    </div>
    <a href="{{ $settingsUrl }}" class="performance-hub-connection-card performance-hub-connection-card--link">
        {{ __('seo-content-ai::filament.performance_hub.manage_api_settings') }}
    </a>
</section>
