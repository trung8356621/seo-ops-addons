@props([
    'row' => [],
])

@php
    $mode = strtoupper(trim((string) ($row['ai_mode'] ?? '')));
    $label = trim((string) ($row['ai_mode_label'] ?? ''));
    if ($label === '') {
        $label = '—';
    }
@endphp

@if ($mode === 'FREE' || $mode === 'PAID' || $mode === 'MIXED')
    <span @class([
        'cp-ops-ai-mode',
        'cp-ops-ai-mode--free' => $mode === 'FREE',
        'cp-ops-ai-mode--paid' => $mode === 'PAID',
        'cp-ops-ai-mode--mixed' => $mode === 'MIXED',
    ])>{{ $label }}</span>
@else
    <span class="cp-ops-ai-mode cp-ops-ai-mode--empty">—</span>
@endif
