<div class="space-y-2">
    <x-filament::button size="sm" color="gray" class="w-full" wire:click="createConversation">
        + {{ __('seo-content-ai::filament.agent_workspace.new_chat') }}
    </x-filament::button>

    <div class="space-y-1">
        @foreach ($conversations as $row)
            <div
                class="group flex items-start justify-between gap-1 rounded-lg px-2 py-2 text-sm {{ ($conversationRef ?? '') === $row['public_ref'] ? 'bg-primary-50 text-primary-900 dark:bg-primary-950/40 dark:text-primary-100' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}"
            >
                <x-seo-content-ai::agent-workspace.action-button
                    action="selectConversation"
                    :value="$row['public_ref']"
                    class="min-w-0 flex-1 text-left"
                >
                    <div class="truncate font-medium">
                        @if ($row['is_pinned'])📌 @endif
                        {{ $row['title'] ?: 'Chat' }}
                    </div>
                    <div class="truncate text-[11px] text-gray-500">{{ $row['last_message_at'] ?? '' }}</div>
                </x-seo-content-ai::agent-workspace.action-button>
                <div class="flex gap-1 opacity-0 group-hover:opacity-100">
                    <x-seo-content-ai::agent-workspace.action-button
                        action="pinConversation"
                        :value="$row['public_ref']"
                        class="text-xs text-gray-500"
                        title="Pin"
                    >P</x-seo-content-ai::agent-workspace.action-button>
                    <x-seo-content-ai::agent-workspace.action-button
                        action="archiveConversation"
                        :value="$row['public_ref']"
                        class="text-xs text-gray-500"
                        title="Archive"
                    >A</x-seo-content-ai::agent-workspace.action-button>
                </div>
            </div>
        @endforeach
    </div>
</div>
