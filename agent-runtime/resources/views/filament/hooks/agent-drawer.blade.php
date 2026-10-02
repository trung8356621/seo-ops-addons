@php
    $appKey = 'seo-ops';
    $scope = ['type' => 'global', 'ref' => 'global'];

    if (class_exists(\Omnichannel\Addons\Seo\Support\DomainContextResolver::class)) {
        try {
            $resolver = app(\Omnichannel\Addons\Seo\Support\DomainContextResolver::class);
            $domainContext = $resolver->current();
            if ($domainContext && ! $domainContext->isAllDomains && $domainContext->siteId) {
                $scope = [
                    'type' => 'site',
                    'ref' => 'site:' . $domainContext->siteId,
                    'siteId' => $domainContext->siteId,
                    'label' => $domainContext->domainKey,
                ];
            }
        } catch (\Throwable) {
            // Keep fallback to global scope
        }
    }

    $hostContext = [
        'appKey' => $appKey,
        'scope' => $scope,
        'capabilities' => ['turn', 'model-input'],
    ];
@endphp

@vite('addons/agent-runtime/resources/js/app/main.jsx', 'build-agent')

<div
    class="agent-drawer-host"
    data-agent-drawer-host
    x-data="{
        open: false,
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => {
                    window.AgentRuntime?.mountDrawer?.();
                });
            }
        },
        close() {
            this.open = false;
        }
    }"
    x-on:agent-drawer:toggle.window="toggle()"
    x-on:agent-drawer:open.window="open = true; $nextTick(() => window.AgentRuntime?.mountDrawer?.())"
    x-on:agent-drawer:close.window="close()"
    x-on:keydown.escape.window="close()"
>
    {{-- Dimmed backdrop scrim --}}
    <div
        class="agent-drawer-scrim"
        x-show="open"
        x-cloak
        x-transition:enter="agent-transition-fade"
        x-transition:enter-start="agent-opacity-0"
        x-transition:enter-end="agent-opacity-100"
        x-transition:leave="agent-transition-fade"
        x-transition:leave-start="agent-opacity-100"
        x-transition:leave-end="agent-opacity-0"
        x-on:click="close()"
        aria-hidden="true"
    ></div>

    {{-- Right-side drawer container --}}
    <aside
        class="agent-drawer-container"
        x-show="open"
        x-cloak
        x-transition:enter="agent-transition-slide"
        x-transition:enter-start="agent-translate-x-full"
        x-transition:enter-end="agent-translate-x-0"
        x-transition:leave="agent-transition-slide"
        x-transition:leave-start="agent-translate-x-0"
        x-transition:leave-end="agent-translate-x-full"
        role="dialog"
        aria-modal="true"
        aria-label="AI Agent"
    >
        <div
            id="agent-drawer-root"
            class="agent-drawer-root"
            data-projects-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/projects"
            data-turn-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/turns"
            data-threads-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/threads"
            data-copy-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/model-input"
            data-model-debug-apply-url="{{ request()->getSchemeAndHttpHost() }}/agent-runtime/model-debug/apply"
            data-csrf="{{ csrf_token() }}"
            data-host-context="{{ json_encode($hostContext) }}"
        ></div>
    </aside>
</div>
