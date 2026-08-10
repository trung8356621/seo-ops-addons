@php
    $proposal = is_array($structured['proposal'] ?? null) ? $structured['proposal'] : $structured;
    $proposalId = (string) ($proposal['hash_id'] ?? '');
@endphp

<div class="seo-agent-workspace__plan-card">
    <div class="text-xs font-semibold uppercase opacity-70">Lưu vào Knowledge?</div>
    <div class="mt-1 text-sm font-medium">{{ $proposal['title'] ?? '' }}</div>
    <div class="mt-1 text-xs opacity-80 whitespace-pre-wrap">{{ mb_substr((string) ($proposal['content'] ?? ''), 0, 400) }}</div>
    <div class="mt-1 text-xs opacity-60">
        Scope: {{ $proposal['proposed_scope_type'] ?? 'site' }}
        · Type: {{ $proposal['proposed_type'] ?? 'general_note' }}
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <x-seo-content-ai::agent-workspace.action-button
            action="resolveMemoryProposal"
            :value="$proposalId"
            decision="save"
            class="rounded-lg bg-primary-600 px-2 py-1 text-xs text-white"
        >
            Save
        </x-seo-content-ai::agent-workspace.action-button>
        <x-seo-content-ai::agent-workspace.action-button
            action="resolveMemoryProposal"
            :value="$proposalId"
            decision="keep_conversation_only"
            class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700"
        >
            Keep conversation only
        </x-seo-content-ai::agent-workspace.action-button>
        <x-seo-content-ai::agent-workspace.action-button
            action="resolveMemoryProposal"
            :value="$proposalId"
            decision="reject"
            class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700"
        >
            Reject
        </x-seo-content-ai::agent-workspace.action-button>
    </div>
    <p class="mt-2 text-xs opacity-60">Không tự lưu — cần bạn duyệt.</p>
</div>
