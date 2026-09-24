@php
    $overview = $this->topicalMapOverview;
    $empty = (bool) ($overview['empty'] ?? true);
    $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
    $tagFacets = is_array($overview['tag_facets'] ?? null) ? $overview['tag_facets'] : [];
    $untaggedCount = (int) ($summary['untagged_count'] ?? 0);
    $aiSnap = $this->aiAuditStatusSnapshot();
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

    <div class="keyword-workspace-shell topical-map-workspace max-w-full">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
            'activeKey' => $this->getActiveKeywordWorkspaceKey(),
            'navItems' => $this->getKeywordWorkspaceNavItems(),
        ])

        <header class="topical-map-toolbar-bar">
            <div class="topical-map-toolbar-bar__title-row">
                <h2 class="topical-map-toolbar-bar__title">
                    {{ __('seo-content-ai::filament.keyword.topical_map_title') }}
                    @if (! empty($aiSnap['site_domain']))
                        <span class="topical-map-toolbar-bar__domain">— {{ $aiSnap['site_domain'] }}</span>
                    @endif
                </h2>
                <div class="topical-map-toolbar-bar__actions">
                    @if (is_array($this->auditResult))
                        <button type="button" class="topical-map-toolbar__btn" wire:click="openAuditOverlay">
                            {{ __('seo-content-ai::filament.keyword.topical_map_open_audit') }}
                        </button>
                    @endif
                    <button
                        type="button"
                        class="topical-map-toolbar__btn topical-map-toolbar__btn--ai"
                        wire:click="beginConfirmAiAudit"
                        wire:loading.attr="disabled"
                        wire:target="confirmRunAiAuditAndTags,beginConfirmAiAudit"
                        @disabled(! $this->canRunAiAuditAndTags() || $this->aiAuditRunning)
                    >
                        <span wire:loading.remove wire:target="confirmRunAiAuditAndTags">
                            {{ $this->aiAuditButtonLabel() }}
                        </span>
                        <span wire:loading wire:target="confirmRunAiAuditAndTags" class="inline-flex items-center gap-2 opacity-70">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            {{ __('seo-content-ai::filament.keyword.ai_audit_tags_running') }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="topical-map-tag-filters" role="group" aria-label="{{ __('seo-content-ai::filament.keyword.topical_map_tags_label') }}">
                <span class="topical-map-tag-filters__label">{{ __('seo-content-ai::filament.keyword.topical_map_tags_label') }}:</span>
                <button
                    type="button"
                    class="topical-map-tag-chip {{ $this->tagFilterAll ? 'is-active' : '' }}"
                    wire:click="toggleTagFilterAll"
                >
                    {{ __('seo-content-ai::filament.keyword.topical_map_tags_all') }}
                </button>
                @foreach ($tagFacets as $facet)
                    @php $fid = (int) ($facet['id'] ?? 0); @endphp
                    <button
                        type="button"
                        class="topical-map-tag-chip {{ (! $this->tagFilterAll && in_array($fid, $this->selectedTagIds, true)) ? 'is-active' : '' }}"
                        wire:click="toggleTagFilter({{ $fid }})"
                    >
                        {{ $facet['name'] ?? '' }} {{ (int) ($facet['topic_count'] ?? 0) }}
                    </button>
                @endforeach
                <button
                    type="button"
                    class="topical-map-tag-chip {{ (! $this->tagFilterAll && $this->tagFilterUntagged) ? 'is-active' : '' }}"
                    wire:click="toggleTagFilterUntagged"
                >
                    {{ __('seo-content-ai::filament.keyword.topical_map_tags_untagged') }} {{ $untaggedCount }}
                </button>
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
        </header>

        <div class="topical-map-layout">
            <div class="topical-map-chart-shell" wire:ignore>
                @if ($empty && (int) ($summary['topic_count'] ?? 0) === 0)
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
                        data-open-topic="openTopicDetail"
                    ></div>
                    <p class="topical-map-meta" data-topical-map-meta hidden></p>
                @endif
            </div>

            @if ($this->showAuditOverlay && is_array($this->auditResult))
                <div class="topical-map-audit-overlay" role="dialog" aria-modal="true">
                    <div class="topical-map-audit-overlay__panel" data-topical-map-audit>
                        <div class="topical-map-audit-overlay__head">
                            <h3>{{ __('seo-content-ai::filament.keyword.topical_map_open_audit') }}</h3>
                            <button type="button" class="topical-map-toolbar__btn" wire:click="closeAuditOverlay">
                                {{ __('seo-content-ai::filament.keyword.topical_map_close_audit') }}
                            </button>
                        </div>
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
                                        @if ($topicRef !== '')
                                            <button type="button" class="topical-map-audit__focus" wire:click="focusTopicFromRef('{{ $topicRef }}')">
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
                                        @if ($topicRef !== '')
                                            <button type="button" class="topical-map-audit__focus" wire:click="focusTopicFromRef('{{ $topicRef }}')">
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
                                    @php $topicRef = is_array($action) ? trim((string) ($action['topic_ref'] ?? '')) : ''; @endphp
                                    <li class="topical-map-audit__item">
                                        @if (is_array($action))
                                            <strong class="topical-map-audit__title">{{ $action['title'] ?? '' }}</strong>
                                            @if ($topicRef !== '')
                                                <button type="button" class="topical-map-audit__focus" wire:click="focusTopicFromRef('{{ $topicRef }}')">
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
                </div>
            @elseif ($this->auditError !== '')
                <div class="topical-map-audit-overlay topical-map-audit-overlay--error">
                    <div class="topical-map-audit-overlay__panel">
                        <p class="topical-map-audit--error">{{ $this->auditError }}</p>
                        <button type="button" class="topical-map-toolbar__btn" wire:click="$set('auditError', '')">
                            {{ __('seo-content-ai::filament.keyword.topical_map_close_audit') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($this->confirmAiAudit)
        @php $snap = $this->aiAuditStatusSnapshot(); @endphp
        <div class="topic-ai-audit-modal" role="dialog" aria-modal="true">
            <div class="topic-ai-audit-modal__backdrop" wire:click="cancelConfirmAiAudit"></div>
            <div class="topic-ai-audit-modal__panel">
                <h3 class="topic-ai-audit-modal__title">{{ __('seo-content-ai::filament.keyword.ai_audit_tags_modal_title') }}</h3>
                <p class="topic-ai-audit-modal__site">
                    {{ __('seo-content-ai::filament.keyword.ai_audit_tags_site') }}:
                    <strong>{{ $snap['site_domain'] !== '' ? $snap['site_domain'] : ('#'.$snap['site_id']) }}</strong>
                </p>
                <ul class="topic-ai-audit-modal__stats">
                    <li>{{ number_format((int) $snap['topic_count']) }} Topics</li>
                    <li>{{ number_format((int) $snap['assigned_keywords']) }} {{ __('seo-content-ai::filament.keyword.ai_audit_tags_assigned_kw') }}</li>
                    <li>{{ number_format((int) $snap['unassigned_keywords']) }} {{ __('seo-content-ai::filament.keyword.ai_audit_tags_unassigned_kw') }}</li>
                    <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_existing') }}: {{ number_format((int) $snap['existing_tags']) }}</li>
                    <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_topics_tagged') }}: {{ number_format((int) $snap['topics_with_tags']) }} / {{ number_format((int) $snap['topic_count']) }}</li>
                    <li>{{ __('seo-content-ai::filament.keyword.ai_audit_tags_untagged') }}: {{ number_format((int) $snap['untagged_topics']) }}</li>
                </ul>
                <p class="topic-ai-audit-modal__notice">{{ __('seo-content-ai::filament.keyword.ai_audit_tags_cost_notice') }}</p>
                <div class="topic-ai-audit-modal__actions">
                    <x-filament::button type="button" size="sm" color="gray" wire:click="cancelConfirmAiAudit">
                        {{ __('seo-content-ai::filament.keyword.topic_recluster_cancel') }}
                    </x-filament::button>
                    <x-filament::button type="button" size="sm" color="primary" wire:click="confirmRunAiAuditAndTags">
                        {{ __('seo-content-ai::filament.keyword.ai_audit_tags_run') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    @vite(['addons/search-intelligence/resources/js/topical-map-chart.js'])
</x-filament-panels::page>
