<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'scoring'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_scoring.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_scoring.intro') }}</p>
                </header>

                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    {{ __('seo-content-ai::filament.settings_scoring.apply_hint') }}
                </div>

                <form wire:submit="saveScoringSettings" class="max-w-4xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-seo-content-ai::form-save-button
                            target="saveScoringSettings"
                            :label="__('Save settings')"
                        />
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
