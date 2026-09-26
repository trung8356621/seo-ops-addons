@php
    use Omnichannel\Addons\Content\Filament\Pages\ChatWorkspacePage;
    use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

    $activeTab = $activeTab ?? null;
    $hash = SeoConnectionContext::resolveHashFromRequest()
        ?? SeoConnectionContext::hash();
    if (! is_string($hash) || ! SeoConnectionContext::isValidHashFormat($hash)) {
        $hash = null;
    }

    $modeUrl = static function (string $tab) use ($hash): ?string {
        if ($hash === null) {
            return null;
        }

        try {
            $url = ChatWorkspacePage::getUrl(
                parameters: ['connection_hash' => $hash],
                panel: 'seo',
            );
        } catch (\Throwable) {
            return null;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['tab' => $tab]);
    };

    // Agent Workspace runtime is isolated — no live deep-link.
    $agentUrl = null;
    $groupUrl = $modeUrl('group');
    $ticketUrl = $modeUrl('ticket');
    $missingSite = 'Vui lòng chọn website trong SEO panel.';
    $agentModeUnavailable = 'Agent Workspace đã tách khỏi runtime (reference-only).';
@endphp

@vite('addons/ai-prompt/resources/css/global-ai-chat.css')

<div
    class="seo-global-chat seo-chat-mode-launcher"
    x-data="{
        menuOpen: false,
        agentUrl: @js($agentUrl),
        groupUrl: @js($groupUrl),
        ticketUrl: @js($ticketUrl),
        missingSite: @js($missingSite),
        agentModeUnavailable: @js($agentModeUnavailable),
        toggle() { this.menuOpen = ! this.menuOpen; },
        close() { this.menuOpen = false; },
        go(url) {
            this.close();
            if (! url) {
                window.alert(this.missingSite);
                return;
            }
            window.location.assign(url);
        },
        openAgentMode() {
            this.close();
            window.alert(this.agentModeUnavailable);
        },
    }"
    x-on:keydown.escape.window="close()"
>
    <div
        class="seo-chat-mode-launcher__menu"
        x-show="menuOpen"
        x-cloak
        x-transition.opacity
        role="menu"
        aria-label="{{ __('seo-content-ai::filament.chat_workspace.mode_menu') }}"
    >
        <button type="button" role="menuitem" class="seo-chat-mode-launcher__item {{ $activeTab === 'agent' ? 'is-active' : '' }} opacity-50" x-on:click="openAgentMode()" aria-disabled="true">
            {{ __('seo-content-ai::filament.chat_workspace.tab_agent') }}
        </button>
        <button type="button" role="menuitem" class="seo-chat-mode-launcher__item {{ $activeTab === 'group' ? 'is-active' : '' }}" x-on:click="go(groupUrl)">
            {{ __('seo-content-ai::filament.chat_workspace.tab_group') }}
        </button>
        <button type="button" role="menuitem" class="seo-chat-mode-launcher__item {{ $activeTab === 'ticket' ? 'is-active' : '' }}" x-on:click="go(ticketUrl)">
            {{ __('seo-content-ai::filament.chat_workspace.tab_ticket') }}
        </button>
    </div>

    <button
        type="button"
        class="seo-global-chat__launcher"
        x-on:click="toggle()"
        x-bind:aria-expanded="menuOpen ? 'true' : 'false'"
        aria-haspopup="menu"
        aria-label="{{ __('seo-content-ai::filament.chat_workspace.launcher') }}"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12c0 4.142-4.03 7.5-9 7.5a10.8 10.8 0 0 1-3.75-.658L3 20.25l1.575-3.675A6.9 6.9 0 0 1 3 12c0-4.142 4.03-7.5 9-7.5s9 3.358 9 7.5Z" />
        </svg>
        <span
            class="seo-global-chat__launcher-badge"
            data-chat-unread-badge
            hidden
        ></span>
    </button>
</div>
