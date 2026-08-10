<div class="seo-agent-workspace__plan-card">
    <div class="text-xs font-semibold uppercase text-amber-700 dark:text-amber-300">Knowledge conflict</div>
    <div class="mt-1 text-sm">{{ $structured['summary'] ?? $message['content'] ?? 'Có mâu thuẫn knowledge cần review.' }}</div>
    @foreach ((is_array($structured['item_refs'] ?? null) ? $structured['item_refs'] : []) as $ref)
        <x-seo-content-ai::agent-workspace.action-button
            action="viewKnowledge"
            :value="$ref"
            class="mt-1 block text-xs underline"
        >
            {{ $ref }}
        </x-seo-content-ai::agent-workspace.action-button>
    @endforeach
</div>
