@php
    $preview = is_array($this->gscMcpPreview) ? $this->gscMcpPreview : [];
    $metrics = is_array($preview['metrics'] ?? null) ? $preview['metrics'] : [];
@endphp

<div
    x-data="{ open: @entangle('gscMcpDrawerOpen') }"
    x-cloak
    x-show="open"
    x-transition.opacity
    class="performance-hub-gsc-mcp-drawer-backdrop"
    @keydown.escape.window="$wire.closeGscMcpDrawer()"
>
    <div
        class="performance-hub-gsc-mcp-drawer"
        @click.outside="$wire.closeGscMcpDrawer()"
        x-show="open"
        x-transition:enter="transform transition-transform duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition-transform duration-300"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
    >
        <div class="performance-hub-gsc-mcp-drawer__head">
            <div>
                <h3>{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_drawer_title', ['month' => $preview['period_label'] ?? '']) }}</h3>
                <p class="performance-hub-gsc-mcp-drawer__meta">
                    {{ __('seo-content-ai::filament.performance_hub.gsc_mcp_source_period', [
                        'start' => $preview['source_period']['start'] ?? '',
                        'end' => $preview['source_period']['end'] ?? '',
                    ]) }}
                </p>
            </div>
            <button type="button" class="performance-hub-gsc-mcp-drawer__close" @click="$wire.closeGscMcpDrawer()">×</button>
        </div>

        <div class="performance-hub-gsc-mcp-drawer__body">
            @if ($this->gscMcpDrawerLoading)
                <div class="performance-hub-gsc-mcp-drawer__skeleton animate-pulse space-y-3">
                    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
                    <div class="h-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                    <div class="h-32 bg-gray-200 dark:bg-gray-700 rounded"></div>
                </div>
            @elseif (($preview['status'] ?? '') === 'no_site')
                <p>{{ __('seo-content-ai::filament.performance_hub.no_domain') }}</p>
            @elseif (($preview['status'] ?? '') === 'absent')
                <div class="performance-hub-empty-state">
                    <p>{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_absent', ['month' => $preview['period_label'] ?? '']) }}</p>
                    @if (($preview['absent_reason'] ?? '') !== '')
                        <p class="text-sm text-gray-500">{{ $preview['absent_reason'] }}</p>
                    @endif
                    <button
                        type="button"
                        wire:click="rebuildGscMcpSnapshot"
                        wire:loading.attr="disabled"
                        wire:target="rebuildGscMcpSnapshot"
                        class="performance-hub-action-btn mt-3"
                    >
                        <span wire:loading.remove wire:target="rebuildGscMcpSnapshot">{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_build') }}</span>
                        <span wire:loading wire:target="rebuildGscMcpSnapshot">{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_building') }}</span>
                    </button>
                </div>
            @else
                <dl class="performance-hub-gsc-mcp-drawer__metrics">
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_status') }}</dt><dd>{{ ($preview['status'] ?? '') === 'stored' ? __('seo-content-ai::filament.performance_hub.gsc_mcp_status_ready') : __('seo-content-ai::filament.performance_hub.gsc_mcp_status_live') }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_generated_at') }}</dt><dd>{{ \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($preview['generated_at'] ?? null) ?? '—' }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.kpi_clicks') }}</dt><dd>{{ number_format((int) ($metrics['clicks'] ?? 0)) }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.kpi_impressions') }}</dt><dd>{{ number_format((int) ($metrics['impressions'] ?? 0)) }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.kpi_ctr') }}</dt><dd>{{ isset($metrics['ctr']) ? number_format((float) $metrics['ctr'] * 100, 2).'%' : '—' }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.kpi_position') }}</dt><dd>{{ isset($metrics['avg_position']) ? number_format((float) $metrics['avg_position'], 1) : (isset($metrics['position']) ? number_format((float) $metrics['position'], 1) : '—') }}</dd></div>
                    <div><dt>{{ __('seo-content-ai::filament.performance_hub.kpi_total_queries') }}</dt><dd>{{ number_format((int) ($metrics['query_count'] ?? 0)) }}</dd></div>
                </dl>

                @if (! empty($preview['context']['ai_lines'] ?? []))
                    <div class="performance-hub-gsc-mcp-drawer__section">
                        <h4>{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_summary') }}</h4>
                        <ul class="list-disc pl-5 text-sm space-y-1">
                            @foreach ($preview['context']['ai_lines'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php
                    $socialTop = is_array($preview['social_top10'] ?? null) ? $preview['social_top10'] : [];
                    $socialItems = is_array($socialTop['items'] ?? null) ? $socialTop['items'] : [];
                @endphp
                <div class="performance-hub-gsc-mcp-drawer__section mt-4">
                    <h4>{{ __('seo-content-ai::filament.performance_hub.gsc_social_top10_title', ['month' => $preview['period_label'] ?? '']) }}</h4>
                    @if (($socialTop['unmapped_pages'] ?? 0) > 0)
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.performance_hub.gsc_social_unmapped', ['count' => (int) $socialTop['unmapped_pages']]) }}
                        </p>
                    @endif
                    @if ($socialItems === [])
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.performance_hub.gsc_social_empty') }}
                        </p>
                    @else
                        <ol class="space-y-3 text-sm">
                            @foreach ($socialItems as $index => $item)
                                @php
                                    $itemTitle = trim((string) ($item['title'] ?? ''));
                                    $itemPath = trim((string) ($item['path'] ?? ''));
                                    $itemUrl = trim((string) ($item['url'] ?? ''));
                                    $itemQueries = is_array($item['queries'] ?? null) ? $item['queries'] : [];
                                    $itemReasons = is_array($item['reason_tags'] ?? null) ? $item['reason_tags'] : [];
                                    $itemImpressions = (int) ($item['impressions'] ?? 0);
                                    $itemPosition = $item['position'] ?? null;
                                @endphp
                                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" wire:key="gsc-social-{{ (int) ($item['article_id'] ?? 0) }}-{{ $index }}">
                                    <div class="font-medium text-gray-950 dark:text-white">
                                        {{ $index + 1 }}. {{ $itemTitle !== '' ? $itemTitle : '—' }}
                                    </div>
                                    @if ($itemPath !== '')
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $itemPath }}</div>
                                    @endif
                                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                        {{ number_format($itemImpressions) }} impressions
                                        @if ($itemPosition !== null)
                                            · {{ __('seo-content-ai::filament.performance_hub.gsc_social_position', ['pos' => number_format((float) $itemPosition, 1)]) }}
                                        @endif
                                    </div>
                                    @if ($itemReasons !== [])
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($itemReasons as $tag)
                                                <span class="inline-flex rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($itemQueries !== [])
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Query: {{ implode(', ', array_slice($itemQueries, 0, 5)) }}
                                        </div>
                                    @endif
                                    <div class="mt-2">
                                        <x-seo-content-ai::social-share-actions
                                            :title="$itemTitle"
                                            :url="$itemUrl"
                                            :compact="true"
                                        />
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>

                <div class="performance-hub-gsc-mcp-drawer__actions">
                    <button type="button" wire:click="toggleGscMcpRaw" class="performance-hub-action-btn performance-hub-action-btn--secondary">
                        {{ $this->gscMcpShowRaw ? __('seo-content-ai::filament.performance_hub.gsc_mcp_hide_raw') : __('seo-content-ai::filament.performance_hub.gsc_mcp_show_raw') }}
                    </button>
                    <button
                        type="button"
                        wire:click="rebuildGscMcpSnapshot"
                        wire:loading.attr="disabled"
                        wire:target="rebuildGscMcpSnapshot"
                        class="performance-hub-action-btn"
                    >
                        <span wire:loading.remove wire:target="rebuildGscMcpSnapshot">{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_build') }}</span>
                        <span wire:loading wire:target="rebuildGscMcpSnapshot">{{ __('seo-content-ai::filament.performance_hub.gsc_mcp_building') }}</span>
                    </button>
                </div>

                @if ($this->gscMcpShowRaw)
                    <pre class="performance-hub-gsc-mcp-drawer__raw">{{ $preview['raw_json'] ?? '' }}</pre>
                @endif
            @endif
        </div>
    </div>
</div>
