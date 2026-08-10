@php
    $role = $message['role'] ?? 'assistant';
    $type = $message['message_type'] ?? 'text';
    $structured = is_array($message['structured_content'] ?? null) ? $message['structured_content'] : [];
@endphp

<div class="flex {{ $role === 'user' ? 'justify-end' : 'justify-start' }}">
    <div class="max-w-[90%] rounded-2xl px-3 py-2 text-sm {{ $role === 'user' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100' }}">
        @if (! empty($message['content']))
            <div class="whitespace-pre-wrap">{{ $message['content'] }}</div>
        @endif

        @if ($type === 'preview' && $structured !== [])
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

        @if ($type === 'tool_result')
            <div class="mt-2 rounded-lg border border-white/20 bg-white/10 p-2 text-xs dark:border-gray-600">
                <div class="font-semibold">Completed</div>
                @if (! empty($structured['operation_ref']))
                    <div>Operation: {{ $structured['operation_ref'] }}</div>
                @endif
                @foreach (($structured['links'] ?? []) as $link)
                    <div class="mt-1">→ {{ $link['label'] ?? 'Open' }} ({{ $link['ref'] ?? '' }})</div>
                @endforeach
            </div>
        @endif

        @if (in_array($type, ['agent_question', 'agent_clarification'], true))
            @if (! empty($structured['quick_replies']) && is_array($structured['quick_replies']))
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($structured['quick_replies'] as $qr)
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
        @endif

        @if (! empty($structured['choices']))
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($structured['choices'] as $choice)
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

        @if (! empty($structured['plan_steps']))
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs">
                @foreach ($structured['plan_steps'] as $step)
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

        @if (! empty($structured['help_groups']))
            <div class="mt-2 space-y-2">
                @foreach ($structured['help_groups'] as $group)
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
    </div>
</div>
