@php
    $data = is_array($data ?? null) ? $data : [];
    $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
    $completedChart = is_array($data['completed_chart'] ?? null) ? $data['completed_chart'] : [];
    $scoreChart = is_array($data['score_chart'] ?? null) ? $data['score_chart'] : [];
    $completedRows = is_array($completedChart['rows'] ?? null) ? $completedChart['rows'] : [];
    $scoreRows = is_array($scoreChart['rows'] ?? null) ? $scoreChart['rows'] : [];
    $completedMax = max(1, (int) ($completedChart['max'] ?? 1));
    $empty = (bool) ($data['empty'] ?? false);
@endphp

@if ($empty)
    <div class="ops-statistics-card ops-statistics-card--empty">
        <p>{{ __('seo-content-ai::filament.statistics.empty_review_month') }}</p>
    </div>
@else
    <div class="ops-statistics__kpi-grid ops-statistics__kpi-grid--4">
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--blue">
                <x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_cms_with_work') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['cms_with_work'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--amber">
                <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_waiting_review') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['waiting_review'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--green">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_reviewed_in_month') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['reviewed_in_month'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--purple">
                <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_in_review') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['in_review'] ?? 0)) }}</p>
            </div>
        </div>
    </div>

    <div class="ops-statistics__split ops-statistics__split--user">
        <section class="ops-statistics-card">
            <div class="ops-statistics-card__head ops-statistics-card__head--stacked">
                <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.completed_volume_title') }}</h2>
                <p class="ops-statistics-card__subtitle">{{ __('seo-content-ai::filament.statistics.completed_volume_subtitle') }}</p>
            </div>
            @if (! empty($completedChart['empty']))
                <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.completed_volume_empty') }}</p>
            @else
                <ul class="ops-statistics-hbar">
                    @foreach ($completedRows as $row)
                        @php
                            $completed = (int) ($row['completed'] ?? 0);
                            $pct = (int) round(($completed / $completedMax) * 100);
                        @endphp
                        <li class="ops-statistics-hbar__row">
                            <button
                                type="button"
                                class="ops-statistics-link ops-statistics-hbar__name"
                                wire:click="selectUser({{ (int) ($row['user_id'] ?? 0) }})"
                            >
                                {{ $row['name'] ?? '' }}
                            </button>
                            <div class="ops-statistics-hbar__track">
                                <div class="ops-statistics-hbar__fill" style="width: {{ min(100, $pct) }}%"></div>
                            </div>
                            <span class="ops-statistics-hbar__value">{{ number_format($completed) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="ops-statistics-card">
            <div class="ops-statistics-card__head ops-statistics-card__head--stacked">
                <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.avg_score_title') }}</h2>
                <p class="ops-statistics-card__subtitle">{{ __('seo-content-ai::filament.statistics.avg_score_subtitle') }}</p>
            </div>
            @if (! empty($scoreChart['empty']))
                <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.avg_score_empty') }}</p>
            @else
                <ul class="ops-statistics-scorelist">
                    @foreach ($scoreRows as $row)
                        @php
                            $score = (float) ($row['avg_seo_score'] ?? 0);
                            $barPct = (int) round(min(100, max(0, $score)));
                        @endphp
                        <li class="ops-statistics-scorelist__row">
                            <button
                                type="button"
                                class="ops-statistics-link ops-statistics-scorelist__name"
                                wire:click="selectUser({{ (int) ($row['user_id'] ?? 0) }})"
                            >
                                {{ $row['name'] ?? '' }}
                            </button>
                            <div class="ops-statistics-scorelist__bar-wrap">
                                <div class="ops-statistics-scorelist__track">
                                    <div class="ops-statistics-scorelist__fill" style="width: {{ $barPct }}%"></div>
                                </div>
                                <strong class="ops-statistics-scorelist__value">{{ number_format($score, 0) }}</strong>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endif
