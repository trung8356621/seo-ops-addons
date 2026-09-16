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
            const topics = Array.isArray(this.detail?.topics) ? this.detail.topics : [];
            return topics.reduce((sum, t) => sum + Number(t.planned_article_count || 0), 0);
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

    {{-- Inline monthly detail: Topic History only --}}
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
                <div class="space-y-3 text-xs" data-site-planning-topic-history="1">
                    <template x-if="(detail.topics || []).length === 0">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.site_planning_topic_history_empty') }}</p>
                    </template>
                    <div class="space-y-2.5">
                        <template x-for="(topic, tIdx) in (detail.topics || [])" :key="topic.topic_ref || ('t-' + tIdx)">
                            <div class="cp-site-planning__cluster-row">
                                <div class="cp-site-planning__cluster-idx" x-text="tIdx + 1"></div>
                                <div class="cp-site-planning__cluster-main">
                                    <div class="font-medium text-gray-900 dark:text-gray-100" x-text="topic.topic_name"></div>
                                    <div class="mt-1">
                                        <span class="cp-site-planning__tag cp-site-planning__tag--count" x-text="(topic.planned_article_count || 0) + ' {{ __('seo-content-ai::filament.projects.site_planning_articles_unit') }}'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
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
        text-align: left;
        font-weight: 600;
        box-shadow: 1px 0 0 rgb(229 231 235 / 0.8);
    }
    .dark .cp-site-planning__domain-head,
    .dark .cp-site-planning__domain-cell {
        background: #111827;
        box-shadow: 1px 0 0 rgb(255 255 255 / 0.08);
    }
    .cp-site-planning__domain {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .cp-site-planning__year-cell,
    .cp-site-planning__month-cell,
    .cp-site-planning__value {
        padding: 0.35rem 0.45rem;
        text-align: center;
        white-space: nowrap;
    }
    .cp-site-planning__month-cell.is-current,
    .cp-site-planning__value.is-current {
        background: rgb(59 130 246 / 0.08);
    }
    .dark .cp-site-planning__month-cell.is-current,
    .dark .cp-site-planning__value.is-current {
        background: rgb(59 130 246 / 0.16);
    }
    .cp-site-planning__value.is-over {
        color: #b45309;
    }
    .cp-site-planning__warn {
        margin-left: 0.15rem;
    }
    .cp-site-planning__cell-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 3.25rem;
        padding: 0.15rem 0.25rem;
        border-radius: 0.25rem;
        font: inherit;
        color: inherit;
        background: transparent;
        cursor: pointer;
    }
    .cp-site-planning__cell-btn:hover {
        background: rgb(15 23 42 / 0.06);
    }
    .cp-site-planning__detail {
        border: 1px solid var(--cp-plan-border, #e5e7eb);
        border-radius: 0.625rem;
        background: #fff;
        overflow: hidden;
    }
    .dark .cp-site-planning__detail {
        border-color: rgb(255 255 255 / 0.1);
        background: rgb(17 24 39 / 0.6);
    }
    .cp-site-planning__detail-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.55rem 0.65rem;
        border-bottom: 1px solid rgb(229 231 235 / 0.8);
    }
    .dark .cp-site-planning__detail-head {
        border-bottom-color: rgb(255 255 255 / 0.08);
    }
    .cp-site-planning__detail-title {
        margin: 0;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #111827;
    }
    .dark .cp-site-planning__detail-title {
        color: #f3f4f6;
    }
    .cp-site-planning__detail-close {
        border: 0;
        background: transparent;
        cursor: pointer;
        font-size: 1.1rem;
        line-height: 1;
        color: #6b7280;
    }
    .cp-site-planning__detail-body {
        padding: 0.65rem;
    }
    .cp-site-planning__cluster-row {
        display: flex;
        gap: 0.55rem;
        padding-bottom: 0.55rem;
        border-bottom: 1px solid rgb(229 231 235 / 0.55);
    }
    .dark .cp-site-planning__cluster-row {
        border-bottom-color: rgb(255 255 255 / 0.06);
    }
    .cp-site-planning__cluster-idx {
        flex: 0 0 auto;
        width: 1.25rem;
        color: #9ca3af;
        font-variant-numeric: tabular-nums;
    }
    .cp-site-planning__tag {
        display: inline-flex;
        align-items: center;
        padding: 0.1rem 0.4rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .cp-site-planning__tag--count {
        background: rgb(16 185 129 / 0.12);
        color: #047857;
    }
    .dark .cp-site-planning__tag--count {
        background: rgb(16 185 129 / 0.2);
        color: #6ee7b7;
    }
    .cp-site-planning__summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 0.55rem;
        padding: 0.45rem 0.65rem 0.6rem;
        border-top: 1px solid rgb(229 231 235 / 0.8);
        font-size: 0.7rem;
        color: #6b7280;
    }
    .dark .cp-site-planning__summary {
        border-top-color: rgb(255 255 255 / 0.08);
        color: #9ca3af;
    }
</style>
