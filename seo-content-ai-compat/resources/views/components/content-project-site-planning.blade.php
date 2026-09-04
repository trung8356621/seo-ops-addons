@php
    $payload = method_exists($this, 'sitePlanningPayload') ? $this->sitePlanningPayload() : [
        'months' => [],
        'rows' => [],
        'selected_site_id' => null,
        'detail' => null,
    ];
    $months = is_array($payload['months'] ?? null) ? $payload['months'] : [];
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $selectedSiteId = (int) ($payload['selected_site_id'] ?? 0);
    $detail = is_array($payload['detail'] ?? null) ? $payload['detail'] : null;
@endphp

<div class="cp-site-planning space-y-3" wire:key="cp-site-planning-panel" data-site-planning="1">
    @if ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.site_planning_empty') }}
        </p>
    @else
        <div class="cp-site-planning__layout grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <div class="cp-site-planning__list space-y-2">
                @foreach ($rows as $row)
                    @php
                        $siteId = (int) ($row['site_id'] ?? 0);
                        $isSelected = $siteId === $selectedSiteId;
                        $monthCells = is_array($row['months'] ?? null) ? $row['months'] : [];
                    @endphp
                    <button
                        type="button"
                        wire:click="selectSitePlanningSite({{ $siteId }})"
                        wire:loading.attr="disabled"
                        wire:target="selectSitePlanningSite"
                        @class([
                            'w-full rounded-lg border px-3 py-2.5 text-left transition',
                            'border-primary-400 bg-primary-50/60 dark:border-primary-500/50 dark:bg-primary-500/10' => $isSelected,
                            'border-gray-200 bg-white hover:border-gray-300 dark:border-white/10 dark:bg-gray-900/40' => ! $isSelected,
                        ])
                        data-site-planning-row="{{ $siteId }}"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $row['domain'] ?? ('#'.$siteId) }}
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.site_planning_ideas_summary', [
                                        'total' => (int) ($row['ideas_total'] ?? 0),
                                        'new' => (int) ($row['ideas_new'] ?? 0),
                                    ]) }}
                                    @if ((int) ($row['mcp_planning_count'] ?? 0) > 0)
                                        <span class="ml-1 text-sky-700 dark:text-sky-300">+{{ (int) $row['mcp_planning_count'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                            @foreach ($monthCells as $cell)
                                @php
                                    $planned = (int) ($cell['planned'] ?? 0);
                                    $target = (int) ($cell['target'] ?? 0);
                                    $over = (bool) ($cell['over_target'] ?? false);
                                    $isCurrent = (bool) ($cell['is_current'] ?? false);
                                @endphp
                                <div @class([
                                    'rounded-md px-1.5 py-1 text-center',
                                    'bg-amber-50 dark:bg-amber-500/10' => $isCurrent,
                                    'bg-gray-50 dark:bg-white/5' => ! $isCurrent,
                                ])>
                                    <div class="text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ $cell['label'] ?? '' }}</div>
                                    <div @class([
                                        'text-xs font-semibold',
                                        'text-amber-700 dark:text-amber-300' => $over,
                                        'text-gray-800 dark:text-gray-100' => ! $over,
                                    ])>
                                        {{ $planned }} / {{ $target }}
                                        @if ($over)
                                            <span title="{{ __('seo-content-ai::filament.projects.site_planning_over_warning') }}">⚠</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </button>
                @endforeach
            </div>

            <div class="cp-site-planning__detail rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900/40">
                @if ($detail === null)
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.site_planning_select_site') }}</p>
                @else
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ $detail['domain'] ?? '' }}
                    </h4>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.projects.site_planning_ideas_summary', [
                            'total' => (int) ($detail['ideas_total'] ?? 0),
                            'new' => (int) ($detail['ideas_new'] ?? 0),
                        ]) }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-md bg-sky-50 px-2 py-1 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200">
                            {{ __('seo-content-ai::filament.projects.site_planning_mcp_planning') }}:
                            +{{ (int) ($detail['mcp_planning_count'] ?? 0) }}
                        </span>
                        <span class="rounded-md bg-gray-50 px-2 py-1 text-gray-700 dark:bg-white/5 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.site_planning_draft_reviewed') }}:
                            {{ (int) ($detail['draft_reviewed'] ?? 0) }}
                        </span>
                        <span class="rounded-md bg-gray-50 px-2 py-1 text-gray-700 dark:bg-white/5 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.site_planning_execution_pending') }}:
                            {{ (int) ($detail['execution_pending'] ?? 0) }}
                        </span>
                    </div>
                    <div class="mt-3 space-y-1.5">
                        @foreach (($detail['months'] ?? []) as $cell)
                            @php
                                $planned = (int) ($cell['planned'] ?? 0);
                                $target = (int) ($cell['target'] ?? 0);
                                $over = (bool) ($cell['over_target'] ?? false);
                                $isCurrent = (bool) ($cell['is_current'] ?? false);
                            @endphp
                            <div @class([
                                'flex items-center justify-between rounded-md px-2 py-1.5 text-sm',
                                'bg-amber-50 dark:bg-amber-500/10' => $isCurrent,
                                'bg-gray-50 dark:bg-white/5' => ! $isCurrent,
                            ])>
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $cell['label'] ?? '' }}</span>
                                <span @class([
                                    'font-semibold',
                                    'text-amber-700 dark:text-amber-300' => $over,
                                    'text-gray-900 dark:text-gray-100' => ! $over,
                                ])>
                                    {{ __('seo-content-ai::filament.projects.site_planning_planned_label') }}
                                    {{ $planned }} /
                                    {{ __('seo-content-ai::filament.projects.site_planning_target_label') }}
                                    {{ $target }}
                                    @if ($over)
                                        ⚠
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
