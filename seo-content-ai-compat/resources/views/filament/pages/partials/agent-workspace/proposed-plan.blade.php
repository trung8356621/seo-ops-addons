@php
    $steps = is_array($structured['steps'] ?? null) ? $structured['steps'] : [];
    $uncertain = (bool) ($structured['uncertain'] ?? false);
@endphp

<div class="seo-agent-workspace__plan-card">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-70">
        {{ $uncertain ? 'Kế hoạch đề xuất (cần xác nhận ý định)' : 'Kế hoạch đề xuất' }}
    </div>
    <div class="mt-1 text-sm">{{ $structured['summary'] ?? $message['content'] ?? '' }}</div>
    <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs">
        @foreach ($steps as $step)
            <li>
                {{ $step['title'] ?? ($step['skill_key'] ?? 'step') }}
                @if (($step['available'] ?? true) === false)
                    <span class="seo-agent-workspace__badge is-warn">unavailable</span>
                @endif
            </li>
        @endforeach
    </ol>
    <p class="mt-2 text-xs opacity-70">Kế hoạch sẽ không tự chạy. Bạn sẽ duyệt từng bước.</p>
    <div class="mt-3 flex flex-wrap gap-2">
        <button
            type="button"
            class="rounded-lg bg-primary-600 px-2 py-1 text-xs font-medium text-white"
            wire:click="saveProposedPlan"
            wire:loading.attr="disabled"
            wire:target="saveProposedPlan"
        >
            <span wire:loading.remove wire:target="saveProposedPlan">Lưu kế hoạch</span>
            <span wire:loading wire:target="saveProposedPlan">Đang lưu…</span>
        </button>
        <button type="button" class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700" wire:click="cancelProposedPlan">
            Hủy
        </button>
    </div>
</div>
