@php
    $variant = (string) ($variant ?? 'content_manager');
    $data = is_array($data ?? null) ? $data : [];
    $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
    $month = is_array($data['month_progress'] ?? null) ? $data['month_progress'] : [];
    $monthTotal = (int) ($month['total'] ?? 0);
    $monthLabel = (string) ($data['month_label'] ?? now()->format('m/Y'));
    // Inline grid: Tailwind JIT may not scan newly added addon blades → xl:grid-cols-5 was missing at runtime.
    $kpiGrid = 'display:grid;gap:0.75rem;grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr))';
    $twoCol = 'display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr))';
    $card = 'border:1px solid #e5e7eb;border-radius:0.75rem;background:#fff;padding:1rem 1.1rem';
@endphp

<x-filament-widgets::widget>
    <div style="display:flex;flex-direction:column;gap:1rem">
        @if ($variant === 'manager_planner' && filled($data['today_label'] ?? null))
            <div style="display:flex;justify-content:flex-end">
                <p style="margin:0;font-size:0.875rem;color:#6b7280">
                    {{ __('seo-content-ai::filament.dashboard.ops_today_label', ['date' => $data['today_label']]) }}
                </p>
            </div>
        @endif

        @if ($variant === 'content_manager')
            <div style="{{ $kpiGrid }}">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_pending_review'),
                    'value' => (int) ($kpis['pending_review'] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-document-text',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_reviewed'),
                    'value' => (int) ($kpis['reviewed'] ?? 0),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_due_today'),
                    'value' => (int) ($kpis['due_today'] ?? 0),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-calendar-days',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_done_today'),
                    'value' => (int) ($kpis['done_today'] ?? 0),
                    'tone' => 'purple',
                    'icon' => 'heroicon-o-check-badge',
                ])
            </div>

            <div style="{{ $twoCol }}">
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

            <div style="{{ $card }}">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem">
                    <h3 style="margin:0;font-size:1rem;font-weight:600;color:#111827">
                        {{ __('seo-content-ai::filament.dashboard.ops_month_progress_cm') }}
                    </h3>
                    <span style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.75rem;color:#6b7280">
                        <x-filament::icon icon="heroicon-o-calendar" class="h-3.5 w-3.5" />
                        {{ __('seo-content-ai::filament.dashboard.ops_month_label', ['month' => $monthLabel]) }}
                    </span>
                </div>
                <div style="display:flex;flex-direction:column;gap:0.75rem">
                    @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                        'label' => __('seo-content-ai::filament.dashboard.ops_progress_total'),
                        'value' => (int) ($month['total'] ?? 0),
                        'total' => $monthTotal,
                        'tone' => 'blue',
                    ])
                    @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                        'label' => __('seo-content-ai::filament.dashboard.ops_kpi_pending_review'),
                        'value' => (int) ($month['pending_review'] ?? 0),
                        'total' => $monthTotal,
                        'tone' => 'amber',
                    ])
                    @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                        'label' => __('seo-content-ai::filament.dashboard.ops_kpi_reviewed'),
                        'value' => (int) ($month['reviewed'] ?? 0),
                        'total' => $monthTotal,
                        'tone' => 'green',
                    ])
                    @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                        'label' => __('seo-content-ai::filament.dashboard.ops_progress_published'),
                        'value' => (int) ($month['published'] ?? 0),
                        'total' => $monthTotal,
                        'tone' => 'purple',
                    ])
                </div>
            </div>
        @else
            {{-- Manager / Planner — match dashboard-manager-planner content hierarchy --}}
            <div style="{{ $kpiGrid }}">
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_needs_review'),
                    'value' => (int) ($kpis['needs_review'] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clipboard-document-check',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_approved'),
                    'value' => (int) ($kpis['approved'] ?? 0),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-check-circle',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_scheduled_today'),
                    'value' => (int) ($kpis['scheduled_today'] ?? 0),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-calendar-days',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_publish_errors'),
                    'value' => (int) ($kpis['publish_errors'] ?? 0),
                    'tone' => 'red',
                    'icon' => 'heroicon-o-exclamation-triangle',
                ])
                @include('seo-content-ai::filament.widgets.partials.ops-dashboard-kpi', [
                    'label' => __('seo-content-ai::filament.dashboard.ops_kpi_ai_running'),
                    'value' => (int) ($kpis['ai_running'] ?? 0),
                    'tone' => 'purple',
                    'icon' => 'heroicon-o-cpu-chip',
                ])
            </div>

            <div style="{{ $twoCol }}">
                <div style="{{ $card }}">
                    <h3 style="margin:0 0 0.75rem;font-size:1rem;font-weight:600;color:#111827">
                        {{ __('seo-content-ai::filament.dashboard.ops_attention_title') }}
                    </h3>
                    @php $attention = is_array($data['attention'] ?? null) ? $data['attention'] : []; @endphp
                    @if ($attention === [])
                        <p style="margin:0;font-size:0.875rem;color:#6b7280">
                            {{ __('seo-content-ai::filament.dashboard.ops_attention_empty') }}
                        </p>
                    @else
                        <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:0.5rem">
                            @foreach ($attention as $item)
                                @php
                                    $tone = (string) ($item['tone'] ?? 'blue');
                                    $toneStyle = match ($tone) {
                                        'danger', 'red' => 'background:#fef2f2;color:#b91c1c',
                                        'warning', 'amber' => 'background:#fffbeb;color:#b45309',
                                        default => 'background:#eff6ff;color:#1d4ed8',
                                    };
                                @endphp
                                <li style="border-radius:0.5rem;padding:0.5rem 0.75rem;font-size:0.875rem;{{ $toneStyle }}">
                                    @if (filled($item['url'] ?? null))
                                        <a href="{{ $item['url'] }}" style="color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">{{ $item['message'] ?? '' }}</a>
                                    @else
                                        {{ $item['message'] ?? '' }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div style="{{ $card }}">
                    <h3 style="margin:0 0 0.75rem;font-size:1rem;font-weight:600;color:#111827">
                        {{ __('seo-content-ai::filament.dashboard.ops_activity_title') }}
                    </h3>
                    @php $activity = is_array($data['activity'] ?? null) ? $data['activity'] : []; @endphp
                    @if ($activity === [])
                        <p style="margin:0;font-size:0.875rem;color:#6b7280">
                            {{ __('seo-content-ai::filament.dashboard.ops_activity_empty') }}
                        </p>
                    @else
                        <ol style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:0.75rem">
                            @foreach ($activity as $event)
                                <li style="display:flex;gap:0.75rem;font-size:0.875rem">
                                    <span style="width:2.75rem;flex-shrink:0;font-weight:600;color:#6b7280">{{ $event['time'] ?? '—' }}</span>
                                    <span style="color:#1f2937">{{ $event['message'] ?? '' }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>

            <div style="{{ $twoCol }}">
                <div style="{{ $card }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem">
                        <h3 style="margin:0;font-size:1rem;font-weight:600;color:#111827">
                            {{ __('seo-content-ai::filament.dashboard.ops_month_progress_manager') }}
                        </h3>
                        <span style="font-size:0.75rem;color:#6b7280">
                            {{ __('seo-content-ai::filament.dashboard.ops_month_label', ['month' => $monthLabel]) }}
                        </span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.75rem">
                        @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                            'label' => __('seo-content-ai::filament.dashboard.ops_progress_written'),
                            'value' => (int) ($month['written'] ?? 0),
                            'total' => $monthTotal,
                            'tone' => 'green',
                        ])
                        @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                            'label' => __('seo-content-ai::filament.dashboard.ops_kpi_pending_review'),
                            'value' => (int) ($month['pending_review'] ?? 0),
                            'total' => $monthTotal,
                            'tone' => 'amber',
                        ])
                        @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                            'label' => __('seo-content-ai::filament.dashboard.ops_kpi_approved'),
                            'value' => (int) ($month['approved'] ?? 0),
                            'total' => $monthTotal,
                            'tone' => 'blue',
                        ])
                        @include('seo-content-ai::filament.widgets.partials.ops-dashboard-progress', [
                            'label' => __('seo-content-ai::filament.dashboard.ops_progress_published'),
                            'value' => (int) ($month['published'] ?? 0),
                            'total' => $monthTotal,
                            'tone' => 'purple',
                        ])
                    </div>
                </div>

                <div style="{{ $card }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:0.75rem">
                        <h3 style="margin:0;font-size:1rem;font-weight:600;color:#111827">
                            {{ __('seo-content-ai::filament.dashboard.ops_queue_title') }}
                        </h3>
                        @if (filled($data['publish_queue_url'] ?? null))
                            <a href="{{ $data['publish_queue_url'] }}" style="font-size:0.75rem;font-weight:600;color:#2563eb;text-decoration:none">
                                {{ __('seo-content-ai::filament.dashboard.ops_view_all') }}
                            </a>
                        @endif
                    </div>
                    @php $queueRows = is_array($data['publish_queue'] ?? null) ? $data['publish_queue'] : []; @endphp
                    @if ($queueRows === [])
                        <p style="margin:0;font-size:0.875rem;color:#6b7280">
                            {{ __('seo-content-ai::filament.dashboard.ops_queue_empty') }}
                        </p>
                    @else
                        <div style="overflow-x:auto">
                            <table style="width:100%;min-width:28rem;border-collapse:collapse;font-size:0.875rem">
                                <thead>
                                    <tr style="border-bottom:1px solid #f3f4f6;text-align:left;font-size:0.75rem;font-weight:500;color:#6b7280">
                                        <th style="padding:0.5rem 0.5rem 0.5rem 0">{{ __('seo-content-ai::filament.dashboard.ops_col_title') }}</th>
                                        <th style="padding:0.5rem">{{ __('seo-content-ai::filament.dashboard.ops_col_domain') }}</th>
                                        <th style="padding:0.5rem">{{ __('seo-content-ai::filament.dashboard.ops_col_schedule') }}</th>
                                        <th style="padding:0.5rem 0 0.5rem 0.5rem">{{ __('seo-content-ai::filament.dashboard.ops_col_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($queueRows as $row)
                                        <tr style="border-bottom:1px solid #f9fafb">
                                            <td style="padding:0.65rem 0.5rem 0.65rem 0">
                                                @if (filled($row['url'] ?? null))
                                                    <a href="{{ $row['url'] }}" style="font-weight:600;color:#2563eb;text-decoration:none">{{ $row['title'] ?? '—' }}</a>
                                                @else
                                                    <span style="font-weight:600;color:#111827">{{ $row['title'] ?? '—' }}</span>
                                                @endif
                                            </td>
                                            <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['domain'] ?? '—' }}</td>
                                            <td style="padding:0.65rem 0.5rem;color:#4b5563">{{ $row['scheduled_at'] ?? '—' }}</td>
                                            <td style="padding:0.65rem 0 0.65rem 0.5rem">
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
                </div>
            </div>
        @endif

        <div style="display:flex;flex-direction:column;gap:0.75rem;border:1px solid #bae6fd;border-radius:0.75rem;background:#f0f9ff;padding:0.75rem 1rem;font-size:0.875rem;color:#0c4a6e">
            <div style="display:flex;align-items:flex-start;gap:0.5rem">
                <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ __('seo-content-ai::filament.dashboard.ops_stats_moved_notice') }}</span>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
