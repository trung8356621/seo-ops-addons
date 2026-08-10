<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'api'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ $this->getTitle() }}</h1>
                </header>

                <x-filament-panels::form wire:submit="{{ $this instanceof \Filament\Resources\Pages\CreateRecord ? 'create' : 'save' }}">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            </div>
        </div>
    </x-filament-panels::page>
</div>
