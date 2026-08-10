<x-filament-panels::page>
    @php
        $props = $this->getBuilderProps();
    @endphp

    <div
        x-data
        x-on:automation-workflow-save-draft.window="$wire.saveDraft($event.detail)"
        x-on:automation-workflow-validate.window="$wire.validateGraph($event.detail)"
        x-on:automation-workflow-publish.window="$wire.publish($event.detail)"
        x-on:automation-workflow-test.window="$wire.testDryRun($event.detail)"
        x-on:automation-workflow-export.window="$wire.exportJson($event.detail)"
        x-on:automation-workflow-import.window="$wire.importJson($event.detail)"
        class="automation-workflow-builder-page h-full w-full overflow-hidden"
    >
        <div
            wire:ignore
            id="automation-workflow-builder"
            data-props='@json($props)'
            class="h-full w-full"
        ></div>
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('addons/content-projects/resources/js/automation-workflow-builder.jsx')
    @endpush
</x-filament-panels::page>
