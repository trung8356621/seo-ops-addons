<x-filament-panels::page>
    <div
        id="agent-runtime-root"
        class="agent-runtime-root"
        data-projects-url="{{ url('/agent-runtime/projects') }}"
        data-turn-url="{{ url('/agent-runtime/turns') }}"
        data-copy-url="{{ url('/agent-runtime/model-input') }}"
        data-csrf="{{ csrf_token() }}"
        data-host-context="{{ json_encode([
            'appKey' => 'seo-ops',
            'capabilities' => ['turn', 'model-input'],
        ]) }}"
    ></div>
    @vite('addons/agent-runtime/resources/js/app/main.jsx')
</x-filament-panels::page>
