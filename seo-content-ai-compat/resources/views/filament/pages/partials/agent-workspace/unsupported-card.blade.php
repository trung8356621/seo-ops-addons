@php
    $nearest = is_array($structured['nearest_skills'] ?? null) ? $structured['nearest_skills'] : [];
@endphp

<div class="seo-agent-workspace__plan-card">
    <div class="text-sm font-medium">
        {{ $structured['summary'] ?? $message['content'] ?? 'Agent Workspace chưa có skill phù hợp.' }}
    </div>
    @if ($nearest !== [])
        <div class="mt-2 text-xs opacity-70">Các lựa chọn gần nhất:</div>
        <div class="mt-1 flex flex-wrap gap-2">
            @foreach ($nearest as $skill)
                <x-seo-content-ai::agent-workspace.action-button
                    action="selectSkill"
                    :value="$skill['skill_key'] ?? ''"
                    class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700"
                >
                    {{ $skill['name'] ?? ($skill['skill_key'] ?? '') }}
                </x-seo-content-ai::agent-workspace.action-button>
            @endforeach
        </div>
    @endif
</div>
