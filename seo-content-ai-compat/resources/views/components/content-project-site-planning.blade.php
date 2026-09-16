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
    $workingSiteId = (int) ($this->filterSiteId ?? 0);
@endphp

<div
    class="cp-site-planning"
    wire:key="cp-site-planning-panel-{{ $activeMonth }}-{{ $workingSiteId }}"
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
        },
        summaryArticles() {
            return Number(this.detail?.totals?.planned ?? 0);
        },
        summaryDnaDelta() {
            const clusters = Array.isArray(this.detail?.clusters) ? this.detail.clusters : [];
            let delta = 0;
            for (const c of clusters) {
                delta += Number(c.dna_planned ?? 0) - Number(c.dna_current ?? 0);
            }
            return delta;
        },
        summaryMcpDelta() {
            const clusters = Array.isArray(this.detail?.clusters) ? this.detail.clusters : [];
            if (clusters.length === 0) {
                return 0;
            }
            let sum = 0;
            for (const c of clusters) {
                sum += Number(c.planning_mcp_share ?? 0) - Number(c.actual_mcp_share ?? 0);
            }
            return Math.round(sum / clusters.length);
        },
    }"
    x-init="
        @if ($workingSiteId > 0 && $activeMonth !== '')
            openCell({{ $workingSiteId }}, '{{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($activeMonth) }}')
        @endif
    "
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
                            {{ __('seo-content-ai::filament.projects.site_planning_domain_col') }}
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

    {{-- Inline monthly detail (mockup: below matrix, not a full-screen drawer) --}}
    <div
        class="cp-site-planning__detail"
        data-site-planning-detail="1"
        x-show="open"
        x-cloak
        x-transition.opacity
    >
        <div class="cp-site-planning__detail-head">
            <div>
                <h4 class="cp-site-planning__detail-title">
                    <span x-text="detail ? ('{{ __('seo-content-ai::filament.projects.site_planning_detail_heading') }} ' + (detail.month_label || '') + ' — ' + (detail.domain || '')) : '{{ __('seo-content-ai::filament.projects.site_planning_detail_heading') }}'"></span>
                </h4>
            </div>
            <button type="button" class="cp-site-planning__detail-close" @click="close()" aria-label="{{ __('seo-content-ai::filament.projects.site_planning_detail_close') }}">×</button>
        </div>

        <div class="cp-site-planning__detail-body" x-show="loading">
            <div class="animate-pulse space-y-2 p-1">
                <div class="h-3 rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-3 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-16 rounded bg-gray-200 dark:bg-gray-700"></div>
            </div>
        </div>

        <div class="cp-site-planning__detail-body" x-show="!loading && detail">
            <template x-if="detail">
                <div class="space-y-3 text-xs">
                    <div class="cp-site-planning__totals grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div>{{ __('seo-content-ai::filament.projects.site_planning_total_draft') }}: <span class="font-semibold" x-text="detail.totals?.draft ?? 0"></span></div>
                        <div>{{ __('seo-content-ai::filament.projects.site_planning_total_execution') }}: <span class="font-semibold" x-text="detail.totals?.execution ?? 0"></span></div>
                        <div>{{ __('seo-content-ai::filament.projects.site_planning_total_planned') }}: <span class="font-semibold" x-text="detail.totals?.planned ?? 0"></span></div>
                    </div>

                    <div class="cp-site-planning__source-counts text-[11px] text-gray-600 dark:text-gray-300" data-site-planning-source-counts="1" x-show="detail.source_counts && Object.keys(detail.source_counts).length">
                        <span class="font-medium">{{ __('seo-content-ai::filament.projects.site_planning_source_heading') }}:</span>
                        <template x-for="(count, key) in (detail.source_counts || {})" :key="key">
                            <span class="ml-1 inline-flex gap-0.5">
                                <span x-text="key"></span>
                                <span x-text="count"></span>
                            </span>
                        </template>
                    </div>

                    <div class="cp-site-planning__attr-counts text-[11px] text-gray-600 dark:text-gray-300" data-site-planning-attr-counts="1">
                        <span>{{ __('seo-content-ai::filament.projects.site_planning_attributed') }}: <span class="font-semibold" x-text="detail.attributed?.count ?? 0"></span></span>
                        <span class="mx-1" aria-hidden="true">·</span>
                        <span>{{ __('seo-content-ai::filament.projects.site_planning_unattributed') }}: <span class="font-semibold" x-text="detail.unattributed?.count ?? 0"></span></span>
                    </div>

                    <div class="cp-site-planning__clusters space-y-2">
                        <template x-for="(cluster, idx) in (detail.clusters || [])" :key="cluster.cluster_ref || idx">
                            <div class="cp-site-planning__cluster-row">
                                <div class="cp-site-planning__cluster-idx" x-text="idx + 1"></div>
                                <div class="cp-site-planning__cluster-main">
                                    <div class="font-medium text-gray-900 dark:text-gray-100" x-text="cluster.cluster_name || cluster.cluster_ref"></div>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        <span class="cp-site-planning__tag cp-site-planning__tag--mcp">
                                            MCP
                                            <span x-text="(cluster.actual_mcp_share ?? 0).toFixed(0) + '%'"></span>
                                            →
                                            <span x-text="(cluster.planning_mcp_share ?? 0).toFixed(0) + '%'"></span>
                                        </span>
                                        <span class="cp-site-planning__tag cp-site-planning__tag--dna">
                                            DNA
                                            <span x-text="cluster.dna_current ?? 0"></span>
                                            →
                                            <span x-text="cluster.dna_planned ?? 0"></span>
                                        </span>
                                        <span class="cp-site-planning__tag cp-site-planning__tag--count" x-text="(cluster.article_count ?? 0) + ' {{ __('seo-content-ai::filament.projects.site_planning_articles_unit') }}'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-show="(detail.unattributed?.count ?? 0) > 0 && (detail.clusters || []).length === 0" class="text-[11px] text-amber-700 dark:text-amber-300">
                        {{ __('seo-content-ai::filament.projects.site_planning_unattributed') }}:
                        <span x-text="detail.unattributed?.count ?? 0"></span>
                    </div>
                </div>
            </template>
        </div>

        <div
            class="cp-site-planning__summary"
            data-site-planning-summary="1"
            x-show="!loading && detail"
            x-cloak
        >
            <span x-text="summaryArticles() + ' {{ __('seo-content-ai::filament.projects.site_planning_articles_unit') }}'"></span>
            <span aria-hidden="true">·</span>
            <span x-text="(summaryDnaDelta() >= 0 ? '+' : '') + summaryDnaDelta() + ' DNA'"></span>
            <span aria-hidden="true">·</span>
            <span x-text="'MCP {{ __('seo-content-ai::filament.projects.site_planning_mcp_forecast') }} ' + (summaryMcpDelta() >= 0 ? '+' : '') + summaryMcpDelta() + '%'"></span>
        </div>
    </div>
