@php
    $tone = (string) ($tone ?? 'blue');
    $icon = (string) ($icon ?? 'heroicon-o-chart-bar');
    $value = (int) ($value ?? 0);
    $label = (string) ($label ?? '');
    $wrap = match ($tone) {
        'amber' => 'background:#fffbeb;color:#d97706',
        'green' => 'background:#ecfdf5;color:#059669',
        'purple' => 'background:#f5f3ff;color:#7c3aed',
        'red' => 'background:#fef2f2;color:#dc2626',
        default => 'background:#eff6ff;color:#2563eb',
    };
@endphp

<div style="min-width:0;border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;padding:1rem 1.1rem">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem">
        <div style="min-width:0">
            <p style="margin:0;font-size:0.75rem;font-weight:500;color:#6b7280;line-height:1.25">{{ $label }}</p>
            <p style="margin:0.35rem 0 0;font-size:1.75rem;font-weight:700;letter-spacing:-0.02em;color:#111827;line-height:1.1">{{ number_format($value) }}</p>
        </div>
        <span style="display:inline-flex;height:2.25rem;width:2.25rem;flex-shrink:0;align-items:center;justify-content:center;border-radius:0.5rem;{{ $wrap }}">
            <x-filament::icon :icon="$icon" class="h-4 w-4" />
        </span>
    </div>
</div>
