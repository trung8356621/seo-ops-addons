@php
    $rawTotal = (int) ($total ?? 0);
    $value = (int) ($value ?? 0);
    $denom = max(0, $rawTotal);
    $pct = $denom > 0 ? (int) min(100, max(0, round(($value / $denom) * 100))) : 0;
    $tone = (string) ($tone ?? 'blue');
    $bar = match ($tone) {
        'amber' => 'bg-amber-500',
        'green' => 'bg-emerald-500',
        'purple' => 'bg-violet-500',
        'red' => 'bg-red-500',
        default => 'bg-sky-500',
    };
@endphp

<div>
    <div class="mb-1 flex items-center justify-between gap-3 text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $label ?? '' }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($value) }}/{{ number_format($denom) }} ({{ $pct }}%)</span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
        <div class="h-full rounded-full {{ $bar }}" style="width: {{ $pct }}%"></div>
    </div>
</div>
