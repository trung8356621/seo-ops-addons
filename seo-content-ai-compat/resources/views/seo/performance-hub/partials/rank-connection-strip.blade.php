@php
    $connection = $connections['provider'] ?? [];
    $settingsUrl = $connections['settings_url'] ?? '#';
@endphp

<div class="performance-hub-provider-strip">
    <span class="performance-hub-provider-strip__name">{{ $connection['label'] ?? '' }}</span>
    <span class="performance-hub-provider-strip__dot" aria-hidden="true">·</span>
    <span class="performance-hub-provider-strip__status">{{ $connection['status'] ?? __('seo-content-ai::filament.api_connections.not_configured') }}</span>
    @if (! empty($connection['usage_label']))
        <span class="performance-hub-provider-strip__dot" aria-hidden="true">·</span>
        <span class="performance-hub-provider-strip__meta">{{ $connection['usage_label'] }}</span>
    @endif
    <div class="performance-hub-provider-strip__actions">
        <button
            type="button"
            wire:click="testSerpConnection"
            wire:loading.attr="disabled"
            wire:target="testSerpConnection"
            class="performance-hub-icon-btn performance-hub-icon-btn--ghost performance-hub-icon-btn--xs"
            title="{{ __('seo-content-ai::filament.api_connections.test_connection') }}"
            aria-label="{{ __('seo-content-ai::filament.api_connections.test_connection') }}"
        >
            <span wire:loading.remove wire:target="testSerpConnection" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H4.989a.75.75 0 00-.75.75v3.75a.75.75 0 001.5 0v-2.433l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h3.751a.75.75 0 00.53-.219z" clip-rule="evenodd" /></svg>
            </span>
            <span wire:loading wire:target="testSerpConnection" class="performance-hub-spinner performance-hub-spinner--xs" aria-hidden="true"></span>
        </button>
        <a
            href="{{ $settingsUrl }}"
            class="performance-hub-icon-btn performance-hub-icon-btn--ghost performance-hub-icon-btn--xs"
            title="{{ __('seo-content-ai::filament.performance_hub.manage_api_settings') }}"
            aria-label="{{ __('seo-content-ai::filament.performance_hub.manage_api_settings') }}"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5"><path fill-rule="evenodd" d="M7.84 1.804A1 1 0 018.82 1h2.36a1 1 0 01.98.804l.331 1.652a6.993 6.993 0 011.929 1.115l1.598-.54a1 1 0 011.186.447l1.18 2.044a1 1 0 01-.205 1.251l-1.267 1.113a7.047 7.047 0 010 2.228l1.267 1.113a1 1 0 01.206 1.25l-1.18 2.045a1 1 0 01-1.187.447l-1.598-.54a6.993 6.993 0 01-1.929 1.115l-.33 1.652a1 1 0 01-.98.804H8.82a1 1 0 01-.98-.804l-.331-1.652a6.993 6.993 0 01-1.929-1.115l-1.598.54a1 1 0 01-1.186-.447l-1.18-2.044a1 1 0 01.205-1.251l1.267-1.113a7.047 7.047 0 010-2.228L2.16 6.165a1 1 0 01-.205-1.251l1.18-2.044a1 1 0 011.186-.447l1.598.54A6.993 6.993 0 017.868 1.804L7.84 1.804zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" /></svg>
        </a>
    </div>
</div>
