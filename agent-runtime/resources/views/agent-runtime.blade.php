<x-filament-panels::page>
    <div
        id="agent-runtime-root"
        class="agent-runtime-root"
        data-projects-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/projects"
        data-turn-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/turns"
        data-threads-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/threads"
        data-copy-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/model-input"
        data-model-debug-apply-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/model-debug/apply"
        data-csrf="{{ csrf_token() }}"
        data-host-context="{{ json_encode([
            'appKey' => 'seo-ops',
            'capabilities' => ['turn', 'model-input'],
        ]) }}"
    ></div>
    @if (file_exists(public_path('build-agent/manifest.json')))
        @vite('addons/agent-runtime/resources/js/app/main.jsx', 'build-agent')
    @else
        @vite('addons/agent-runtime/resources/js/app/main.jsx')
    @endif
</x-filament-panels::page>
