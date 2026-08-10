@props([
    'badge' => null,
])

@php
    /** @var array{key?: string, label?: string, classes?: string, icon?: string}|null $badge */
    $badge = is_array($badge) ? $badge : [];
    $key = strtolower((string) ($badge['key'] ?? ''));
    $label = (string) ($badge['label'] ?? '-');
    $baseClasses = 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset';
    $toneClasses = match ($key) {
        'success', 'generated', 'approved',
        'pending', 'draft', 'none',
        'running', 'generating', 'processing', 'publishing',
        'review', 'needs_review', 'in_review',
        'retrying', 'waiting', 'scheduled',
        'published',
        'failed', 'needs_attention',
        'skipped', 'cancelled', 'archived' => '',
        default => (string) ($badge['classes'] ?? 'bg-gray-100 text-gray-700 ring-gray-400/30 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600/40'),
    };
    $classes = $baseClasses.' '.$toneClasses;
    $style = match ($key) {
        'success', 'generated', 'approved' => 'background:#dcfce7;color:#166534;--tw-ring-color:rgb(22 163 74 / 0.35);',
        'pending', 'draft', 'none' => 'background:#fef3c7;color:#92400e;--tw-ring-color:rgb(217 119 6 / 0.35);',
        'running', 'generating', 'processing', 'publishing' => 'background:#e0f2fe;color:#075985;--tw-ring-color:rgb(2 132 199 / 0.35);',
        'review', 'needs_review', 'in_review' => 'background:#ede9fe;color:#5b21b6;--tw-ring-color:rgb(124 58 237 / 0.35);',
        'retrying', 'waiting', 'scheduled' => 'background:#ffedd5;color:#9a3412;--tw-ring-color:rgb(234 88 12 / 0.35);',
        'published' => 'background:#ccfbf1;color:#115e59;--tw-ring-color:rgb(13 148 136 / 0.35);',
        'failed', 'needs_attention' => 'background:#ffe4e6;color:#be123c;--tw-ring-color:rgb(225 29 72 / 0.35);',
        'skipped', 'cancelled', 'archived' => 'background:#e2e8f0;color:#334155;--tw-ring-color:rgb(100 116 139 / 0.35);',
        default => '',
    };
    $icon = (string) ($badge['icon'] ?? '');
@endphp

<span {{ $attributes->class([$classes])->merge(['style' => $style]) }} title="{{ $label }}">
    @if ($icon !== '')
        <x-filament::icon :icon="$icon" class="h-3.5 w-3.5 shrink-0 opacity-90" />
    @endif
    <span>{{ $label }}</span>
</span>
