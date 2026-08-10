<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'prompt'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Prompt settings') }}</h1>
                    <p>{{ __('Tone options for prompt writing; Featured Snippet table thresholds are used in SEO scoring and post-sync validation.') }}</p>
                </header>

                <form wire:submit="savePromptSettings" class="max-w-3xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex justify-end">
                        <x-seo-content-ai::form-save-button
                            target="savePromptSettings"
                            :label="__('Save settings')"
                        />
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
