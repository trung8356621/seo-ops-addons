@php
    $tone = (string) ($tone ?? 'blue');
    $icon = (string) ($icon ?? 'heroicon-o-chart-bar');
    $value = (int) ($value ?? 0);
    $label = (string) ($label ?? '');
    $meta = $meta ?? null;
    $iconTone = match ($tone) {
        'amber' => 'ops-landing-kpi__icon--amber',
        'green' => 'ops-landing-kpi__icon--green',
        'purple' => 'ops-landing-kpi__icon--purple',
        'red' => 'ops-landing-kpi__icon--red',
        default => 'ops-landing-kpi__icon--blue',
    };
@endphp

<article class="ops-landing-kpi">
    <span class="ops-landing-kpi__icon {{ $iconTone }}">
        <x-filament::icon :icon="$icon" class="h-5 w-5" />
    </span>
    <div class="ops-landing-kpi__body">
        <p class="ops-landing-kpi__label">{{ $label }}</p>
        <p class="ops-landing-kpi__value">{{ number_format($value) }}</p>
        @if (filled($meta))
            <p class="ops-landing-kpi__meta">{{ $meta }}</p>
        @endif
    </div>
</article>
