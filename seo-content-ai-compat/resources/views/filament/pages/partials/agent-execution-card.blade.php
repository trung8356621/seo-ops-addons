@php
    $display = is_array($structured['display'] ?? null) ? $structured['display'] : [];
    $execution = is_array($structured['execution'] ?? null) ? $structured['execution'] : [];
    $rendered = is_array($structured['rendered'] ?? null) ? $structured['rendered'] : [];
    $statusRaw = $structured['status'] ?? $execution['status'] ?? 'unknown';
    $executionRefRaw = $structured['execution_ref'] ?? $execution['execution_ref'] ?? '';
    $status = is_scalar($statusRaw) ? (string) $statusRaw : 'unknown';
    $executionRef = is_scalar($executionRefRaw) ? (string) $executionRefRaw : '';
    $retryable = (bool) (($rendered['details']['retryable'] ?? false) || ($execution['error_category'] ?? '') === 'rate_limited');
    $inputSummary = is_array($structured['input_summary'] ?? null) ? $structured['input_summary'] : [];
    $previewPayload = is_array($structured['preview'] ?? null) ? $structured['preview'] : [];
    $hideEnvelope = (bool) ($rendered['hide_envelope'] ?? false);
    $userFacingSummary = trim((string) ($rendered['summary'] ?? $rendered['body'] ?? ''));
@endphp

@if ($hideEnvelope && in_array($messageType, ['execution_result', 'execution_error'], true))
    <div class="mt-2 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">
        {{ $userFacingSummary !== '' ? $userFacingSummary : 'Hoàn tất.' }}
    </div>
@else
<div class="mt-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-950">
    <div class="flex items-start justify-between gap-2">
        <div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $rendered['title'] ?? ($display['action'] ?? 'Execution') }}
            </div>
            @if ($executionRef !== '' && ! $hideEnvelope)
                <div class="mt-0.5 font-mono text-[11px] text-gray-500">{{ $executionRef }}</div>
            @endif
        </div>
        @if (! $hideEnvelope)
            <span class="seo-agent-workspace__badge">{{ $status }}</span>
        @endif
    </div>

    @if (! empty($rendered['summary']) || ! empty($display['message']))
        <div class="mt-2 whitespace-pre-wrap text-gray-700 dark:text-gray-200">
            {{ $rendered['summary'] ?? ($display['message'] ?? '') }}
        </div>
    @endif

    @if ($messageType === 'execution_confirmation' && $status !== 'failed')
        <div class="mt-3 flex flex-wrap gap-2">
            <x-filament::button
                size="sm"
                color="gray"
                wire:click="answerConversation('no')"
                wire:loading.attr="disabled"
                wire:target="answerConversation"
            >
                No
            </x-filament::button>
            <x-filament::button
                size="sm"
                color="primary"
                wire:click="answerConversation('yes')"
                wire:loading.attr="disabled"
                wire:target="answerConversation"
            >
                Yes
            </x-filament::button>
            <x-filament::button
                size="sm"
                color="gray"
                wire:click="answerConversation('edit')"
                wire:loading.attr="disabled"
                wire:target="answerConversation"
            >
                Sửa
            </x-filament::button>
        </div>
    @endif

    @if ($messageType === 'execution_result' || $messageType === 'execution_error')
        @foreach (($rendered['warnings'] ?? []) as $warning)
            <div class="mt-1 text-xs text-amber-700">⚠ {{ $warning }}</div>
        @endforeach

        @foreach (($rendered['links'] ?? []) as $link)
            <div class="mt-1 text-xs">
                → {{ $link['label'] ?? 'Link' }}
                @if (! empty($link['ref']) && ! $hideEnvelope)
                    <span class="font-mono">({{ $link['ref'] }})</span>
                @endif
            </div>
        @endforeach

        @if ($messageType === 'execution_error' && $retryable && $executionRef !== '')
            <div class="mt-3">
                <x-seo-content-ai::agent-workspace.action-button
                    action="retryExecution"
                    :value="$executionRef"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium dark:border-gray-600 dark:bg-gray-800"
                >
                    Retry
                </x-seo-content-ai::agent-workspace.action-button>
            </div>
        @endif
    @endif

    @if ($messageType === 'execution_plan')
        <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs">
            @foreach (($structured['steps'] ?? []) as $step)
                <li class="{{ ($step['status'] ?? '') === 'locked' ? 'opacity-50' : '' }}">
                    <span class="font-medium">{{ $step['title'] ?? $step['skill_key'] ?? 'Step' }}</span>
                    <span class="seo-agent-workspace__badge">{{ $step['status'] ?? '—' }}</span>
                </li>
            @endforeach
        </ol>
        <div class="mt-2 text-[11px] text-gray-500">Không có Run All — chỉ chạy từng bước.</div>
    @endif

    @if ($messageType === 'execution_preview' || $messageType === 'execution_confirmation')
        @if ($inputSummary !== [])
            <div class="mt-3 text-xs opacity-90">
                <div class="font-semibold">Ảnh hưởng:</div>
                @foreach ($inputSummary as $k => $v)
                    @continue(in_array((string) $k, ['site_ref', 'tenant_ref', 'connection_hash', 'actor_ref'], true))
                    <div><span class="opacity-70">{{ $k }}:</span> {{ is_scalar($v) ? $v : json_encode($v) }}</div>
                @endforeach
            </div>
        @endif

        @if (is_array(($previewPayload['warnings'] ?? null)) && ($previewPayload['warnings'] ?? []) !== [])
            <div class="mt-2 space-y-1 text-xs">
                @foreach (($previewPayload['warnings'] ?? []) as $warning)
                    <div class="text-amber-700">⚠ {{ $warning }}</div>
                @endforeach
            </div>
        @endif
    @endif
</div>
@endif
