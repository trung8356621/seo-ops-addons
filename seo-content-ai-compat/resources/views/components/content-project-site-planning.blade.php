@php
    $payload = method_exists($this, 'sitePlanningPayload') ? $this->sitePlanningPayload() : [
        'months' => [],
        'year_groups' => [],
        'rows' => [],
        'active_month' => null,
    ];
    $months = is_array($payload['months'] ?? null) ? $payload['months'] : [];
    $yearGroups = is_array($payload['year_groups'] ?? null) ? $payload['year_groups'] : [];
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $activeMonth = (string) ($payload['active_month'] ?? '');
@endphp

<div
    class="cp-site-planning"
    wire:key="cp-site-planning-panel-{{ $activeMonth }}"
    data-site-planning="1"
    x-data="{
        open: false,
        loading: false,
        detail: null,
        async openCell(siteId, month) {
            this.open = true;
            this.loading = true;
            this.detail = null;
            try {
                this.detail = await $wire.sitePlanningCellDetail(siteId, month);
            } catch (e) {
                this.detail = null;
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.open = false;
            this.detail = null;
        }
    }"
>
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
                                    $planningMonth = (string) ($cell['planning_month'] ?? '');
                                @endphp
                                <td
                                    @class([
                                        'cp-site-planning__value',
                                        'is-current' => $isCurrent,
                                        'is-over' => $over,
                                    ])
                                    title="{{ $over ? __('seo-content-ai::filament.projects.site_planning_over_warning') : '' }}"
                                >
                                    <button
                                        type="button"
                                        class="cp-site-planning__cell-btn"
                                        @click="openCell({{ $siteId }}, '{{ $planningMonth }}')"
                                    >
                                        {{ $planned }} / {{ $target }}@if ($over)<span class="cp-site-planning__warn" aria-label="{{ __('seo-content-ai::filament.projects.site_planning_over_warning') }}">⚠</span>@endif
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div
        class="cp-site-planning__drawer"
        x-show="open"
        x-cloak
        x-transition.opacity
        @keydown.escape.window="close()"
    >
        <div class="cp-site-planning__drawer-backdrop" @click="close()"></div>
        <aside class="cp-site-planning__drawer-panel" role="dialog" aria-modal="true">
            <div class="cp-site-planning__drawer-head">
                <h3 class="cp-site-planning__drawer-title">
                    {{ __('seo-content-ai::filament.projects.site_planning_detail_heading') }}
                </h3>
                <button type="button" class="cp-site-planning__drawer-close" @click="close()">×</button>
            </div>
            <div class="cp-site-planning__drawer-body" x-show="loading">
                <div class="animate-pulse space-y-2 p-2">
                    <div class="h-3 rounded bg-gray-200 dark:bg-gray-700"></div>
                    <div class="h-3 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                    <div class="h-16 rounded bg-gray-200 dark:bg-gray-700"></div>
                </div>
            </div>
            <div class="cp-site-planning__drawer-body" x-show="!loading && detail">
                <template x-if="detail">
                    <div class="space-y-3 text-xs">
                        <div>
                            <div class="font-semibold" x-text="detail.domain"></div>
                            <div class="text-gray-500" x-text="detail.month_label"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>Draft: <span x-text="detail.totals?.draft ?? 0"></span></div>
                            <div>Execution: <span x-text="detail.totals?.execution ?? 0"></span></div>
                            <div>Generated: <span x-text="detail.totals?.by_status?.generated ?? 0"></span></div>
                            <div>Editing: <span x-text="detail.totals?.by_status?.editing ?? 0"></span></div>
                            <div>Scheduled: <span x-text="detail.totals?.by_status?.scheduled ?? 0"></span></div>
                            <div>Published: <span x-text="detail.totals?.by_status?.published ?? 0"></span></div>
                        </div>
                        <template x-for="cluster in (detail.clusters || [])" :key="cluster.cluster_ref">
                            <div class="rounded border border-gray-200 p-2 dark:border-gray-700">
                                <div class="font-medium" x-text="cluster.cluster_name || cluster.cluster_ref"></div>
                                <div class="mt-1 text-[11px] text-gray-600 dark:text-gray-300">
                                    {{ __('seo-content-ai::filament.projects.site_planning_actual_mcp') }}
                                    <span x-text="(cluster.actual_mcp_share ?? 0).toFixed(1) + '%'"></span>
                                    ·
                                    {{ __('seo-content-ai::filament.projects.site_planning_planned_mcp') }}
                                    <span x-text="(cluster.planning_mcp_share ?? 0).toFixed(1) + '%'"></span>
                                </div>
                                <div class="text-[11px] text-gray-600 dark:text-gray-300">
                                    {{ __('seo-content-ai::filament.projects.site_planning_dna_current') }}
                                    <span x-text="cluster.dna_current ?? 0"></span>
                                    ·
                                    {{ __('seo-content-ai::filament.projects.site_planning_dna_planned') }}
                                    <span x-text="cluster.dna_planned ?? 0"></span>
                                    ·
                                    <span x-text="(cluster.article_count ?? 0) + ' bài'"></span>
                                </div>
                            </div>
                        </template>
                        <div x-show="(detail.unattributed?.count ?? 0) > 0" class="text-[11px] text-amber-700 dark:text-amber-300">
                            {{ __('seo-content-ai::filament.projects.site_planning_unattributed') }}:
                            <span x-text="detail.unattributed?.count ?? 0"></span>
                        </div>
                    </div>
                </template>
            </div>
        </aside>
    </div>
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
    .cp-site-planning__domain-head,
    .cp-site-planning__domain-cell {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #fff;
        min-width: 7.5rem;
        max-width: 10rem;
        padding: 0.4rem 0.5rem;
    }
    .dark .cp-site-planning__domain-head,
    .dark .cp-site-planning__domain-cell {
        background: rgb(17 24 39);
    }
    .cp-site-planning__domain {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 600;
    }
    .cp-site-planning__year-cell,
    .cp-site-planning__month-cell,
    .cp-site-planning__value {
        text-align: center;
        padding: 0.35rem 0.45rem;
        white-space: nowrap;
    }
    .cp-site-planning__month-cell.is-current,
    .cp-site-planning__value.is-current {
        background: rgb(254 243 199 / 0.55);
    }
    .dark .cp-site-planning__month-cell.is-current,
    .dark .cp-site-planning__value.is-current {
        background: rgb(245 158 11 / 0.12);
    }
    .cp-site-planning__value.is-over {
        color: #b45309;
        font-weight: 600;
    }
    .cp-site-planning__warn {
        margin-left: 0.15rem;
    }
    .cp-site-planning__cell-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 1.5rem;
        border-radius: 0.25rem;
        cursor: pointer;
    }
    .cp-site-planning__cell-btn:hover {
        background: rgb(148 163 184 / 0.15);
    }
    .cp-site-planning__drawer {
        position: fixed;
        inset: 0;
        z-index: 80;
    }
    .cp-site-planning__drawer-backdrop {
        position: absolute;
        inset: 0;
        background: rgb(15 23 42 / 0.35);
    }
    .cp-site-planning__drawer-panel {
        position: absolute;
        top: 0;
        right: 0;
        height: 100%;
        width: min(24rem, 92vw);
        background: #fff;
        box-shadow: -8px 0 24px rgb(15 23 42 / 0.18);
        display: flex;
        flex-direction: column;
        transform: translateX(0);
    }
    .dark .cp-site-planning__drawer-panel {
        background: rgb(17 24 39);
    }
    .cp-site-planning__drawer-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid rgb(229 231 235);
    }
    .dark .cp-site-planning__drawer-head {
        border-bottom-color: rgb(255 255 255 / 0.08);
    }
    .cp-site-planning__drawer-title {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 700;
    }
    .cp-site-planning__drawer-close {
        font-size: 1.25rem;
        line-height: 1;
        padding: 0.15rem 0.4rem;
    }
    .cp-site-planning__drawer-body {
        flex: 1 1 auto;
        overflow: auto;
        padding: 0.75rem 1rem 1.25rem;
    }
</style>
