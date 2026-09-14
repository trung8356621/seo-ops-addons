@php
    $payload = method_exists($this, 'sitePlanningPayload') ? $this->sitePlanningPayload() : [
        'months' => [],
        'year_groups' => [],
        'rows' => [],
    ];
    $months = is_array($payload['months'] ?? null) ? $payload['months'] : [];
    $yearGroups = is_array($payload['year_groups'] ?? null) ? $payload['year_groups'] : [];
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
@endphp

<div class="cp-site-planning" wire:key="cp-site-planning-panel" data-site-planning="1">
    @if ($rows === [])
        <p class="cp-site-planning__empty">
            {{ __('seo-content-ai::filament.projects.site_planning_empty') }}
        </p>
    @else
        <div class="cp-site-planning__scroll">
            <table class="cp-site-planning__table">
                <thead>
                    <tr class="cp-site-planning__year-row">
                        <th
                            scope="col"
                            rowspan="2"
                            class="cp-site-planning__sticky cp-site-planning__domain-head"
                        >
                            Domain
                        </th>
                        @foreach ($yearGroups as $group)
                            <th
                                scope="colgroup"
                                colspan="{{ (int) ($group['span'] ?? 1) }}"
                                class="cp-site-planning__year-cell"
                            >
                                {{ (int) ($group['year'] ?? 0) }}
                            </th>
                        @endforeach
                    </tr>
                    <tr class="cp-site-planning__month-row">
                        @foreach ($months as $month)
                            <th
                                scope="col"
                                @class([
                                    'cp-site-planning__month-cell',
                                    'is-current' => (bool) ($month['is_current'] ?? false),
                                ])
                            >
                                {{ $month['month'] ?? '' }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $siteId = (int) ($row['site_id'] ?? 0);
                            $monthCells = is_array($row['months'] ?? null) ? $row['months'] : [];
                        @endphp
                        <tr
                            class="cp-site-planning__row"
                            data-site-planning-row="{{ $siteId }}"
                        >
                            <th
                                scope="row"
                                class="cp-site-planning__sticky cp-site-planning__domain-cell"
                            >
                                <div class="cp-site-planning__domain" title="{{ $row['domain'] ?? ('#'.$siteId) }}">
                                    {{ $row['domain'] ?? ('#'.$siteId) }}
                                </div>
                            </th>
                            @foreach ($monthCells as $cell)
                                @php
                                    $planned = (int) ($cell['planned'] ?? 0);
                                    $target = (int) ($cell['target'] ?? 0);
                                    $over = (bool) ($cell['over_target'] ?? false);
                                    $isCurrent = (bool) ($cell['is_current'] ?? false);
                                @endphp
                                <td
                                    @class([
                                        'cp-site-planning__value',
                                        'is-current' => $isCurrent,
                                        'is-over' => $over,
                                    ])
                                    title="{{ $over ? __('seo-content-ai::filament.projects.site_planning_over_warning') : '' }}"
                                >
                                    {{ $planned }} / {{ $target }}@if ($over)<span class="cp-site-planning__warn" aria-label="{{ __('seo-content-ai::filament.projects.site_planning_over_warning') }}">⚠</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<style>
    .cp-site-planning {
        min-width: 0;
    }
    .cp-site-planning__empty {
        margin: 0;
        font-size: 0.8125rem;
        line-height: 1.35;
        color: var(--cp-plan-muted, #6b7280);
    }
    .dark .cp-site-planning__empty {
        color: #9ca3af;
    }
    .cp-site-planning__scroll {
        overflow-x: auto;
        border: 1px solid var(--cp-plan-border, #e5e7eb);
        border-radius: 0.625rem;
        background: transparent;
        scrollbar-width: thin;
        scrollbar-color: rgb(148 163 184 / 0.7) transparent;
    }
    .dark .cp-site-planning__scroll {
        border-color: rgb(255 255 255 / 0.1);
        scrollbar-color: rgb(100 116 139 / 0.75) transparent;
    }
    .cp-site-planning__table {
        width: 100%;
        min-width: max-content;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.75rem;
        line-height: 1.25;
    }
    .cp-site-planning__table th,
    .cp-site-planning__table td {
        border-bottom: 1px solid rgb(229 231 235 / 0.65);
        vertical-align: middle;
    }
    .dark .cp-site-planning__table th,
    .dark .cp-site-planning__table td {
        border-bottom-color: rgb(255 255 255 / 0.06);
    }
    .cp-site-planning__table tbody tr:last-child th,
    .cp-site-planning__table tbody tr:last-child td {
        border-bottom: 0;
    }
    .cp-site-planning__year-row,
    .cp-site-planning__month-row {
        background: #f9fafb;
    }
    .dark .cp-site-planning__year-row,
    .dark .cp-site-planning__month-row {
        background: rgb(255 255 255 / 0.03);
    }
    .cp-site-planning__domain-head {
        padding: 0.4rem 0.625rem;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--cp-plan-muted, #6b7280);
    }
    .cp-site-planning__year-cell {
        padding: 0.3rem 0.375rem 0.15rem;
        text-align: center;
        font-size: 0.6875rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        color: #4b5563;
    }
    .dark .cp-site-planning__year-cell {
        color: #d1d5db;
    }
    .cp-site-planning__month-cell {
        padding: 0.25rem 0.5rem 0.4rem;
        text-align: center;
        font-size: 0.6875rem;
        font-weight: 500;
        font-variant-numeric: tabular-nums;
        color: var(--cp-plan-muted, #6b7280);
    }
    .cp-site-planning__month-cell.is-current {
        background: #fffbeb;
        color: #b45309;
    }
    .dark .cp-site-planning__month-cell.is-current {
        background: rgb(245 158 11 / 0.12);
        color: #fcd34d;
    }
    .cp-site-planning__row:hover {
        background: rgb(249 250 251 / 0.85);
    }
    .dark .cp-site-planning__row:hover {
        background: rgb(255 255 255 / 0.03);
    }
    .cp-site-planning__domain-cell {
        max-width: 11rem;
        padding: 0.35rem 0.625rem;
        font-weight: 500;
        color: #111827;
    }
    .dark .cp-site-planning__domain-cell {
        color: #f3f4f6;
    }
    .cp-site-planning__domain {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .cp-site-planning__value {
        white-space: nowrap;
        padding: 0.35rem 0.5rem;
        text-align: center;
        font-variant-numeric: tabular-nums;
        color: #374151;
    }
    .dark .cp-site-planning__value {
        color: #e5e7eb;
    }
    .cp-site-planning__value.is-current {
        background: rgb(255 251 235 / 0.7);
    }
    .dark .cp-site-planning__value.is-current {
        background: rgb(245 158 11 / 0.08);
    }
    .cp-site-planning__value.is-over {
        font-weight: 600;
        color: #b45309;
    }
    .dark .cp-site-planning__value.is-over {
        color: #fcd34d;
    }
    .cp-site-planning__warn {
        margin-left: 0.125rem;
        color: #b45309;
        font-weight: 700;
    }
    .dark .cp-site-planning__warn {
        color: #fbbf24;
    }
    .cp-site-planning__sticky {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #fff;
        box-shadow: 1px 0 0 0 var(--cp-plan-border, #e5e7eb);
        min-width: 8.5rem;
    }
    .dark .cp-site-planning__sticky {
        background: rgb(17 24 39);
        box-shadow: 1px 0 0 0 rgb(255 255 255 / 0.1);
    }
    thead .cp-site-planning__sticky {
        z-index: 3;
        background: #f9fafb;
    }
    .dark thead .cp-site-planning__sticky {
        background: rgb(17 24 39);
    }
    .cp-site-planning__row:hover .cp-site-planning__sticky {
        background: #f9fafb;
    }
    .dark .cp-site-planning__row:hover .cp-site-planning__sticky {
        background: rgb(31 41 55);
    }
</style>
