@php
    $settingsUrl = $settingsUrl ?? '#';
    $periodLabel = $periodLabel ?? '';
@endphp

<section class="performance-hub-connection-strip">
    <div class="performance-hub-connection-card performance-hub-connection-card--gsc">
        <div class="performance-hub-connection-card__head">
            <span class="performance-hub-connection-card__icon" aria-hidden="true">G</span>
            <div>
                <span class="performance-hub-connection-card__label">{{ __('seo-content-ai::filament.api_connections.provider_gsc') }}</span>
                <span class="performance-hub-connection-card__status">{{ $connection['label'] ?? __('seo-content-ai::filament.api_connections.not_configured') }}</span>
            </div>
        </div>
        @if (! empty($connection['property_url']))
            <span class="performance-hub-connection-card__meta">{{ $connection['property_url'] }}</span>
        @endif
        @if (! empty($connection['last_synced_at']))
            <span class="performance-hub-connection-card__meta">{{ __('seo-content-ai::filament.performance_hub.gsc_last_synced', ['time' => \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($connection['last_synced_at']) ?? $connection['last_synced_at']]) }}</span>
        @elseif (($connection['configured'] ?? false) === true && ($connection['has_snapshot'] ?? false) !== true)
            <span class="performance-hub-connection-card__meta performance-hub-connection-card__meta--warn">{{ __('seo-content-ai::filament.performance_hub.gsc_snapshot_missing') }}</span>
        @endif
        <div class="performance-hub-connection-card__actions">
            @if (($connection['configured'] ?? false) === true)
                <button
                    type="button"
                    wire:click="syncGscData"
                    wire:loading.attr="disabled"
                    wire:target="syncGscData"
                    class="performance-hub-connection-card__action"
                >
                    <span wire:loading.remove wire:target="syncGscData">
                        @if ($periodLabel !== '')
                            {{ __('seo-content-ai::filament.performance_hub.sync_gsc_month', ['month' => $periodLabel]) }}
                        @else
                            {{ __('seo-content-ai::filament.performance_hub.sync_current_domain') }}
                        @endif
                    </span>
                    <span wire:loading wire:target="syncGscData">{{ __('seo-content-ai::filament.performance_hub.syncing_gsc') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="syncAllMappedGscDomains"
                    wire:loading.attr="disabled"
                    wire:target="syncAllMappedGscDomains"
                    @disabled($this->isGscBulkSyncing)
                    class="performance-hub-connection-card__action"
                >
                    <span wire:loading.remove wire:target="syncAllMappedGscDomains">{{ __('seo-content-ai::filament.performance_hub.sync_all_auto_map') }}</span>
                    <span wire:loading wire:target="syncAllMappedGscDomains">{{ __('seo-content-ai::filament.performance_hub.syncing_all_mapped') }}</span>
                </button>
            @endif
            <a href="{{ $settingsUrl }}" class="performance-hub-connection-card__action performance-hub-connection-card__action--link">
                {{ __('seo-content-ai::filament.performance_hub.manage_api_settings') }}
            </a>
        </div>
    </div>
</section>
