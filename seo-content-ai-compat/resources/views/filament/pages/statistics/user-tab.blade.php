@php
    $data = is_array($data ?? null) ? $data : [];
    $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $workloadMax = max(1, (int) ($data['workload_max'] ?? 1));
    $empty = (bool) ($data['empty'] ?? false);
@endphp

@if ($empty)
    <div class="ops-statistics-card ops-statistics-card--empty">
        <p>{{ __('seo-content-ai::filament.statistics.empty_assignments') }}</p>
    </div>
@else
    <div class="ops-statistics__kpi-grid ops-statistics__kpi-grid--4">
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--blue">
                <x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_users_with_work') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['users_with_work'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--purple">
                <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_assigned') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['total_assigned'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--green">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_completed') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['total_completed'] ?? 0)) }}</p>
            </div>
        </div>
        <div class="ops-statistics-kpi">
            <div class="ops-statistics-kpi__icon ops-statistics-kpi__icon--amber">
                <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
            </div>
            <div>
                <p class="ops-statistics-kpi__label">{{ __('seo-content-ai::filament.statistics.kpi_pending') }}</p>
                <p class="ops-statistics-kpi__value">{{ number_format((int) ($kpis['total_pending'] ?? 0)) }}</p>
            </div>
        </div>
    </div>

    <section class="ops-statistics-card">
        <div class="ops-statistics-card__head">
            <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.workload_title') }}</h2>
            <span class="ops-statistics-card__meta">{{ $data['month_label'] ?? '' }}</span>
        </div>
        <ul class="ops-statistics-workload">
            @foreach ($rows as $row)
                @php
                    $assigned = (int) ($row['assigned'] ?? 0);
                    $completed = (int) ($row['completed'] ?? 0);
                    $pending = (int) ($row['pending'] ?? 0);
                    $capacity = max(1, (int) ($row['capacity'] ?? 1));
                    $assignedPct = (int) round(($assigned / $workloadMax) * 100);
                    $completedPct = $assigned > 0 ? (int) round(($completed / $assigned) * 100) : 0;
                @endphp
                <li class="ops-statistics-workload__row">
                    <button
                        type="button"
                        class="ops-statistics-link ops-statistics-workload__name"
                        wire:click="selectUser({{ (int) ($row['user_id'] ?? 0) }})"
                    >
                        {{ $row['name'] ?? '' }}
                    </button>
                    <div class="ops-statistics-workload__bars">
                        <div class="ops-statistics-workload__track" title="{{ __('seo-content-ai::filament.statistics.col_assigned') }}: {{ $assigned }}">
                            <div class="ops-statistics-workload__fill ops-statistics-workload__fill--assigned" style="width: {{ min(100, $assignedPct) }}%"></div>
                        </div>
                        <div class="ops-statistics-workload__track" title="{{ __('seo-content-ai::filament.statistics.col_completed') }}: {{ $completed }}">
                            <div class="ops-statistics-workload__fill ops-statistics-workload__fill--done" style="width: {{ min(100, $completedPct) }}%"></div>
                        </div>
                    </div>
                    <div class="ops-statistics-workload__nums">
                        <span>{{ $assigned }} / {{ $capacity }}</span>
                        <span class="ops-statistics-muted">{{ $pending }} {{ __('seo-content-ai::filament.statistics.pending_short') }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="ops-statistics-card">
        <div class="ops-statistics-card__head">
            <h2 class="ops-statistics-card__title">{{ __('seo-content-ai::filament.statistics.users_table_title') }}</h2>
        </div>
        <div class="ops-statistics-table-wrap">
            <table class="ops-statistics-table">
                <thead>
                    <tr>
                        <th>{{ __('seo-content-ai::filament.statistics.col_user') }}</th>
                        <th>{{ __('seo-content-ai::filament.statistics.col_assigned') }}</th>
                        <th>{{ __('seo-content-ai::filament.statistics.col_completed') }}</th>
                        <th>{{ __('seo-content-ai::filament.statistics.col_pending') }}</th>
                        <th>{{ __('seo-content-ai::filament.statistics.col_progress') }}</th>
                        <th>{{ __('seo-content-ai::filament.statistics.col_capacity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                <button
                                    type="button"
                                    class="ops-statistics-link"
                                    wire:click="selectUser({{ (int) ($row['user_id'] ?? 0) }})"
                                >
                                    {{ $row['name'] ?? '' }}
                                </button>
                            </td>
                            <td>{{ number_format((int) ($row['assigned'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($row['completed'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($row['pending'] ?? 0)) }}</td>
                            <td>
                                <div class="ops-statistics-progress">
                                    <div class="ops-statistics-progress__track">
                                        <div class="ops-statistics-progress__fill" style="width: {{ (int) ($row['progress'] ?? 0) }}%"></div>
                                    </div>
                                    <span>{{ (int) ($row['progress'] ?? 0) }}%</span>
                                </div>
                            </td>
                            <td>{{ number_format((int) ($row['capacity'] ?? 0)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
