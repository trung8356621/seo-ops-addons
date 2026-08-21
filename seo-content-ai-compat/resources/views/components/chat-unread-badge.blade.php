@php
    $unreadUrl = auth()->check() ? route('seo.team-messages.unread-count') : '';
    $onChatPage = \Omnichannel\Addons\Seo\Support\SeoPanelRoutes::is('filament.seo.pages.chat');
@endphp

@if ($unreadUrl !== '')
    <div
        id="seo-chat-unread-config"
        class="hidden"
        data-props='@json(['unreadUrl' => $unreadUrl])'
        aria-hidden="true"
    ></div>
    @vite('addons/content/resources/js/chat/unreadBadge.js')
@endif

{{-- Outside /chat: round launcher is the only chat entry. On /chat the page mounts its own. --}}
@if (auth()->check() && ! $onChatPage && request()->is('seo', 'seo/*'))
    <x-seo-content-ai::chat-mode-launcher />
@endif
