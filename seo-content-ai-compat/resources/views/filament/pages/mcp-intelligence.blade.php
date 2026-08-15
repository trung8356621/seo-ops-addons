@php
    $state = $this->viewState();
    $presenter = $this->presenter();
    /** @var \Omnichannel\Addons\Seo\Models\SeoMcpPeriod|null $period */
    $period = $state['period'];
    $site = $state['site'];
    $report = $state['report'];
    $siteCard = $state['site_card'];
    $kwCard = $state['keyword_card'];
    $kwMetrics = is_array($kwCard['metrics'] ?? null) ? $kwCard['metrics'] : [];
    $siteMetrics = is_array($siteCard['metrics'] ?? null) ? $siteCard['metrics'] : [];
    $kwSummary = is_array($state['keyword_snap']?->summary_json) ? $state['keyword_snap']->summary_json : [];
    $groups = is_array($kwSummary['groups'] ?? null) ? $kwSummary['groups'] : [];
    $weakGroups = array_values(array_filter($groups, static fn ($g): bool => is_array($g) && (int) ($g['count'] ?? 0) > 0 && (int) ($g['count'] ?? 0) <= 6));
    $clusters = is_array($kwSummary['clusters'] ?? null) ? $kwSummary['clusters'] : [];
    $overview = is_array($report?->overview_json) ? $report->overview_json : [];
    $highlights = is_array($report?->highlights_json) ? $report->highlights_json : [];
    $risks = is_array($report?->risks_json) ? $report->risks_json : [];
    $opps = is_array($report?->opportunities_json) ? $report->opportunities_json : [];
    $actions = is_array($report?->action_plan_json) ? $report->action_plan_json : [];
    $periodLabel = sprintf('%02d/%d', $this->selectedMonth(), $this->selectedYear());
    $isOpen = $period?->isOpen() ?? true;
    $reportStatus = (string) ($report->status ?? 'missing');
    $cssPath = base_path('addons/seo/resources/css/mcp-intelligence.css');
@endphp

