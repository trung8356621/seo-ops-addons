@php
    $payload = $this->relationshipPayload;
    $ok = (bool) ($payload['ok'] ?? false);
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $graph = is_array($payload['graph'] ?? null) ? $payload['graph'] : [];
    $keyword = is_array($data['keyword'] ?? null) ? $data['keyword'] : [];
    $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    $trunc = is_array($graph['truncations'] ?? null) ? $graph['truncations'] : [];
    $relatedTrunc = is_array($trunc['related_keywords'] ?? null) ? $trunc['related_keywords'] : [];
    $inboundTrunc = is_array($trunc['internal_links']['inbound'] ?? null) ? $trunc['internal_links']['inbound'] : [];
    $outboundTrunc = is_array($trunc['internal_links']['outbound'] ?? null) ? $trunc['internal_links']['outbound'] : [];
    $gscTrunc = is_array($trunc['gsc'] ?? null) ? $trunc['gsc'] : [];
    $workspaceCss = base_path('addons/seo/resources/css/keyword-workspace.css');
    $chartCss = base_path('addons/search-intelligence/resources/css/keyword-relationship.css');
    $filters = $this->categoryFilters;
@endphp

<x-filament-panels::page class="keyword-workspace-page keyword-relationship-page max-w-full">
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

        <header class="topic-index-section-heading keyword-relationship-header">
            <div class="keyword-relationship-header__row">
                <div>
                    <h2 class="topic-index-section-heading__title">
                        {{ __('seo-content-ai::filament.keyword.relationship_title') }}
                    </h2>
                    <p class="topic-index-section-heading__subtitle">
                        {{ __('seo-content-ai::filament.keyword.relationship_subtitle') }}
                    </p>
                    @if ($ok)
                        <p class="topic-index-compact-stats">
                            <span>{{ $keyword['phrase'] ?? '' }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $keyword['ref'] ?? ('keyword:'.$this->keyword) }}</span>
                        </p>
                    @endif
                </div>
                <div class="keyword-relationship-header__actions">
                    <a class="cp-plan-btn" href="{{ $this->dictionaryUrl() }}">
                        {{ __('seo-content-ai::filament.keyword.relationship_open_dictionary') }}
                    </a>
                </div>
            </div>

            <div class="keyword-relationship-toolbar" role="group" aria-label="{{ __('seo-content-ai::filament.keyword.relationship_filters') }}">
                @foreach ([
                    'topic' => 'relationship_filter_topic',
                    'article' => 'relationship_filter_article',
                    'dna' => 'relationship_filter_dna',
                    'related_keyword' => 'relationship_filter_related',
                    'gsc' => 'relationship_filter_gsc',
                    'internal_link' => 'relationship_filter_links',
                    'planning' => 'relationship_filter_planning',
                ] as $key => $labelKey)
                    <button
                        type="button"
                        class="keyword-relationship-toolbar__btn {{ ! empty($filters[$key]) ? 'is-active' : '' }}"
                        wire:click="toggleCategory('{{ $key }}')"
                        wire:loading.attr="disabled"
                        wire:target="toggleCategory"
                    >
                        {{ __('seo-content-ai::filament.keyword.'.$labelKey) }}
                    </button>
                @endforeach
            </div>

            @if ($ok)
                <div class="keyword-relationship-truncation" data-keyword-relationship-truncation>
                    @if (! empty($relatedTrunc['truncated']))
                        <span>{{ __('seo-content-ai::filament.keyword.relationship_trunc_related', [
                            'returned' => (int) ($relatedTrunc['returned'] ?? 0),
                            'total' => (int) ($relatedTrunc['total'] ?? 0),
                        ]) }}</span>
                    @endif
                    @if (! empty($inboundTrunc['truncated']))
                        <span>{{ __('seo-content-ai::filament.keyword.relationship_trunc_inbound', [
                            'returned' => (int) ($inboundTrunc['returned'] ?? 0),
                            'total' => (int) ($inboundTrunc['total'] ?? 0),
                        ]) }}</span>
                    @endif
                    @if (! empty($outboundTrunc['truncated']))
                        <span>{{ __('seo-content-ai::filament.keyword.relationship_trunc_outbound', [
                            'returned' => (int) ($outboundTrunc['returned'] ?? 0),
                            'total' => (int) ($outboundTrunc['total'] ?? 0),
                        ]) }}</span>
                    @endif
                    @if (! empty($gscTrunc['truncated']))
                        <span>{{ __('seo-content-ai::filament.keyword.relationship_trunc_gsc', [
                            'returned' => (int) ($gscTrunc['returned'] ?? 0),
                            'total' => (int) ($gscTrunc['total'] ?? 0),
                        ]) }}</span>
                    @endif
                </div>

                @if (! empty($meta['relation_issues']))
                    <p class="keyword-relationship-issues">
                        {{ __('seo-content-ai::filament.keyword.relationship_issues') }}:
                        {{ implode(', ', (array) $meta['relation_issues']) }}
                    </p>
                @endif
            @endif
        </header>

        <div class="keyword-relationship-layout">
            <div class="keyword-relationship-chart-shell">
                @if (! $ok)
                    <div class="keyword-relationship-empty">
                        {{ __('seo-content-ai::filament.keyword.relationship_empty') }}
                    </div>
                @else
                    <div
                        id="keyword-relationship-chart"
                        class="keyword-relationship-chart"
                        wire:key="kw-rel-chart-{{ $this->keyword }}-{{ md5(json_encode($this->categoryFilters)) }}"
                        data-keyword-relationship-root="1"
                        data-graph='@json($graph)'
                    ></div>
                @endif
            </div>

            <aside class="keyword-relationship-side" aria-label="{{ __('seo-content-ai::filament.keyword.relationship_side_panel') }}">
                <h3 class="keyword-relationship-side__title">{{ __('seo-content-ai::filament.keyword.relationship_side_panel') }}</h3>
                <div class="keyword-relationship-side__body" data-keyword-relationship-side>
                    <p class="keyword-relationship-side__placeholder">
                        {{ __('seo-content-ai::filament.keyword.relationship_side_placeholder') }}
                    </p>
                </div>
            </aside>
        </div>
    </div>

    @vite(['addons/search-intelligence/resources/js/keyword-relationship-chart.js'])
</x-filament-panels::page>
