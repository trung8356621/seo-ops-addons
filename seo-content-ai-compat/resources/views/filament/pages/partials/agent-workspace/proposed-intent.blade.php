@php
    $skillKey = (string) ($structured['skill_key'] ?? '');
    $missing = is_array($structured['missing_fields'] ?? null) ? $structured['missing_fields'] : [];
    $assumptions = is_array($structured['assumptions'] ?? null) ? $structured['assumptions'] : [];
    $warnings = is_array($structured['warnings'] ?? null) ? $structured['warnings'] : [];
    $uncertain = (bool) ($structured['uncertain'] ?? false);
@endphp

<div class="seo-agent-workspace__plan-card">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-70">
        {{ $uncertain ? 'Đề xuất chưa chắc chắn' : 'Tôi hiểu bạn muốn' }}
    </div>
    <div class="mt-1 text-sm font-medium">{{ $structured['summary'] ?? $message['content'] ?? '' }}</div>
    @if ($skillKey !== '')
        <div class="mt-1 text-xs opacity-80">Skill: {{ $structured['skill_name'] ?? $skillKey }}</div>
    @endif
    @if ($missing !== [])
        <div class="mt-2 text-xs">
            <div class="font-semibold">Còn thiếu:</div>
            <ul class="list-disc pl-4">
                @foreach ($missing as $field)
                    <li>{{ $field }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @foreach ($assumptions as $assumption)
        <div class="mt-1 text-xs opacity-70">Giả định: {{ $assumption }}</div>
    @endforeach
    @foreach ($warnings as $warning)
        <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $warning }}</div>
    @endforeach
    <div class="mt-3 flex flex-wrap gap-2">
        @if ($skillKey !== '')
            <x-seo-content-ai::agent-workspace.action-button
                action="selectSkill"
                :value="$skillKey"
                class="rounded-lg bg-primary-600 px-2 py-1 text-xs font-medium text-white"
                wire:loading.attr="disabled"
                wire:target="selectSkill"
            >
                Bắt đầu nhập
            </x-seo-content-ai::agent-workspace.action-button>
        @endif
        <button type="button" class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700" wire:click="cancelProposedPlan">
            Hủy
        </button>
    </div>
</div>