<div>
    <x-filament-panels::page class="mcp-intelligence-page">
        @if (is_readable($cssPath))
            <style>{!! file_get_contents($cssPath) !!}</style>
        @endif

        <div class="mcp-report space-y-5" x-data="{ aiView: @entangle('aiContextView'), refreshOpen: false }">
            <header class="mcp-report__header">
                <div>
                    <h1 class="mcp-report__title">{{ __('seo-content-ai::filament.mcp_intelligence.title') }}</h1>
                    <p class="mcp-report__meta">
                        {{ $site?->domain ?? __('seo-content-ai::filament.mcp_intelligence.pick_site') }}
                        · {{ __('seo-content-ai::filament.mcp_intelligence.month_label', ['period' => $periodLabel]) }}
                        · {{ $period ? __('seo-content-ai::filament.mcp_intelligence.status_'.$period->status) : __('seo-content-ai::filament.mcp_intelligence.status_missing') }}
                    </p>
                </div>
                <div class="mcp-report__controls">
                    <x-select wire:model.live="periodKey" class="min-w-[8rem]">
                        @foreach ($this->periodOptions() as $opt)
                            <option value="{{ sprintf('%04d-%02d', $opt['year'], $opt['month']) }}">
                                {{ $opt['label'] }}
                                @if ($opt['exists'] && $opt['status'] === 'finalized')
                                    · {{ __('seo-content-ai::filament.mcp_intelligence.status_finalized') }}
                                @endif
                            </option>
                        @endforeach
                    </x-select>
                    @if ($period && $isOpen)
                        <div class="relative">
                            <button type="button" wire:click="refreshAll" wire:loading.attr="disabled" wire:target="refreshAll,generateReport,refreshSiteSnapshot,refreshKeywordSnapshot" class="mcp-btn mcp-btn--primary">
                                <span wire:loading.remove wire:target="refreshAll,generateReport">{{ __('seo-content-ai::filament.mcp_intelligence.refresh_all') }}</span>
                                <span wire:loading wire:target="refreshAll,generateReport">{{ __('seo-content-ai::filament.mcp_intelligence.updating_progress', ['current' => 1, 'total' => 2]) }}</span>
                            </button>
                            <button type="button" class="mcp-btn" @click="refreshOpen = !refreshOpen">▾</button>
                            <div x-show="refreshOpen" x-cloak class="mcp-menu">
                                <button type="button" wire:click="refreshSiteSnapshot" @click="refreshOpen = false">{{ __('seo-content-ai::filament.mcp_intelligence.refresh_site') }}</button>
                                <button type="button" wire:click="refreshKeywordSnapshot" @click="refreshOpen = false">{{ __('seo-content-ai::filament.mcp_intelligence.refresh_keywords') }}</button>
                            </div>
                        </div>
                        <button type="button" wire:click="requestFinalize" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.finalize') }}</button>
                    @elseif ($period)
                        <button type="button" wire:click="reopenPeriod" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.reopen') }}</button>
                    @endif
                    <button type="button" wire:click="openAiContext" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.view_ai_context') }}</button>
                </div>
            </header>

            @if ($site === null)
                <p class="mcp-empty">{{ __('seo-content-ai::filament.mcp_intelligence.need_global_domain') }}</p>
            @elseif ($state['changed_after_finalize'])
                <div class="mcp-banner mcp-banner--warn">{{ __('seo-content-ai::filament.mcp_intelligence.source_changed_after_finalize') }}</div>
            @endif

            @if ($site && ! $period)
                <div class="mcp-card">
                    <p>{{ __('seo-content-ai::filament.mcp_intelligence.empty_period', ['period' => $periodLabel]) }}</p>
                    <button type="button" wire:click="createPeriod" class="mcp-btn mcp-btn--primary mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.create_period', ['period' => $periodLabel]) }}</button>
                </div>
            @elseif ($site && $period)
                <section class="mcp-status-strip">
                    <div class="mcp-status">
                        <span class="mcp-status__label">{{ __('seo-content-ai::filament.mcp_intelligence.site_intel') }}</span>
                        <span class="mcp-dot {{ $presenter->freshnessClass($siteCard['freshness']) }}"></span>
                        <span>{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.$siteCard['freshness']) }}</span>
                        @if ($siteCard['relative'])
                            <span class="mcp-status__muted">{{ $siteCard['relative'] }}</span>
                        @endif
                    </div>
                    <div class="mcp-status">
                        <span class="mcp-status__label">{{ __('seo-content-ai::filament.mcp_intelligence.keyword_intel') }}</span>
                        <span class="mcp-dot {{ $presenter->freshnessClass($kwCard['freshness']) }}"></span>
                        <span>{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.$kwCard['freshness']) }}</span>
                        @if ($kwCard['relative'])
                            <span class="mcp-status__muted">{{ $kwCard['relative'] }}</span>
                        @endif
                    </div>
                    <div class="mcp-status">
                        <span class="mcp-status__label">{{ __('seo-content-ai::filament.mcp_intelligence.card_coverage') }}</span>
                        <span>{{ $state['source_ready'] }} / {{ $state['source_total'] }}</span>
                    </div>
                    <div class="mcp-status">
                        <span class="mcp-status__label">{{ __('seo-content-ai::filament.mcp_intelligence.card_report') }}</span>
                        <span class="mcp-badge {{ $presenter->reportStatusClass($reportStatus) }}">{{ __('seo-content-ai::filament.mcp_intelligence.report_'.$reportStatus) }}</span>
                    </div>
                </section>

                @if (! $report)
                    <div class="mcp-card">
                        <p>{{ __('seo-content-ai::filament.mcp_intelligence.empty_report', ['period' => $periodLabel]) }}</p>
                        @if ($isOpen)
                            <button type="button" wire:click="generateReport" class="mcp-btn mcp-btn--primary mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.create_report') }}</button>
                        @endif
                    </div>
                @else
                    <section>
                        <h2 class="mcp-h2">{{ __('seo-content-ai::filament.mcp_intelligence.overview') }}</h2>
                        <div class="mcp-metrics">
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_articles') }}</span><strong>{{ number_format((int) ($overview['article_total'] ?? $siteMetrics['article_total'] ?? 0)) }}</strong></div>
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_published') }}</span><strong>{{ number_format((int) ($overview['article_published'] ?? $siteMetrics['article_published'] ?? 0)) }}</strong></div>
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_focus') }}</span><strong>{{ number_format((int) ($overview['focus'] ?? $kwMetrics['focus'] ?? 0)) }}</strong></div>
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_error') }}</span><strong>{{ number_format((int) ($overview['error'] ?? $kwMetrics['error'] ?? 0)) }}</strong></div>
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_excluded') }}</span><strong>{{ number_format((int) ($overview['excluded'] ?? $kwMetrics['excluded'] ?? 0)) }}</strong></div>
                            <div class="mcp-metric"><span>{{ __('seo-content-ai::filament.mcp_intelligence.metric_clusters') }}</span><strong>{{ number_format((int) ($overview['clusters'] ?? $kwMetrics['clusters'] ?? 0)) }}</strong></div>
                        </div>
                    </section>
                @endif

                <div class="mcp-grid-2">
                    <section class="mcp-card">
                        <div class="mcp-card__head">
                            <h2>{{ __('seo-content-ai::filament.mcp_intelligence.site_intel') }}</h2>
                            <button type="button" wire:click="openSourcePreview('site')" class="mcp-link">{{ __('seo-content-ai::filament.mcp_intelligence.view_source') }}</button>
                        </div>
                        <dl class="mcp-dl">
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.site_health') }}</dt><dd>{{ $siteMetrics['health'] ?? '—' }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.indexability') }}</dt><dd>{{ ((int) ($siteMetrics['indexable'] ?? 0) + (int) ($siteMetrics['noindex'] ?? 0)) > 0 ? ((int) ($siteMetrics['noindex'] ?? 0).' '.__('seo-content-ai::filament.mcp_intelligence.issues')) : __('seo-content-ai::filament.mcp_intelligence.metric_unavailable') }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.critical_findings') }}</dt><dd>{{ (int) ($siteMetrics['critical_findings'] ?? 0) + (int) ($siteMetrics['high_findings'] ?? 0) }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_articles') }}</dt><dd>{{ number_format((int) ($siteMetrics['article_total'] ?? 0)) }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_published') }}</dt><dd>{{ number_format((int) ($siteMetrics['article_published'] ?? 0)) }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.internal_links') }}</dt><dd>{{ number_format((int) ($siteMetrics['internal_links'] ?? 0)) }}</dd></div>
                        </dl>
                    </section>
                    <section class="mcp-card">
                        <div class="mcp-card__head">
                            <h2>{{ __('seo-content-ai::filament.mcp_intelligence.keyword_intel') }}</h2>
                            <button type="button" wire:click="openSourcePreview('keywords')" class="mcp-link">{{ __('seo-content-ai::filament.mcp_intelligence.view_source') }}</button>
                        </div>
                        <dl class="mcp-dl">
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_focus') }}</dt><dd><a href="{{ \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource::buildOperationalTagFilterUrl('focus') }}">{{ number_format((int) ($kwMetrics['focus'] ?? 0)) }}</a></dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_error') }}</dt><dd><a href="{{ $this->keywordsErrorUrl() }}">{{ number_format((int) ($kwMetrics['error'] ?? 0)) }}</a></dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_excluded') }}</dt><dd>{{ number_format((int) ($kwMetrics['excluded'] ?? 0)) }}</dd></div>
                            <div><dt>{{ __('seo-content-ai::filament.mcp_intelligence.metric_clusters') }}</dt><dd><a href="{{ $this->keywordsUnclusteredUrl() }}">{{ number_format((int) ($kwMetrics['clusters'] ?? 0)) }}</a></dd></div>
                        </dl>
                        @if ($groups !== [])
                            <p class="mcp-sub">{{ __('seo-content-ai::filament.mcp_intelligence.top_groups') }}</p>
                            <div class="mcp-chips">
                                @foreach (array_slice($groups, 0, 6) as $group)
                                    <span>{{ $group['label'] ?? $group['key'] }} {{ (int) ($group['count'] ?? 0) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if ($weakGroups !== [])
                            <p class="mcp-sub">{{ __('seo-content-ai::filament.mcp_intelligence.weak_groups') }}</p>
                            <div class="mcp-chips">
                                @foreach (array_slice($weakGroups, 0, 6) as $group)
                                    <span>{{ $group['label'] ?? $group['key'] }} {{ (int) ($group['count'] ?? 0) }}</span>
                                @endforeach
                            </div>
                        @endif
                    </section>
                </div>

                @if ($clusters !== [])
                    <section class="mcp-card">
                        <h2 class="mcp-h2" style="margin-top:0">{{ __('seo-content-ai::filament.mcp_intelligence.top_clusters') }}</h2>
                        <ul class="mcp-list">
                            @foreach (array_slice($clusters, 0, 8) as $cluster)
                                @php $cid = (string) ($cluster['cluster_id'] ?? ''); @endphp
                                <li>
                                    <a href="{{ $this->clusterUrl($cid) }}">{{ $cluster['name'] ?? $cid }}</a>
                                    <span class="mcp-status__muted">
                                        {{ (int) ($cluster['keyword_count'] ?? 0) }} {{ __('seo-content-ai::filament.mcp_intelligence.keywords_unit') }}
                                        · {{ (int) ($cluster['article_count'] ?? 0) }} {{ __('seo-content-ai::filament.mcp_intelligence.articles_unit') }}
                                        · {{ $cluster['coverage'] ?? '' }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($report)
                    <div class="mcp-grid-3">
                        <section class="mcp-card">
                            <h2>{{ __('seo-content-ai::filament.mcp_intelligence.highlights') }}</h2>
                            <ul class="mcp-list">
                                @forelse (array_slice($highlights, 0, 8) as $item)
                                    <li>{{ $presenter->highlightText($item) }}</li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                        <section class="mcp-card">
                            <h2>{{ __('seo-content-ai::filament.mcp_intelligence.risks') }}</h2>
                            <ul class="mcp-list">
                                @forelse (array_slice($risks, 0, 8) as $item)
                                    <li>
                                        @if (($item['key'] ?? '') === 'keyword_error')
                                            <a href="{{ $this->keywordsErrorUrl() }}">{{ $presenter->highlightText($item) }}</a>
                                        @elseif (($item['key'] ?? '') === 'unclustered_keywords')
                                            <a href="{{ $this->keywordsUnclusteredUrl() }}">{{ $presenter->highlightText($item) }}</a>
                                        @else
                                            {{ $presenter->highlightText($item) }}
                                        @endif
                                    </li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                        <section class="mcp-card">
                            <h2>{{ __('seo-content-ai::filament.mcp_intelligence.opportunities') }}</h2>
                            <ul class="mcp-list">
                                @forelse (array_slice($opps, 0, 8) as $item)
                                    <li>
                                        @if (($item['key'] ?? '') === 'weak_cluster' && ($item['cluster_id'] ?? '') !== '')
                                            <a href="{{ $this->clusterUrl((string) $item['cluster_id']) }}">{{ $presenter->highlightText($item) }}</a>
                                        @else
                                            {{ $presenter->highlightText($item) }}
                                        @endif
                                    </li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                    </div>

                    <section class="mcp-card">
                        <h2>{{ __('seo-content-ai::filament.mcp_intelligence.actions') }}</h2>
                        <ol class="mcp-actions">
                            @forelse (array_slice($actions, 0, 10) as $item)
                                @php $link = $presenter->actionLink($item); @endphp
                                <li>
                                    <span class="mcp-chip-mod">{{ __('seo-content-ai::filament.mcp_intelligence.module_'.$link['module']) }}</span>
                                    @if ($link['url'])
                                        <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                                    @else
                                        {{ $link['label'] }}
                                    @endif
                                </li>
                            @empty
                                <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                            @endforelse
                        </ol>
                    </section>
                @endif
            @endif

            @if ($this->showFinalizeConfirm)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div class="w-full max-w-md rounded-xl bg-white p-4 dark:bg-gray-900">
                        <p class="text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.finalize_confirm', [
                            'available' => $state['coverage']['available'],
                            'expected' => $state['coverage']['expected'],
                        ]) }}</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" wire:click="$set('showFinalizeConfirm', false)" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.cancel') }}</button>
                            <button type="button" wire:click="confirmFinalize" class="mcp-btn mcp-btn--primary">{{ __('seo-content-ai::filament.mcp_intelligence.finalize') }}</button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($this->showAiContext)
                <div class="fixed inset-0 z-40 flex justify-end bg-black/40" wire:click="closeDrawers">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-4 dark:bg-gray-900" wire:click.stop>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-semibold">{{ __('seo-content-ai::filament.mcp_intelligence.ai_context') }}</h2>
                            <button type="button" wire:click="closeDrawers">✕</button>
                        </div>
                        <div class="mb-3 flex gap-2">
                            <button type="button" wire:click="$set('aiContextView', 'readable')" @class(['mcp-btn', 'mcp-btn--primary' => $this->aiContextView === 'readable'])>{{ __('seo-content-ai::filament.mcp_intelligence.readable') }}</button>
                            <button type="button" wire:click="$set('aiContextView', 'json')" @class(['mcp-btn', 'mcp-btn--primary' => $this->aiContextView === 'json'])>{{ __('seo-content-ai::filament.mcp_intelligence.raw_json') }}</button>
                        </div>
                        @if ($this->aiContextView === 'json')
                            <div class="mb-2 flex justify-end">
                                <button type="button" class="mcp-btn" x-on:click="navigator.clipboard.writeText(@js($state['ai_json']))">{{ __('seo-content-ai::filament.mcp_intelligence.copy') }}</button>
                            </div>
                            <pre class="mcp-json">{{ $state['ai_json'] }}</pre>
                        @else
                            @foreach ($state['ai_readable'] as $section)
                                <section class="mb-4">
                                    <h3 class="text-sm font-semibold">{{ $section['title'] ?? '' }}</h3>
                                    <ul class="mt-1 list-disc pl-5 text-sm">
                                        @foreach (($section['lines'] ?? []) as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif

            @if (in_array($this->previewSource, ['site', 'keywords'], true))
                @php
                    $preview = $this->previewSource === 'site' ? $siteCard : $kwCard;
                    $previewJson = $this->previewSource === 'site' ? $state['site_preview_json'] : $state['keyword_preview_json'];
                    $previewSnap = $this->previewSource === 'site' ? $state['site_snap'] : $state['keyword_snap'];
                    $previewContext = is_array($previewSnap?->context_json) ? $previewSnap->context_json : [];
                    $previewSummary = is_array($previewSnap?->summary_json) ? $previewSnap->summary_json : [];
                @endphp
                <div class="fixed inset-0 z-40 flex justify-end bg-black/40" wire:click="closeDrawers">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-4 dark:bg-gray-900" wire:click.stop>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-semibold">{{ $this->previewSource === 'site' ? __('seo-content-ai::filament.mcp_intelligence.site_intel') : __('seo-content-ai::filament.mcp_intelligence.keyword_intel') }}</h2>
                            <button type="button" wire:click="closeDrawers">✕</button>
                        </div>
                        <h3 class="mcp-sub">{{ __('seo-content-ai::filament.mcp_intelligence.source_summary') }}</h3>
                        <p class="text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.($preview['freshness'] ?? 'missing')) }} @if($preview['relative'] ?? null)· {{ $preview['relative'] }}@endif</p>
                        <h3 class="mcp-sub mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.source_metrics') }}</h3>
                        <div class="text-sm">
                            @foreach (($preview['metrics'] ?? []) as $k => $v)
                                @if (! is_array($v))
                                    <p>{{ $k }}: {{ $v }}</p>
                                @endif
                            @endforeach
                        </div>
                        @if ($previewContext !== [])
                            <h3 class="mcp-sub mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.source_context') }}</h3>
                            <p class="text-sm text-gray-600">{{ implode(' · ', array_keys($previewContext)) }}</p>
                        @endif
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm font-semibold">{{ __('seo-content-ai::filament.mcp_intelligence.technical') }}</summary>
                            <p class="mt-2 text-xs text-gray-500">schema: {{ $preview['schema'] ?? '' }}</p>
                            <p class="text-xs text-gray-500">hash: {{ $preview['hash'] ?? '' }}</p>
                            <p class="text-xs text-gray-500">ISO: {{ $preview['generated_at'] ?? '—' }}</p>
                            <details class="mt-2">
                                <summary class="cursor-pointer text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.raw_json') }}</summary>
                                <pre class="mcp-json mt-2">{{ $previewJson }}</pre>
                            </details>
                        </details>
                    </div>
                </div>
            @endif
        </div>
    </x-filament-panels::page>
</div>
