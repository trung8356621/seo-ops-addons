<x-filament-panels::page full-height>
    @php
        $tab = $chatTab ?? 'group';
        $hash = is_string(request()->route('connection_hash'))
            ? (string) request()->route('connection_hash')
            : (string) (session('seo_current_connection_hash') ?? '');
        $groupChatProps = [
            'messagesUrl' => route('seo.team-messages.index'),
            'storeUrl' => route('seo.team-messages.store'),
            'markReadUrl' => route('seo.team-messages.mark-read'),
            'unreadUrl' => route('seo.team-messages.unread-count'),
            'configUrl' => route('seo.team-messages.config'),
            'csrfToken' => csrf_token(),
            'pollIntervalMs' => 15000,
            'currentUserId' => (int) auth()->id(),
            'accept' => implode(',', array_map(
                static fn (string $ext): string => '.'.$ext,
                app(\Omnichannel\Addons\Content\Services\TeamChatAttachmentService::class)->clientConfig()['allowed_extensions'] ?? [],
            )),
            'maxFileSizeBytes' => app(\Omnichannel\Addons\Content\Services\TeamChatAttachmentService::class)->clientConfig()['max_file_size_bytes'] ?? (5 * 1024 * 1024),
        ];
        $ticketChatConfig = app(\App\Services\SupportTickets\SupportTicketAttachmentService::class)->clientConfig();
        $ticketProps = [
            'indexUrl' => route('support-tickets.index'),
            'storeUrl' => route('support-tickets.store'),
            'csrfToken' => csrf_token(),
            'pageUrl' => url()->current(),
            'connectionHash' => $hash,
            'service' => 'seo',
            'accept' => implode(',', array_map(
                static fn (string $ext): string => '.'.$ext,
                $ticketChatConfig['allowed_extensions'] ?? [],
            )),
            'maxFileSizeBytes' => $ticketChatConfig['max_file_size_bytes'] ?? (5 * 1024 * 1024),
        ];
    @endphp

    {{-- Mode content only — no horizontal tabs. Round launcher switches ?tab= --}}
    <div class="seo-chat-workspace flex h-full min-h-0 flex-col">
        <div class="seo-chat-workspace__body min-h-0 flex-1 overflow-hidden">
            @if ($tab === 'agent')
                <div class="flex h-full items-center justify-center p-6 text-sm text-gray-600 dark:text-gray-300">
                    Agent Workspace đã được tách khỏi runtime hiện tại (reference-only). Dùng Group Chat hoặc Support Ticket.
                </div>
            @elseif ($tab === 'group')
                @vite(['addons/content/resources/js/chat/groupChatApp.js', 'addons/ai-prompt/resources/css/global-ai-chat.css'])
                <div
                    id="seo-group-chat-root"
                    class="h-full min-h-0"
                    data-props='@json($groupChatProps)'
                ></div>
            @else
                @vite(['addons/content/resources/js/chat/ticketPanel.js', 'addons/ai-prompt/resources/css/global-ai-chat.css'])
                <div
                    id="seo-ticket-panel-root"
                    class="h-full min-h-0 overflow-auto p-1"
                    data-props='@json($ticketProps)'
                ></div>
            @endif
        </div>
    </div>

    <x-seo-content-ai::chat-mode-launcher :active-tab="$tab" />
</x-filament-panels::page>
