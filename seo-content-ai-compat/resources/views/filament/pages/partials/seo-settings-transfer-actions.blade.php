@php
    $transferUrl = \Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer::getUrl();
@endphp
<div class="flex flex-wrap gap-2">
    <x-filament::button tag="a" size="sm" color="gray" :href="$transferUrl.'?intent=import'">
        {{ __('seo-content-ai::filament.settings_transfer.import') }}
    </x-filament::button>
    <x-filament::button tag="a" size="sm" color="gray" :href="$transferUrl.'?intent=export'">
        {{ __('seo-content-ai::filament.settings_transfer.export') }}
    </x-filament::button>
</div>
