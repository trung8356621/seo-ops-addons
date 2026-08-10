{{-- Planning status — instant feedback, no chain-of-thought --}}
<div class="seo-agent-workspace__plan-card is-status" wire:key="planning-status-{{ $message['public_ref'] ?? 'x' }}">
    <div class="flex items-center gap-2 text-sm">
        <svg class="h-4 w-4 animate-spin opacity-70" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <span>{{ $structured['summary'] ?? $message['content'] ?? 'Đang phân tích yêu cầu…' }}</span>
    </div>
</div>
