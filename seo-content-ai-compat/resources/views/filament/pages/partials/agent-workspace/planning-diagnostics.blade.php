@php
    $diag = is_array($diagnostics ?? null) ? $diagnostics : [];
@endphp

@if ($diag !== [])
    <div class="seo-agent-workspace__plan-card text-xs">
        <div class="font-semibold uppercase opacity-70">Planning diagnostics</div>
        <dl class="mt-2 grid grid-cols-2 gap-1">
            @foreach ([
                'planning_request_id' => 'Request ID',
                'provider' => 'Provider',
                'model' => 'Model',
                'routing_reason' => 'Routing',
                'input_token_estimate' => 'Input tokens',
                'output_token_estimate' => 'Output tokens',
                'confidence' => 'Model confidence',
                'adjusted_confidence' => 'Adjusted confidence',
                'response_type' => 'Response type',
                'latency_ms' => 'Latency ms',
                'prompt_fingerprint' => 'Prompt fingerprint',
                'error_category' => 'Error',
            ] as $key => $label)
                @if (! empty($diag[$key]) || (isset($diag[$key]) && $diag[$key] === 0))
                    <dt class="opacity-60">{{ $label }}</dt>
                    <dd class="break-all">{{ is_scalar($diag[$key]) ? $diag[$key] : json_encode($diag[$key]) }}</dd>
                @endif
            @endforeach
        </dl>
        @if (! empty($diag['validation_errors']) && is_array($diag['validation_errors']))
            <div class="mt-2 text-amber-700 dark:text-amber-300">
                Validation: {{ implode(', ', $diag['validation_errors']) }}
            </div>
        @endif
        @if (! empty($diag['repair_actions']) && is_array($diag['repair_actions']))
            <div class="mt-1 opacity-70">Repair: {{ implode(', ', $diag['repair_actions']) }}</div>
        @endif
    </div>
@endif
