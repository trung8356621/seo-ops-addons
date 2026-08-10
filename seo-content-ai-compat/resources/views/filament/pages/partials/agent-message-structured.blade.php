@php
    $type = (string) ($message['message_type'] ?? 'text');
    $structured = is_array($message['structured_content'] ?? null) ? $message['structured_content'] : [];
    $role = (string) ($message['role'] ?? 'assistant');

    // User bubbles must stay morph-marker free — Livewire @if comments + pre-wrap
    // previously stretched the bubble into a tall thin strip.
    $partial = null;
    if ($role !== 'user') {
        $partial = match (true) {
            $type === 'memory_proposal' => 'seo-content-ai::filament.pages.partials.agent-workspace.memory-proposal-card',
            $type === 'knowledge_conflict' => 'seo-content-ai::filament.pages.partials.agent-workspace.knowledge-conflict-card',
            $type === 'planning_status' => 'seo-content-ai::filament.pages.partials.agent-workspace.planning-status',
            $type === 'proposed_intent' => 'seo-content-ai::filament.pages.partials.agent-workspace.proposed-intent',
            $type === 'proposed_plan' => 'seo-content-ai::filament.pages.partials.agent-workspace.proposed-plan',
            $type === 'clarification' => 'seo-content-ai::filament.pages.partials.agent-workspace.clarification-card',
            in_array($type, ['unsupported', 'assistant_answer'], true) => 'seo-content-ai::filament.pages.partials.agent-workspace.unsupported-card',
            in_array($type, ['execution_preview', 'execution_confirmation', 'execution_result', 'execution_error', 'execution_plan'], true)
                => 'seo-content-ai::filament.pages.partials.agent-execution-card',
            default => null,
        };
    }
@endphp

@if ($partial === 'seo-content-ai::filament.pages.partials.agent-workspace.unsupported-card')
    @include($partial, [
        'message' => $message,
        'structured' => $type === 'assistant_answer'
            ? array_merge($structured, ['summary' => $message['content'] ?? ($structured['summary'] ?? '')])
            : $structured,
    ])
@elseif ($partial === 'seo-content-ai::filament.pages.partials.agent-execution-card')
    @include($partial, [
        'messageType' => $type,
        'structured' => $structured,
    ])
@elseif ($partial !== null)
    @include($partial, [
        'message' => $message,
        'structured' => $structured,
    ])
@endif

@php
    $showPreviewDetails = $role !== 'user' && $type === 'preview' && $structured !== [];
    $showToolResult = $role !== 'user' && $type === 'tool_result';
    $choices = ($role !== 'user' && ! empty($structured['choices']) && is_array($structured['choices']))
        ? $structured['choices']
        : [];
    $planSteps = ($role !== 'user' && ! empty($structured['plan_steps']) && is_array($structured['plan_steps']))
        ? $structured['plan_steps']
        : [];
    $helpGroups = ($role !== 'user' && ! empty($structured['help_groups']) && is_array($structured['help_groups']))
        ? $structured['help_groups']
        : [];
    $quickReplies = ($role !== 'user'
        && in_array($type, ['agent_question', 'agent_clarification'], true)
        && ! empty($structured['quick_replies'])
        && is_array($structured['quick_replies']))
        ? $structured['quick_replies']
        : [];
@endphp

@if ($showPreviewDetails)
    <div class="mt-2 space-y-1 text-xs opacity-90">
        <div class="font-semibold">Preview</div>
        @foreach (($structured['input_summary'] ?? []) as $k => $v)
            <div><span class="opacity-70">{{ $k }}:</span> {{ is_scalar($v) ? $v : json_encode($v) }}</div>
        @endforeach
        @foreach (($structured['result']['warnings'] ?? []) as $warning)
            <div class="text-amber-700 dark:text-amber-300">⚠ {{ $warning }}</div>
        @endforeach
    </div>
@endif

@if ($showToolResult)
    <div class="mt-2 rounded-lg border border-black/10 bg-black/5 p-2 text-xs dark:border-white/20 dark:bg-white/10">
        <div class="font-semibold">Completed</div>
        @if (! empty($structured['operation_ref']))
            <div>Operation: {{ $structured['operation_ref'] }}</div>
        @endif
        @foreach (($structured['links'] ?? []) as $link)
            <div class="mt-1">→ {{ $link['label'] ?? 'Open' }} ({{ $link['ref'] ?? '' }})</div>
        @endforeach
    </div>
@endif

@if ($quickReplies !== [])
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach ($quickReplies as $qr)
            @php
                $qrValue = (string) ($qr['value'] ?? '');
                $qrLabel = (string) ($qr['label'] ?? $qrValue);
            @endphp
            @if ($qrValue !== '')
                <x-filament::button
                    size="sm"
                    color="gray"
                    wire:click="answerConversation('{{ $qrValue }}')"
                    wire:loading.attr="disabled"
                    wire:target="answerConversation"
                    type="button"
                >
                    {{ $qrLabel }}
                </x-filament::button>
            @endif
        @endforeach
    </div>
@endif

@if ($choices !== [])
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach ($choices as $choice)
            <x-seo-content-ai::agent-workspace.action-button
                action="selectSkill"
                :value="$choice['skill_key'] ?? ''"
                class="rounded-lg bg-white px-2 py-1 text-xs font-medium text-gray-800 dark:bg-gray-700 dark:text-white"
            >
                {{ $choice['name'] }}
            </x-seo-content-ai::agent-workspace.action-button>
        @endforeach
    </div>
@endif

@if ($planSteps !== [])
    <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs">
        @foreach ($planSteps as $step)
            <li>
                <x-seo-content-ai::agent-workspace.action-button
                    action="selectSkill"
                    :value="$step['skill_key'] ?? ''"
                    class="underline"
                >
                    {{ $step['title'] }}
                </x-seo-content-ai::agent-workspace.action-button>
            </li>
        @endforeach
    </ol>
@endif

@if ($helpGroups !== [])
    <div class="mt-2 space-y-2">
        @foreach ($helpGroups as $group)
            <div>
                <div class="text-xs font-semibold uppercase opacity-70">{{ $group['group'] }}</div>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach ($group['items'] as $item)
                        <x-seo-content-ai::agent-workspace.action-button
                            action="selectSkill"
                            :value="$item['skill_key'] ?? ''"
                            class="rounded-lg bg-white px-2 py-1 text-xs text-gray-800 dark:bg-gray-700 dark:text-white"
                        >
                            {{ $item['name'] }}
                        </x-seo-content-ai::agent-workspace.action-button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
