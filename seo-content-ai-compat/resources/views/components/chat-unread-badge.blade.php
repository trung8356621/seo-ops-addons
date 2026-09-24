@php
    $unreadUrl = auth()->check() ? route('seo.team-messages.unread-count') : '';
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

{{-- Floating Chat/Agent launcher retired from normal/global pages.
     Support Ticket entry is global header (support-ticket-header).
     /seo/{hash}/chat may still mount its own launcher for Agent|Group mode switch. --}}
