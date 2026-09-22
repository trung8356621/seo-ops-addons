@php
    $data = is_array($data ?? null) ? $data : [];
    $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
    $distribution = is_array($data['distribution'] ?? null) ? $data['distribution'] : [];
    $segments = is_array($distribution['segments'] ?? null) ? $distribution['segments'] : [];
    $chart = is_array($data['chart'] ?? null) ? $data['chart'] : [];
    $points = is_array($chart['points'] ?? null) ? $chart['points'] : [];
    $maxVolume = max(1, (int) ($chart['max_volume'] ?? 1));
    $domains = is_array($data['domains'] ?? null) ? $data['domains'] : [];
    $urgent = is_array($data['urgent'] ?? null) ? $data['urgent'] : [];
    $insights = is_array($data['insights'] ?? null) ? $data['insights'] : [];
    $compare = (bool) ($data['compare'] ?? false);
    $empty = (bool) ($data['empty'] ?? false);
@endphp

@if ($empty)
    <div class="ops-statistics-card ops-statistics-card--empty">
        <p>{{ __('seo-content-ai::filament.statistics.empty_period') }}</p>
    </div>
@else
    <div class="ops-statistics__kpi-grid ops-statistics__kpi-grid--4">
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--blue">
                <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_total_articles') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['total_articles'] ?? 0)) }}</p>
                <p class="ops-statistics-kpi__meta">{{ __('seo-content-ai::filament.statistics.kpi_inventory_hint') }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--green">
                <x-filament::icon icon="heroicon-o-chart-bar" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_avg_score') }}</p>
                <p class="ops-statistics-kpi__value">
                    @if (($kpis['avg_seo_score'] ?? null) === null)
                        —
                    @else
                        {{ number_format((float) $kpis['avg_seo_score'], 1) }}
                    @endif
                </p>
                <p class="ops-statistics-kpi__meta">{{ __('seo-content-ai::filament.statistics.kpi_scored_hint', ['count' => (int) ($kpis['scored_articles'] ?? 0)]) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--amber">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_needs_optimize') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['needs_optimize'] ?? 0)) }}</p>
                <p class="ops-statistics-kpi__meta">{{ __('seo-content-ai::filament.statistics.kpi_optimize_hint') }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--red">
                <x-filament::icon icon="heroicon-o-signal" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_sync_index') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['sync_index_issues'] ?? 0)) }}</p>
                <p class="ops-statistics-kpi__meta">{{ __('seo-content-ai::filament.statistics.kpi_sync_hint') }}</p>
            </div>
        </div>
    </div>

    <div class="ops-statistics__split">
        <section class="ops-statistics-card">
            <div class="ops-statistics-card__head">
                <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.chart_title') }}</h2>
                <span class="ops-statistics-card__meta">{{ $data['month_label'] ?? '' }}</span>
            </div>
            @if (! empty($chart['empty']))
                <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.chart_empty') }}</p>
            @else
                <div class="ops-statistics-chart" aria-label="{{ __('seo-content-ai::filament.statistics.chart_title') }}">
                    @foreach ($points as $point)
                        @php
                            $h = (int) round((((int) ($point['volume'] ?? 0)) / $maxVolume) * 100);
                        @endphp
                        <div class="ops-statistics-chart__col" title="{{ ($point['day'] ?? '').' · '.(int) ($point['volume'] ?? 0) }}">
                            <div class="ops-statistics-chart__bar-wrap">
                                @if (($point['avg_score'] ?? null) !== null)
                                    <span
                                        class="ops-statistics-chart__score-dot"
                                        style="bottom: {{ min(100, max(0, (float) $point['avg_score'])) }}%"
                                    ></span>
                                @endif
                                <div class="ops-statistics-chart__bar" style="height: {{ $h }}%"></div>
                            </div>
                            @if (((int) ($point['label'] ?? 0) % 5) === 1 || (int) ($point['label'] ?? 0) === (int) count($points))
                                <span class="ops-statistics-chart__label">{{ $point['label'] ?? '' }}</span>
                            @else
                                <span class="ops-statistics-chart__label ops-statistics-chart__label--tick"></span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="ops-statistics-chart__legend">
                    <span><i class="ops-statistics-chart__legend-bar"></i> {{ __('seo-content-ai::filament.statistics.chart_volume') }}</span>
                    <span><i class="ops-statistics-chart__legend-line"></i> {{ __('seo-content-ai::filament.statistics.chart_avg_score') }}</span>
                </div>
            @endif
        </section>

        <section class="ops-statistics-card">
            <div class="ops-statistics-card__head">
                <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.distribution_title') }}</h2>
            </div>
            @if ((int) ($distribution['scored'] ?? 0) === 0)
                <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.empty_scores') }}</p>
            @else
                @php $scoredTotal = max(1, (int) $distribution['scored']); @endphp
                <ul class="ops-statistics-dist">
                    @foreach ($segments as $segment)
                        @php
                            $count = (int) ($segment['count'] ?? 0);
                            $pct = (int) round(($count / $scoredTotal) * 100);
                        @endphp
                        <li class="ops-statistics-dist__row">
                            <div class="ops-statistics-dist__meta">
                                <span>{{ $segment['label'] ?? '' }}</span>
                                <strong>{{ number_format($count) }}</strong>
                            </div>
                            <div class="ops-statistics-dist__track">
                                <div
                                    class="ops-statistics-dist__fill"
                                    style="width: {{ $pct }}%; background: {{ $segment['color'] ?? '#94a3b8' }}"
                                ></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="ops-statistics__split">
        <section class="ops-statistics-card">
            <div class="ops-statistics-card__head">
                <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.domains_title') }}</h2>
            </div>
            @if ($domains === [])
                <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.empty_period') }}</p>
            @else
                <div class="ops-statistics-table-wrap">
                    <table class="ops-statistics-table">
                        <thead>
                            <tr>
                                <th>{{ __('seo-content-ai::filament.statistics.col_domain') }}</th>
                                <th>{{ __('seo-content-ai::filament.statistics.col_total') }}</th>
                                <th>{{ __('seo-content-ai::filament.statistics.col_avg') }}</th>
                                <th>{{ __('seo-content-ai::filament.statistics.col_needs_optimize') }}</th>
                                @if ($compare)
                                    <th>{{ __('seo-content-ai::filament.statistics.col_trend') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domains as $row)
                                <tr>
                                    <td>
                                        <button
                                            type="button"
                                            class="ops-statistics-link"
                                            wire:click="selectDomain({{ (int) ($row['site_id'] ?? 0) }})"
                                        >
                                            {{ $row['domain'] ?? '' }}
                                        </button>
                                    </td>
                                    <td>{{ number_format((int) ($row['total_articles'] ?? 0)) }}</td>
                                    <td>
                                        @if (($row['avg_seo_score'] ?? null) === null)
                                            —
                                        @else
                                            {{ number_format((float) $row['avg_seo_score'], 1) }}
                                        @endif
                                    </td>
                                    <td>{{ number_format((int) ($row['needs_optimize'] ?? 0)) }}</td>
                                    @if ($compare)
                                        <td>
                                            @if (($row['trend'] ?? null) === null)
                                                —
                                            @else
                                                @php $trend = (float) $row['trend']; @endphp
                                                <span class="ops-statistics-trend {{ $trend >= 0 ? 'is-up' : 'is-down' }}">
                                                    {{ $trend >= 0 ? '+' : '' }}{{ number_format($trend, 1) }}
                                                </span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="ops-statistics__stack">
            <section class="ops-statistics-card">
                <div class="ops-statistics-card__head">
                    <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.urgent_title') }}</h2>
                </div>
                @if ($urgent === [])
                    <p class="ops-statistics-card__empty">{{ __('seo-content-ai::filament.statistics.urgent_empty') }}</p>
                @else
                    <ul class="ops-statistics-urgent">
                        @foreach ($urgent as $item)
                            <li>
                                <a href="{{ $item['edit_url'] ?? '#' }}" class="ops-statistics-urgent__title">{{ $item['title'] ?? '' }}</a>
                                <div class="ops-statistics-urgent__meta">
                                    <span>{{ $item['domain'] ?? '' }}</span>
                                    <strong>{{ number_format((float) ($item['score'] ?? 0), 1) }}</strong>
                                    <span>{{ $item['updated_at'] ?? '' }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($insights !== [])
                <section class="ops-statistics-card">
                    <div class="ops-statistics-card__head">
                        <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.insights_title') }}</h2>
                    </div>
                    <ul class="ops-statistics-insights">
                        @foreach ($insights as $insight)
                            <li class="ops-statistics-insights__item ops-statistics-insights__item--{{ $insight['tone'] ?? 'info' }}">
                                {{ $insight['text'] ?? '' }}
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
@endif
