@php
    $overview = $this->topicalMapOverview;
    $empty = (bool) ($overview['empty'] ?? true);
    $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $chartCss = base_path('addons/search-intelligence/resources/css/topical-map.css');
@endphp

<x-filament-panels::page class="keyword-workspace-page topical-map-page max-w-full">
    <x-seo-content-ai::content-project-ops-styles />
    @if (is_readable($workspaceCss))
        <style>{!! file_get_contents($workspaceCss) !!}</style>
    @endif
    @if (is_readable($chartCss))
        <style>{!! file_get_contents($chartCss) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-4">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <header class="topic-index-section-heading topical-map-header">
            <div class="topical-map-header__row">
                <div>
                    <h2 class="topic-index-section-heading__title">
                        {{ __('seo-content-ai::filament.keyword.topical_map_title') }}
                    </h2>
                    <p class="topic-index-section-heading__subtitle">
                        {{ __('seo-content-ai::filament.keyword.topical_map_subtitle') }}
                    </p>
                </div>
                <div class="topical-map-header__actions">
                    <button
                        type="button"
                        class="cp-plan-btn"
                        wire:click="runTopicalMapAudit"
                        wire:loading.attr="disabled"
                        wire:target="runTopicalMapAudit"
                        @disabled($empty)
                        title="{{ $empty ? __('seo-content-ai::filament.keyword.topical_map_audit_empty') : '' }}"
                    >
                        <span wire:loading.remove wire:target="runTopicalMapAudit">
                            {{ __('seo-content-ai::filament.keyword.topical_map_audit') }}
                        </span>
                        <span wire:loading wire:target="runTopicalMapAudit" class="inline-flex items-center gap-2 opacity-70">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            {{ __('seo-content-ai::filament.keyword.topical_map_audit') }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="topical-map-toolbar" role="tablist" aria-label="Topical Map renderer">
                @foreach (['tree' => 'topical_map_mode_tree', 'network' => 'topical_map_mode_network', 'sunburst' => 'topical_map_mode_sunburst'] as $mode => $labelKey)
                    <button
                        type="button"
                        role="tab"
                        class="topical-map-toolbar__btn {{ $this->mapRenderer === $mode ? 'is-active' : '' }}"
                        wire:click="setMapRenderer('{{ $mode }}')"
                        aria-selected="{{ $this->mapRenderer === $mode ? 'true' : 'false' }}"
                        data-topical-map-renderer="{{ $mode }}"
                    >
                        {{ __('seo-content-ai::filament.keyword.'.$labelKey) }}
                    </button>
                @endforeach
            </div>

            @if (! $empty)
                <p class="topic-index-compact-stats">
                    <span>{{ number_format((int) ($summary['topic_count'] ?? 0)) }} Topics</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ number_format((int) ($summary['total_keywords'] ?? 0)) }} Keywords</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ number_format((int) ($summary['total_articles'] ?? 0)) }} Articles</span>
                    @if (! empty($summary['source_updated_at']))
                        <span aria-hidden="true">·</span>
                        <span>{{ $summary['source_updated_at'] }}</span>
                    @endif
                </p>
            @endif
        </header>

        <div class="topical-map-layout">
            <div class="topical-map-chart-shell" wire:ignore>
                @if ($empty)
                    <div class="topical-map-empty" data-topical-map-empty="1">
                        {{ __('seo-content-ai::filament.keyword.topical_map_empty') }}
                    </div>
                @else
                    <div
                        id="topical-map-chart"
                        class="topical-map-chart"
                        data-topical-map-root="1"
                        data-renderer="{{ $this->mapRenderer }}"
                        data-overview='@json($overview)'
                        data-load-children="loadTopicChildren"
                        data-load-network="loadNetworkNeighborhood"
                        data-focus-topic="focusTopic"
                    ></div>
                    <p class="topical-map-meta" data-topical-map-meta hidden></p>
                @endif
            </div>

            <aside class="topical-map-side" aria-label="{{ __('seo-content-ai::filament.keyword.topical_map_side_panel') }}">
                <h3 class="topical-map-side__title">{{ __('seo-content-ai::filament.keyword.topical_map_side_panel') }}</h3>
                <div class="topical-map-side__body" data-topical-map-side>
                    <p class="topical-map-side__placeholder">Select a Topic or Keyword node.</p>
                </div>
                @if ($this->focusedTopicId)
                    <a
                        class="topical-map-side__link"
                        href="{{ $this->topicDetailUrl((int) $this->focusedTopicId) }}"
                    >
                        {{ __('seo-content-ai::filament.keyword.topical_map_open_topic') }}
                    </a>
                @endif

                @if (is_array($this->auditResult))
                    <div class="topical-map-audit" data-topical-map-audit>
                        <h4>{{ __('seo-content-ai::filament.keyword.topical_map_audit_summary') }}</h4>
                        <p class="topical-map-audit__summary">{{ $this->auditResult['summary'] ?? '' }}</p>

                        @if (! empty($this->auditResult['findings']))
                            <h4>{{ __('seo-content-ai::filament.keyword.topical_map_audit_findings') }}</h4>
                            <ul class="topical-map-audit__list">
                                @foreach ($this->auditResult['findings'] as $finding)
                                    @php
                                        $severity = strtolower((string) ($finding['severity'] ?? 'medium'));
                                        $topicRef = trim((string) ($finding['topic_ref'] ?? ''));
                                    @endphp
                                    <li class="topical-map-audit__item">
                                        <div class="topical-map-audit__item-head">
                                            <span class="topical-map-audit__badge topical-map-audit__badge--{{ $severity }}">{{ $severity }}</span>
                                            <span class="topical-map-audit__type">{{ $finding['type'] ?? '' }}</span>
                                        </div>
                                        <strong class="topical-map-audit__title">{{ $finding['title'] ?? '' }}</strong>
                                        @if (! empty($finding['observation']))
                                            <div class="topical-map-audit__body">{{ $finding['observation'] }}</div>
                                        @endif
                                        @if (! empty($finding['evidence']) && is_array($finding['evidence']))
                                            <ul class="topical-map-audit__evidence">
                                                @foreach ($finding['evidence'] as $ev)
                                                    <li>{{ $ev }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($topicRef !== '')
                                            <button
                                                type="button"
                                                class="topical-map-audit__focus"
                                                wire:click="focusTopicFromRef('{{ $topicRef }}')"
                                            >
                                                {{ $finding['topic_name'] ?? $topicRef }}
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($this->auditResult['opportunities']))
                            <h4>{{ __('seo-content-ai::filament.keyword.topical_map_audit_opportunities') }}</h4>
                            <ul class="topical-map-audit__list">
                                @foreach ($this->auditResult['opportunities'] as $opp)
                                    @php $topicRef = trim((string) ($opp['topic_ref'] ?? '')); @endphp
                                    <li class="topical-map-audit__item">
                                        <strong class="topical-map-audit__title">{{ $opp['title'] ?? $opp['topic_name'] ?? '' }}</strong>
                                        @if (! empty($opp['reason']))
                                            <div class="topical-map-audit__body">{{ $opp['reason'] }}</div>
                                        @endif
                                        @if (! empty($opp['suggested_direction']))
                                            <div class="topical-map-audit__body">{{ $opp['suggested_direction'] }}</div>
                                        @endif
                                        @if ($topicRef !== '')
                                            <button
                                                type="button"
                                                class="topical-map-audit__focus"
                                                wire:click="focusTopicFromRef('{{ $topicRef }}')"
                                            >
                                                {{ $opp['topic_name'] ?? $topicRef }}
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($this->auditResult['recommended_actions']))
                            <h4>{{ __('seo-content-ai::filament.keyword.topical_map_audit_actions') }}</h4>
                            <ol class="topical-map-audit__list topical-map-audit__list--actions">
                                @foreach ($this->auditResult['recommended_actions'] as $action)
                                    @php
                                        $topicRef = is_array($action) ? trim((string) ($action['topic_ref'] ?? '')) : '';
                                    @endphp
                                    <li class="topical-map-audit__item">
                                        @if (is_array($action))
                                            <div class="topical-map-audit__item-head">
                                                <span class="topical-map-audit__type">#{{ $action['priority'] ?? '' }} · {{ $action['action_type'] ?? '' }}</span>
                                            </div>
                                            <strong class="topical-map-audit__title">{{ $action['title'] ?? '' }}</strong>
                                            @if (! empty($action['reason']))
                                                <div class="topical-map-audit__body">{{ $action['reason'] }}</div>
                                            @endif
                                            @if ($topicRef !== '')
                                                <button
                                                    type="button"
                                                    class="topical-map-audit__focus"
                                                    wire:click="focusTopicFromRef('{{ $topicRef }}')"
                                                >
                                                    {{ $topicRef }}
                                                </button>
                                            @endif
                                        @else
                                            {{ $action }}
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @elseif ($this->auditError !== '')
                    <div class="topical-map-audit topical-map-audit--error">{{ $this->auditError }}</div>
                @endif
            </aside>
        </div>
    </div>

    @vite(['addons/search-intelligence/resources/js/topical-map-chart.js'])
</x-filament-panels::page>
