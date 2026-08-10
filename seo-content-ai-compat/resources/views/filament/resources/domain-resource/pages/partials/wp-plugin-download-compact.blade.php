@props([
    'overview',
])

@php
    $latest = $overview['latest'] ?? null;
    $metadata = is_array($overview['metadata'] ?? null) ? $overview['metadata'] : [];
@endphp

@if(($overview['has_packages'] ?? false) && is_array($latest))
    <div class="seo-wp-plugin-compact">
        <div class="seo-wp-plugin-compact__icon" aria-hidden="true">
            <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-6 w-6" />
        </div>

        <div class="seo-wp-plugin-compact__body">
            <p class="seo-wp-plugin-compact__title">
                {{ $metadata['name'] ?? 'OMI SEO AI Bridge' }}
            </p>
            <p class="seo-wp-plugin-compact__desc">
                {{ __('seo-content-ai::filament.dashboard.wp_plugin_compact_desc') }}
            </p>

            <div class="seo-wp-plugin-compact__meta">
                <span class="seo-wp-plugin-compact__version">
                    v{{ $latest['version'] }}
                </span>
                @if(filled($latest['size_label'] ?? null))
                    <span class="seo-wp-plugin-compact__size">{{ $latest['size_label'] }}</span>
                @endif
            </div>

            <x-filament::button
                tag="a"
                :href="route('seo.wp-plugin.download', ['version' => $latest['version']])"
                icon="heroicon-o-arrow-down-tray"
                color="primary"
                size="sm"
                class="seo-wp-plugin-compact__download"
            >
                {{ __('seo-content-ai::filament.wp_plugin.download_latest', ['version' => $latest['version']]) }}
            </x-filament::button>
        </div>
    </div>
@endif
