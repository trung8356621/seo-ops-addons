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
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.site_planning_empty') }}
        </p>
    @else
        <div class="cp-site-planning__scroll overflow-x-auto rounded border border-gray-200 dark:border-white/10">
            <table class="cp-site-planning__table w-full min-w-max border-collapse text-left text-xs">
                <thead>
                    <tr class="cp-site-planning__year-row border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-900/60">
                        <th
                            scope="col"
                            rowspan="2"
                            class="cp-site-planning__sticky px-2 py-1 align-middle text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                        >
                            Domain
                        </th>
                        @foreach ($yearGroups as $group)
                            <th
                                scope="colgroup"
                                colspan="{{ (int) ($group['span'] ?? 1) }}"
                                class="px-1 py-0.5 text-center text-[11px] font-semibold tabular-nums text-gray-600 dark:text-gray-300"
                            >
                                {{ (int) ($group['year'] ?? 0) }}
                            </th>
                        @endforeach
                    </tr>
                    <tr class="cp-site-planning__month-row border-b border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-gray-900/40">
                        @foreach ($months as $month)
                            <th
                                scope="col"
                                @class([
                                    'px-1.5 py-0.5 text-center text-[11px] font-medium tabular-nums text-gray-500 dark:text-gray-400',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => (bool) ($month['is_current'] ?? false),
                                ])
                            >
                                {{ $month['month'] ?? '' }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        @php
                            $siteId = (int) ($row['site_id'] ?? 0);
                            $monthCells = is_array($row['months'] ?? null) ? $row['months'] : [];
                        @endphp
                        <tr
                            class="cp-site-planning__row hover:bg-gray-50/80 dark:hover:bg-white/[0.03]"
                            data-site-planning-row="{{ $siteId }}"
                        >
                            <th
                                scope="row"
                                class="cp-site-planning__sticky max-w-[11rem] px-2 py-1 align-middle font-medium text-gray-900 dark:text-gray-100"
                            >
                                <div class="truncate" title="{{ $row['domain'] ?? ('#'.$siteId) }}">
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
                                        'whitespace-nowrap px-1.5 py-1 text-center tabular-nums',
                                        'bg-amber-50/70 dark:bg-amber-500/10' => $isCurrent,
                                        'font-semibold text-amber-700 dark:text-amber-300' => $over,
                                        'text-gray-700 dark:text-gray-200' => ! $over,
                                    ])
                                    title="{{ $over ? __('seo-content-ai::filament.projects.site_planning_over_warning') : '' }}"
                                >
                                    {{ $planned }} / {{ $target }}@if ($over)<span class="ml-0.5" aria-label="{{ __('seo-content-ai::filament.projects.site_planning_over_warning') }}">⚠</span>@endif
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
    .cp-site-planning__sticky {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #fff;
        box-shadow: 1px 0 0 0 rgb(229 231 235);
        min-width: 8.5rem;
    }
    .dark .cp-site-planning__sticky {
        background: rgb(17 24 39);
        box-shadow: 1px 0 0 0 rgb(255 255 255 / 0.1);
    }
    thead .cp-site-planning__sticky {
        z-index: 3;
        background: rgb(249 250 251);
    }
    .dark thead .cp-site-planning__sticky {
        background: rgb(17 24 39 / 0.95);
    }
    .cp-site-planning__table th,
    .cp-site-planning__table td {
        line-height: 1.25;
    }
</style>
