<x-filament-panels::page>
    <div class="seo-settings-root">
        @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'keyword-sources'])
        <div class="seo-settings-main">
            <header class="seo-settings-header">
                <h1>{{ __('seo-content-ai::filament.keyword.quality_settings') }}</h1>
                <p>{{ __('seo-content-ai::filament.keyword.quality_settings_description') }}</p>
                <p class="mt-2 text-sm font-medium">{{ $siteLabel }}</p>
            </header>

            <form wire:submit="savePolicy" class="max-w-3xl space-y-6">
                {{ $this->form }}
                <div class="flex justify-end">
                    <x-seo-content-ai::form-save-button
                        target="savePolicy"
                        :label="__('Save settings')"
                    />
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