</div>

<style>
    .cp-site-planning {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
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
        box-shadow: inset 0 0 0 1px rgb(245 158 11 / 0.45);
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
    .cp-site-planning__detail {
        border: 1px solid var(--cp-plan-border, #e5e7eb);
        border-radius: 0.625rem;
        background: #fff;
        overflow: hidden;
    }
    .dark .cp-site-planning__detail {
        border-color: rgb(255 255 255 / 0.1);
        background: rgb(17 24 39);
    }
    .cp-site-planning__detail-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.65rem 0.75rem;
        border-bottom: 1px solid rgb(229 231 235);
    }
    .dark .cp-site-planning__detail-head {
        border-bottom-color: rgb(255 255 255 / 0.08);
    }
    .cp-site-planning__detail-title {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1.3;
        color: #111827;
    }
    .dark .cp-site-planning__detail-title {
        color: #f3f4f6;
    }
    .cp-site-planning__detail-close {
        font-size: 1.15rem;
        line-height: 1;
        padding: 0.1rem 0.35rem;
        color: #6b7280;
    }
    .cp-site-planning__detail-body {
        padding: 0.65rem 0.75rem;
        max-height: 14rem;
        overflow: auto;
    }
    .cp-site-planning__cluster-row {
        display: grid;
        grid-template-columns: 1.5rem minmax(0, 1fr);
        gap: 0.5rem;
        padding: 0.5rem;
        border-radius: 0.5rem;
        border: 1px solid rgb(229 231 235 / 0.9);
    }
    .dark .cp-site-planning__cluster-row {
        border-color: rgb(255 255 255 / 0.08);
    }
    .cp-site-planning__cluster-idx {
        font-weight: 700;
        color: #6b7280;
        padding-top: 0.1rem;
    }
    .cp-site-planning__tag {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        border-radius: 999px;
        padding: 0.15rem 0.45rem;
        font-size: 0.65rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .cp-site-planning__tag--mcp {
        background: rgb(220 252 231);
        color: rgb(21 128 61);
    }
    .cp-site-planning__tag--dna {
        background: rgb(219 234 254);
        color: rgb(29 78 216);
    }
    .cp-site-planning__tag--count {
        background: rgb(243 244 246);
        color: rgb(55 65 81);
    }
    .dark .cp-site-planning__tag--mcp {
        background: rgb(22 101 52 / 0.35);
        color: rgb(134 239 172);
    }
    .dark .cp-site-planning__tag--dna {
        background: rgb(30 64 175 / 0.35);
        color: rgb(147 197 253);
    }
    .dark .cp-site-planning__tag--count {
        background: rgb(255 255 255 / 0.08);
        color: rgb(209 213 219);
    }
    .cp-site-planning__summary {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem 0.55rem;
        padding: 0.55rem 0.75rem;
        background: rgb(220 252 231 / 0.55);
        border-top: 1px solid rgb(187 247 208);
        font-size: 0.75rem;
        font-weight: 600;
        color: rgb(21 128 61);
    }
    .dark .cp-site-planning__summary {
        background: rgb(22 101 52 / 0.25);
        border-top-color: rgb(34 197 94 / 0.25);
        color: rgb(134 239 172);
    }
</style>
