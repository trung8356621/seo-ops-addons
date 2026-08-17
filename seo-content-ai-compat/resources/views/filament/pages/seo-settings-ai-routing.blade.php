<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'ai-routing'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_ai_routing.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_ai_routing.intro') }}</p>
                </header>

                <form wire:submit="saveRouting" class="max-w-4xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-seo-content-ai::form-save-button
                            target="saveRouting"
                            :label="__('Save settings')"
                        />
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
