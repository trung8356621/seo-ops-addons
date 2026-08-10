<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('seo-content-ai::filament.automation.save_settings') }}</span>
                <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.automation.save_settings') }}
                </span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
