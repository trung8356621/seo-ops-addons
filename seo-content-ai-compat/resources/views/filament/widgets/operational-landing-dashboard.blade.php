@php
    $variant = (string) ($variant ?? 'content_manager');
    $data = is_array($data ?? null) ? $data : [];
    $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
    $month = is_array($data['month_progress'] ?? null) ? $data['month_progress'] : [];
    $monthRows = is_array($month['rows'] ?? null) ? $month['rows'] : [];
    $monthLabel = (string) ($data['month_label'] ?? now()->format('m/Y'));
    $monthHasProjects = (bool) ($data['month_has_projects'] ?? ($month['has_month_projects'] ?? false));
    $subtitle = (string) __('seo-content-ai::filament.'.($data['subtitle_key'] ?? 'dashboard.ops_cm_subtitle'));
@endphp

{{-- CSS is loaded via SeoServiceProvider Filament STYLES_AFTER hook — never @vite inside Livewire widgets. --}}

<x-filament-widgets::widget>
    <div class="ops-landing">
        <div class="ops-landing__header">
            <div>
                <h2 class="ops-landing__title">Dashboard</h2>
                <p class="ops-landing__subtitle">{{ $subtitle }}</p>
            </div>
            @if ($variant === 'manager_planner' && filled($data['today_label'] ?? null))
                <div class="ops-landing__date">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                    <span>{{ __('seo-content-ai::filament.dashboard.ops_today_label', ['date' => $data['today_label']]) }}</span>
                </div>
            @endif
        </div>

        @if ($variant === 'content_manager')
            <div class="ops-landing__kpi-grid ops-landing__kpi-grid--4">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_pending_review'),
                    'value' => (int) ($kpis['pending_review'] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-document-text',
                    'meta' => __('seo-content-ai::filament.dashboard.ops_kpi_scope_hint'),
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_reviewed'),
                    'value' => (int) ($kpis['reviewed'] ?? 0),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                    'meta' => null,
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_due_today'),
                    'value' => (int) ($kpis['due_today'] ?? 0),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-calendar-days',
                    'meta' => null,
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_done_today'),
                    'value' => (int) ($kpis['done_today'] ?? 0),
                    'tone' => 'purple',
                    'icon' => 'heroicon-o-check-badge',
                    'meta' => null,
                ])
            </div>

            <div class="ops-landing__split">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-table-card', [
                    'title' => __('seo-content-ai::filament.dashboard.ops_pending_table_title'),
                    'viewAllUrl' => $data['view_all_pending_url'] ?? null,
                    'empty' => __('seo-content-ai::filament.dashboard.ops_pending_empty'),
                    'headers' => [
                        '#',
                        __('seo-content-ai::filament.dashboard.ops_col_title'),
                        __('seo-content-ai::filament.dashboard.ops_col_project'),
                        __('seo-content-ai::filament.dashboard.ops_col_updated'),
                        __('seo-content-ai::filament.dashboard.ops_col_status'),
                    ],
                    'rows' => $data['pending_rows'] ?? [],
                    'rowType' => 'pending',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-table-card', [
                    'title' => __('seo-content-ai::filament.dashboard.ops_reviewed_table_title'),
                    'viewAllUrl' => $data['view_all_reviewed_url'] ?? null,
                    'empty' => __('seo-content-ai::filament.dashboard.ops_reviewed_empty'),
                    'headers' => [
                        '#',
                        __('seo-content-ai::filament.dashboard.ops_col_title'),
                        __('seo-content-ai::filament.dashboard.ops_col_reviewer'),
                        __('seo-content-ai::filament.dashboard.ops_col_time'),
                        __('seo-content-ai::filament.dashboard.ops_col_status'),
                    ],
                    'rows' => $data['reviewed_rows'] ?? [],
                    'rowType' => 'reviewed',
                ])
            </div>

            @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress-card', [
                'title' => __('seo-content-ai::filament.dashboard.ops_month_progress_cm'),
                'monthLabel' => $monthLabel,
                'hasProjects' => $monthHasProjects,
                'rows' => $monthRows,
                'empty' => __('seo-content-ai::filament.dashboard.ops_month_empty', ['month' => $monthLabel]),
            ])
        @else
            <div class="ops-landing__kpi-grid ops-landing__kpi-grid--5">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_needs_review'),
                    'value' => (int) ($kpis['needs_review'] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clipboard-document-check',
                    'meta' => __('seo-content-ai::filament.dashboard.ops_kpi_scope_hint'),
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_approved'),
                    'value' => (int) ($kpis['approved'] ?? 0),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                    'meta' => __('seo-content-ai::filament.dashboard.ops_kpi_scope_hint'),
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_scheduled_today'),
                    'value' => (int) ($kpis['scheduled_today'] ?? 0),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-calendar-days',
                    'meta' => null,
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_publish_errors'),
                    'value' => (int) ($kpis['publish_errors'] ?? 0),
                    'tone' => 'red',
                    'icon' => 'heroicon-o-exclamation-triangle',
                    'meta' => null,
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_ai_running'),
                    'value' => (int) ($kpis['ai_running'] ?? 0),
                    'tone' => 'purple',
                    'icon' => 'heroicon-o-sparkles',
                    'meta' => null,
                ])
            </div>

            <div class="ops-landing__split">
                @php $attention = is_array($data['attention'] ?? null) ? $data['attention'] : []; @endphp
                <section @class(['ops-landing-card', 'ops-landing-card--compact-empty' => $attention === []])>
                    <div class="ops-landing-card__head">
                        <div class="ops-landing-card__title-wrap">
                            <x-filament::icon icon="heroicon-o-bell-alert" class="h-4 w-4 text-red-500" />
                            <h3 class="ops-landing-card__title">{{ __('seo-content-ai::filament.dashboard.ops_attention_title') }}</h3>
                            @if ($attention !== [])
                                <span class="ops-landing-card__badge">{{ count($attention) }}</span>
                            @endif
                        </div>
                        @if (filled($data['view_all_projects_url'] ?? null))
                            <a href="{{ $data['view_all_projects_url'] }}" class="ops-landing-card__link">
                                {{ __('seo-content-ai::filament.dashboard.ops_view_all') }} →
                            </a>
                        @endif
                    </div>
                    @if ($attention === [])
                        <p class="ops-landing-card__empty">{{ __('seo-content-ai::filament.dashboard.ops_attention_empty') }}</p>
                    @else
                        <ul class="ops-landing-attention">
                            @foreach ($attention as $item)
                                @php
                                    $tone = (string) ($item['tone'] ?? 'info');
                                    $iconClass = match ($tone) {
                                        'danger', 'red' => 'ops-landing-attention__icon--danger',
                                        'warning', 'amber' => 'ops-landing-attention__icon--warning',
                                        default => 'ops-landing-attention__icon--info',
                                    };
                                    $tag = filled($item['url'] ?? null) ? 'a' : 'div';
                                @endphp
                                <li>
                                    <{{ $tag }}
                                        @if ($tag === 'a') href="{{ $item['url'] }}" @endif
                                        class="ops-landing-attention__row"
                                    >
                                        <span class="ops-landing-attention__icon {{ $iconClass }}">
                                            <x-filament::icon :icon="$item['icon'] ?? 'heroicon-o-information-circle'" class="h-4 w-4" />
                                        </span>
                                        <div class="ops-landing-attention__main">
                                            <p class="ops-landing-attention__title">{{ $item['title'] ?? '' }}</p>
                                            @if (filled($item['detail'] ?? null))
                                                <p class="ops-landing-attention__detail">{{ $item['detail'] }}</p>
                                            @endif
                                        </div>
                                        <div class="ops-landing-attention__aside">
                                            @if (filled($item['age'] ?? null))
                                                <span>{{ $item['age'] }}</span>
                                            @endif
                                            @if ($tag === 'a')
                                                <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
                                            @endif
                                        </div>
                                    </{{ $tag }}>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @php $activity = is_array($data['activity'] ?? null) ? $data['activity'] : []; @endphp
                <section @class(['ops-landing-card', 'ops-landing-card--compact-empty' => $activity === []])>
                    <div class="ops-landing-card__head">
                        <div class="ops-landing-card__title-wrap">
                            <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4 text-emerald-500" />
                            <h3 class="ops-landing-card__title">{{ __('seo-content-ai::filament.dashboard.ops_activity_title') }}</h3>
                        </div>
                    </div>
                    @if ($activity === [])
                        <p class="ops-landing-card__empty">{{ __('seo-content-ai::filament.dashboard.ops_activity_empty') }}</p>
                    @else
                        <ol class="ops-landing-timeline">
                            @foreach ($activity as $event)
                                <li class="ops-landing-timeline__item">
                                    <span class="ops-landing-timeline__time">{{ $event['time'] ?? '—' }}</span>
                                    <div class="ops-landing-timeline__body">
                                        <span class="ops-landing-timeline__dot" aria-hidden="true"></span>
                                        <p class="ops-landing-timeline__text">{{ $event['message'] ?? '' }}</p>
                                        @if (filled($event['context'] ?? null))
                                            <p class="ops-landing-timeline__context">{{ $event['context'] }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            <div class="ops-landing__split">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress-card', [
                    'title' => __('seo-content-ai::filament.dashboard.ops_month_progress_manager'),
                    'monthLabel' => $monthLabel,
                    'hasProjects' => $monthHasProjects,
                    'rows' => $monthRows,
                    'empty' => __('seo-content-ai::filament.dashboard.ops_month_empty', ['month' => $monthLabel]),
                    'caption' => __('seo-content-ai::filament.dashboard.ops_month_scope_hint', ['month' => $monthLabel]),
                ])

                @php $queueRows = is_array($data['publish_queue'] ?? null) ? $data['publish_queue'] : []; @endphp
                <section @class(['ops-landing-card', 'ops-landing-card--compact-empty' => $queueRows === []])>
                    <div class="ops-landing-card__head">
                        <div class="ops-landing-card__title-wrap">
                            <x-filament::icon icon="heroicon-o-queue-list" class="h-4 w-4 text-sky-500" />
                            <h3 class="ops-landing-card__title">{{ __('seo-content-ai::filament.dashboard.ops_queue_title') }}</h3>
                        </div>
                        @if (filled($data['publish_queue_url'] ?? null))
                            <a href="{{ $data['publish_queue_url'] }}" class="ops-landing-card__link">
                                {{ __('seo-content-ai::filament.dashboard.ops_view_all') }} →
                            </a>
                        @endif
                    </div>
                    @if ($queueRows === [])
                        <p class="ops-landing-card__empty">{{ __('seo-content-ai::filament.dashboard.ops_queue_empty') }}</p>
                    @else
                        <div class="ops-landing-table-wrap">
                            <table class="ops-landing-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>{{ __('seo-content-ai::filament.dashboard.ops_col_title') }}</th>
                                        <th>{{ __('seo-content-ai::filament.dashboard.ops_col_domain') }}</th>
                                        <th>{{ __('seo-content-ai::filament.dashboard.ops_col_schedule') }}</th>
                                        <th>{{ __('seo-content-ai::filament.dashboard.ops_col_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($queueRows as $i => $row)
                                        <tr>
                                            <td>{{ $i + 1 }}</td>
                                            <td>
                                                @if (filled($row['url'] ?? null))
                                                    <a href="{{ $row['url'] }}" class="ops-landing-table__title">{{ $row['title'] ?? '—' }}</a>
                                                @else
                                                    <span class="ops-landing-table__title">{{ $row['title'] ?? '—' }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $row['domain'] ?? '—' }}</td>
                                            <td>{{ $row['scheduled_at'] ?? '—' }}</td>
                                            <td>
                                                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-badge', [
                                                    'status' => ($row['status'] ?? '') === 'scheduled' ? 'scheduled' : 'waiting_publish',
                                                ])
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        @endif

        <div class="ops-landing-banner">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ __('seo-content-ai::filament.dashboard.ops_stats_moved_notice') }}</span>
        </div>
    </div>
</x-filament-widgets::widget>
