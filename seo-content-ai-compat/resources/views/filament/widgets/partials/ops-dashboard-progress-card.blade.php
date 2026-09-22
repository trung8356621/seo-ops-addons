@php
    $rows = is_array($rows ?? null) ? $rows : [];
    $hasProjects = (bool) ($hasProjects ?? false);
    $title = (string) ($title ?? '');
    $monthLabel = (string) ($monthLabel ?? '');
    $empty = (string) ($empty ?? '');
    $caption = $caption ?? null;
@endphp

<section @class(['ops-landing-card', 'ops-landing-card--compact-empty' => ! $hasProjects || $rows === []])>
    <div class="ops-landing-card__head">
        <div class="ops-landing-card__title-wrap">
            <x-filament::icon icon="heroicon-o-chart-bar" class="h-4 w-4 text-sky-600" />
            <h3 class="ops-landing-card__title">{{ $title }}</h3>
        </div>
        <span class="ops-landing-card__meta">
            <x-filament::icon icon="heroicon-o-calendar" class="inline h-3.5 w-3.5" />
            {{ __('seo-content-ai::filament.dashboard.ops_month_label', ['month' => $monthLabel]) }}
        </span>
    </div>

    @if (! $hasProjects || $rows === [])
        <p class="ops-landing-card__empty">{{ $empty }}</p>
    @else
        @if (filled($caption))
            <p class="ops-landing-card__empty ops-landing-card__caption">{{ $caption }}</p>
        @endif
        <div class="ops-landing-progress">
            @foreach ($rows as $row)
                @php
                    $tone = (string) ($row['tone'] ?? 'blue');
                    $fill = match ($tone) {
                        'amber' => 'ops-landing-progress__fill--amber',
                        'green' => 'ops-landing-progress__fill--green',
                        'purple' => 'ops-landing-progress__fill--purple',
                        default => 'ops-landing-progress__fill--blue',
                    };
                    $pct = (int) ($row['pct'] ?? 0);
                @endphp
                <div class="ops-landing-progress__row">
                    <span class="ops-landing-progress__label">{{ $row['label'] ?? '' }}</span>
                    <div class="ops-landing-progress__track" aria-hidden="true">
                        <div class="ops-landing-progress__fill {{ $fill }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <span class="ops-landing-progress__ratio">{{ number_format((int) ($row['value'] ?? 0)) }} / {{ number_format((int) ($row['total'] ?? 0)) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</section>
