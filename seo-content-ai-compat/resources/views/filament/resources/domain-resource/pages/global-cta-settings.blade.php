<x-filament-panels::page>
    <form wire:submit="saveGlobalCtaSettings" class="mx-auto max-w-3xl space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-seo-content-ai::form-save-button
                target="saveGlobalCtaSettings"
                :label="__('filament-panels::resources/pages/edit-record.form.actions.save.label')"
            />
        </div>
    </form>
</x-filament-panels::page>
