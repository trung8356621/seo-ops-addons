<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'ai'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('AI settings') }}</h1>
                    <p>{{ __('Manage API keys and default models for Gemini and Claude.') }}</p>
                </header>

                @if(count($this->getCachedHeaderActions()))
                    <div class="mb-4 flex justify-end">
                        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
                    </div>
                @endif

                <div class="seo-settings-ai-table">
                    {{ $this->table }}
                </div>
            </div>
        </div>
    </x-filament-panels::page>
</div>
