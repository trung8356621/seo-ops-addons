@props([
    'domainChart' => [],
    'writerChart' => [],
    'domainEmptyKey' => 'seo-content-ai::filament.projects.chart_domain_empty',
    'writerEmptyKey' => 'seo-content-ai::filament.projects.chart_writer_empty',
])

@php
    $domainChart = is_array($domainChart) ? $domainChart : [];
    $writerChart = is_array($writerChart) ? $writerChart : [];
    $domainTotal = (int) ($domainChart['total'] ?? 0);
    $writerTotal = (int) ($writerChart['total'] ?? 0);
    $teamCapacity = (int) ($writerChart['team_capacity'] ?? 0);
    $overallPct = (int) ($writerChart['overall_progress_pct'] ?? 0);
    $writerCount = (int) ($writerChart['writer_count'] ?? 0);
@endphp

@once
    <style>
        /* Scoped — do not rely on Tailwind arbitrary grid classes (often missing from Filament CSS). */
        .cp-month-charts {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: stretch;
        }
        @media (min-width: 1024px) {
            .cp-month-charts {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            }
        }
        .cp-month-charts__card {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            padding: 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
            background: #fff;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
        }
        .dark .cp-month-charts__card {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-month-charts__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .cp-month-charts__title {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 600;
            color: rgb(17 24 39);
        }
        .dark .cp-month-charts__title { color: #fff; }
        .cp-month-charts__sub {
            margin: 0.125rem 0 0;
            font-size: 0.75rem;
            color: rgb(107 114 128);
        }
        .cp-month-charts__body {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 0.875rem;
            min-width: 0;
            align-items: center;
        }
        @media (min-width: 640px) {
            .cp-month-charts__body {
                flex-direction: row;
                align-items: center;
            }
        }
        .cp-month-charts__donut {
            position: relative;
            width: 9rem;
            height: 9rem;
            flex: 0 0 9rem;
            border-radius: 9999px;
        }
        .cp-month-charts__donut-hole {
            position: absolute;
            inset: 22%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: #fff;
            text-align: center;
            padding: 0.25rem;
        }
        .dark .cp-month-charts__donut-hole { background: rgb(17 24 39); }
        .cp-month-charts__donut-kicker {
            font-size: 0.625rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgb(156 163 175);
            line-height: 1.1;
        }
        .cp-month-charts__donut-value {
            font-size: 1.25rem;
            font-weight: 600;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
            color: rgb(17 24 39);
        }
        .dark .cp-month-charts__donut-value { color: #fff; }
        .cp-month-charts__donut-value--accent {
            color: rgb(5 150 105);
            font-size: 1.5rem;
        }
        .dark .cp-month-charts__donut-value--accent { color: rgb(52 211 153); }
        .cp-month-charts__donut-meta {
            font-size: 0.625rem;
            color: rgb(107 114 128);
            line-height: 1.2;
        }
        .cp-month-charts__side {
            flex: 1 1 auto;
            min-width: 0;
            width: 100%;
        }
        .cp-month-charts__rank {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .cp-month-charts__rank-row {
            min-width: 0;
        }
        .cp-month-charts__rank-top {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            margin-bottom: 0.2rem;
        }
        .cp-month-charts__badge {
            display: inline-flex;
            width: 1.25rem;
            height: 1.25rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            font-size: 0.625rem;
            font-weight: 600;
            color: #fff;
        }
        .cp-month-charts__domain {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
            color: rgb(31 41 55);
        }
        .dark .cp-month-charts__domain { color: rgb(243 244 246); }
        .cp-month-charts__count {
            flex: 0 0 auto;
            font-variant-numeric: tabular-nums;
            color: rgb(75 85 99);
        }
        .dark .cp-month-charts__count { color: rgb(209 213 219); }
        .cp-month-charts__bar-track {
            margin-left: 1.75rem;
            height: 0.375rem;
            max-width: 11rem;
            overflow: hidden;
            border-radius: 9999px;
            background: rgb(243 244 246);
        }
        .dark .cp-month-charts__bar-track { background: rgb(31 41 55); }
        .cp-month-charts__bar-fill {
            height: 100%;
            border-radius: 9999px;
        }
        .cp-month-charts__writer-summary {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 0 0 auto;
            width: 9rem;
        }
        .cp-month-charts__writer-summary-text {
            margin-top: 0.5rem;
            text-align: center;
        }
        .cp-month-charts__writer-table-head,
        .cp-month-charts__writer-row {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(4.25rem, 0.85fr) minmax(3.25rem, 0.65fr);
            gap: 0.5rem;
            align-items: center;
        }
        .cp-month-charts__writer-table-head {
            margin-bottom: 0.25rem;
            font-size: 0.625rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgb(156 163 175);
        }
        .cp-month-charts__writer-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .cp-month-charts__writer-row {
            height: 2rem;
            font-size: 0.75rem;
            border-top: 1px solid rgb(243 244 246);
        }
        .dark .cp-month-charts__writer-row { border-top-color: rgb(31 41 55); }
        .cp-month-charts__writer-list > li:first-child .cp-month-charts__writer-row {
            border-top: 0;
        }
        .cp-month-charts__writer-name {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            min-width: 0;
        }
        .cp-month-charts__avatar {
            display: inline-flex;
            width: 1.25rem;
            height: 1.25rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            font-size: 0.5625rem;
            font-weight: 600;
            background: rgb(236 253 245);
            color: rgb(4 120 87);
        }
        .dark .cp-month-charts__avatar {
            background: rgb(6 95 70 / 0.25);
            color: rgb(110 231 183);
        }
        .cp-month-charts__progress {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            min-width: 0;
        }
        .cp-month-charts__progress-track {
            flex: 1 1 auto;
            min-width: 0;
            height: 0.375rem;
            overflow: hidden;
            border-radius: 9999px;
            background: rgb(243 244 246);
        }
        .dark .cp-month-charts__progress-track { background: rgb(31 41 55); }
        .cp-month-charts__progress-fill {
            height: 100%;
            border-radius: 9999px;
            background: rgb(16 185 129);
        }
        .cp-month-charts__progress-fill--over { background: rgb(245 158 11); }
        .cp-month-charts__more {
            margin: 0.5rem 0 0;
            font-size: 0.75rem;
            color: rgb(107 114 128);
        }
    </style>
@endonce

{{-- Compact month charts: domain + writer, 50/50 desktop — shared by Content Projects + Archived --}}
<div {{ $attributes->class(['cp-month-charts']) }}>
    <section class="cp-month-charts__card">
        <div class="cp-month-charts__head">
            <div class="min-w-0">
                <h3 class="cp-month-charts__title">
                    {{ __('seo-content-ai::filament.projects.chart_articles_by_domain') }}
                </h3>
                <p class="cp-month-charts__sub">{{ $domainChart['month_label'] ?? '' }}</p>
            </div>
            <div class="shrink-0 text-right">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    {{ __('seo-content-ai::filament.projects.chart_total_articles') }}
                </p>
                <p class="text-lg font-semibold tabular-nums leading-tight text-primary-600 dark:text-primary-400">{{ $domainTotal }}</p>
            </div>
        </div>

        @if (! empty($domainChart['empty']))
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __($domainEmptyKey) }}</p>
        @else
            <div class="cp-month-charts__body">
                <div
                    class="cp-month-charts__donut"
                    style="background: {{ $domainChart['donut_gradient'] ?? 'conic-gradient(#e5e7eb 0% 100%)' }}"
                    role="img"
                    aria-label="{{ __('seo-content-ai::filament.projects.chart_articles_by_domain') }}"
                >
                    <div class="cp-month-charts__donut-hole">
                        <span class="cp-month-charts__donut-kicker">{{ __('seo-content-ai::filament.projects.chart_donut_total') }}</span>
                        <span class="cp-month-charts__donut-value">{{ $domainTotal }}</span>
                        <span class="cp-month-charts__donut-meta">{{ __('seo-content-ai::filament.projects.chart_donut_articles') }}</span>
                    </div>
                </div>

                <div class="cp-month-charts__side">
                    <ul class="cp-month-charts__rank">
                        @foreach (($domainChart['visible_rows'] ?? []) as $row)
                            @php
                                $count = (int) ($row['total_count'] ?? $row['count'] ?? 0);
                                $sharePct = (float) ($row['share_pct'] ?? 0);
                                $barPct = max(0, min(100, $sharePct));
                                $color = (string) ($row['color'] ?? '#10b981');
                                $domain = (string) ($row['domain'] ?? '');
                                $rank = (int) ($row['rank'] ?? $loop->iteration);
                            @endphp
                            <li class="cp-month-charts__rank-row">
                                <div class="cp-month-charts__rank-top">
                                    <span class="cp-month-charts__badge" style="background: {{ $color }}">{{ $rank }}</span>
                                    <span class="cp-month-charts__domain" title="{{ $domain }}">{{ $domain }}</span>
                                    <span class="cp-month-charts__count">
                                        {{ $count }}
                                        <span class="text-gray-400 dark:text-gray-500">({{ number_format($sharePct, 1) }}%)</span>
                                    </span>
                                </div>
                                <div class="cp-month-charts__bar-track">
                                    <div class="cp-month-charts__bar-fill" style="width: {{ $barPct }}%; background: {{ $color }}"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ((int) ($domainChart['more_count'] ?? 0) > 0)
                        <p class="cp-month-charts__more">
                            {{ __('seo-content-ai::filament.projects.chart_more_domains', ['count' => (int) $domainChart['more_count']]) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </section>

    <section class="cp-month-charts__card">
        <div class="cp-month-charts__head">
            <div class="min-w-0">
                <h3 class="cp-month-charts__title">
                    {{ __('seo-content-ai::filament.projects.chart_articles_by_writer') }}
                </h3>
                <p class="cp-month-charts__sub">{{ $writerChart['month_label'] ?? '' }}</p>
            </div>
            <div class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <x-filament::icon icon="heroicon-m-user-group" class="h-3.5 w-3.5 text-gray-400" />
                <span>{{ __('seo-content-ai::filament.projects.chart_writers_count', ['count' => $writerCount]) }}</span>
            </div>
        </div>

        @if (! empty($writerChart['empty']))
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __($writerEmptyKey) }}</p>
        @else
            <div class="cp-month-charts__body">
                <div class="cp-month-charts__writer-summary">
                    <div
                        class="cp-month-charts__donut"
                        style="background: {{ $writerChart['donut_gradient'] ?? 'conic-gradient(#e5e7eb 0% 100%)' }}"
                        role="img"
                        aria-label="{{ __('seo-content-ai::filament.projects.chart_overall_progress') }}"
                    >
                        <div class="cp-month-charts__donut-hole">
                            <span class="cp-month-charts__donut-value cp-month-charts__donut-value--accent">{{ $overallPct }}%</span>
                        </div>
                    </div>
                    <div class="cp-month-charts__writer-summary-text">
                        <p class="m-0 text-xs font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.chart_overall_progress') }}
                        </p>
                        <p class="m-0 mt-0.5 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $writerTotal }} / {{ $teamCapacity }}
                        </p>
                        <p class="m-0 mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                            {{ __('seo-content-ai::filament.projects.chart_team_capacity', ['count' => $teamCapacity]) }}
                        </p>
                    </div>
                </div>

                <div class="cp-month-charts__side">
                    <div class="cp-month-charts__writer-table-head">
                        <span>{{ __('seo-content-ai::filament.projects.chart_col_writer') }}</span>
                        <span>{{ __('seo-content-ai::filament.projects.chart_col_progress') }}</span>
                        <span class="text-right">{{ __('seo-content-ai::filament.projects.chart_col_articles') }}</span>
                    </div>
                    <ul class="cp-month-charts__writer-list">
                        @foreach (($writerChart['visible_rows'] ?? []) as $row)
                            @php
                                $name = (string) ($row['name'] ?? '');
                                $initials = (string) ($row['initials'] ?? '?');
                                $count = (int) ($row['total_count'] ?? $row['count'] ?? 0);
                                $capacity = (int) ($row['capacity'] ?? 30);
                                $progressPct = (int) ($row['progress_pct'] ?? 0);
                                $barPct = max(0, min(100, $progressPct));
                                $over = $count > $capacity;
                            @endphp
                            <li>
                                <div class="cp-month-charts__writer-row">
                                    <div class="cp-month-charts__writer-name">
                                        <span class="cp-month-charts__avatar">{{ $initials }}</span>
                                        <span class="truncate font-medium text-gray-800 dark:text-gray-100" title="{{ $name }}">{{ $name }}</span>
                                    </div>
                                    <div class="cp-month-charts__progress">
                                        <div class="cp-month-charts__progress-track">
                                            <div
                                                class="cp-month-charts__progress-fill{{ $over ? ' cp-month-charts__progress-fill--over' : '' }}"
                                                style="width: {{ $barPct }}%"
                                            ></div>
                                        </div>
                                        <span class="w-7 shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $progressPct }}%</span>
                                    </div>
                                    <span class="text-right tabular-nums {{ $over ? 'text-warning-600 dark:text-warning-400' : 'text-gray-700 dark:text-gray-200' }}">
                                        {{ $count }} / {{ $capacity }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ((int) ($writerChart['more_count'] ?? 0) > 0)
                        <p class="cp-month-charts__more">
                            {{ __('seo-content-ai::filament.projects.chart_more_writers', ['count' => (int) $writerChart['more_count']]) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </section>
</div>
