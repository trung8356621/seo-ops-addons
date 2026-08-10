<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'api'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ $this->getTitle() }}</h1>
                </header>

                @if (count($this->getCachedHeaderActions()))
                    <div class="mb-4 flex justify-end gap-2">
                        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
                    </div>
                @endif

                <x-filament-panels::form wire:submit="save">
                    {{ $this->form }}

                    <div class="mt-4 flex gap-2">
                        <x-filament::button type="submit">
                            {{ __('seo-content-ai::filament.api_connections.save') }}
                        </x-filament::button>
                        <x-filament::button
                            tag="a"
                            :href="\Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::getUrl('index')"
                            color="gray"
                        >
                            {{ __('seo-content-ai::filament.api_connections.cancel') }}
                        </x-filament::button>
                    </div>
                </x-filament-panels::form>

                @if (property_exists($this, 'gscBulkSyncResult') && $this->gscBulkSyncResult)
                    <div class="mt-6">
                        @include('seo-content-ai::seo.performance-hub.partials.gsc-bulk-sync-summary', [
                            'result' => $this->gscBulkSyncResult,
                        ])
                    </div>
                @endif
            </div>
        </div>
    </x-filament-panels::page>
</div>
